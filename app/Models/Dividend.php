<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\CopyToBaseCurrency;
use App\Casts\BaseCurrency;
use App\Interfaces\MarketData\MarketDataInterface;
use App\Traits\HasMarketData;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Pipeline;
use Illuminate\Support\Str;

class Dividend extends Model
{
    use HasFactory;
    use HasMarketData;
    use HasUuids;

    public const SOURCE_SYNC = 'sync';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'symbol',
        'date',
        'dividend_amount',
        'source',
    ];

    protected $attributes = [
        'source' => self::SOURCE_SYNC,
    ];

    protected $hidden = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'last_dividend_update' => 'date',
        'dividend_amount' => 'float',
        'dividend_amount_base' => BaseCurrency::class,
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($dividend) {

            $dividend = Pipeline::send($dividend)
                ->through([
                    CopyToBaseCurrency::class,
                ])
                ->then(fn (Dividend $dividend) => $dividend);
        });
    }

    /**
     * Symbols are stored uppercase so they match holdings created from transactions.
     */
    protected function symbol(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtoupper($value)
        );
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(Holding::class, 'symbol', 'symbol');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'symbol', 'symbol');
    }

    public function scopeSymbol($query, $symbol)
    {
        return $query->where('dividends.symbol', $symbol);
    }

    /**
     * Grab new dividend data.
     *
     * Provider rows are inserted only. Existing rows are not updated or deleted.
     * Hand-entered dividends (source = manual) and dates the user removed or
     * moved away from are skipped, including when this runs with --force.
     */
    public static function refreshDividendData(string $symbol): void
    {
        $dividends_meta = self::query()
            ->where('symbol', $symbol)
            ->where('source', self::SOURCE_SYNC)
            ->selectRaw('COUNT(symbol) as total_dividends')
            ->selectRaw('MAX(created_at) as last_dividend_update')
            ->first();

        // assume we need to populate ALL dividend data
        $start_date = new Carbon('@0');
        $end_date = now();

        // nope, refresh forward looking only — manual rows must not move this cursor
        if ($dividends_meta->total_dividends) {

            $start_date = $dividends_meta->last_dividend_update;
        }

        // skip refresh if there's already recent data
        if ($start_date->greaterThan($end_date)) {

            return;
        }

        $dividend_data = app(MarketDataInterface::class)->dividends($symbol, $start_date, $end_date);

        if ($dividend_data->isEmpty()) {

            return;
        }

        $skipDates = self::providerSkipDates($symbol);

        $freshDividends = $dividend_data
            ->reject(function ($dividend) use ($skipDates) {
                return isset($skipDates[Carbon::parse($dividend['date'])->toDateString()]);
            })
            ->values();

        if ($freshDividends->isNotEmpty()) {

            $market_data = MarketData::getMarketData($symbol);

            $freshDividends
                ->chunk(10)
                ->each(function ($chunk) use ($market_data) {

                    // get historic conversion rates
                    $rate_to_base = CurrencyRate::timeSeriesRates($market_data->currency, $chunk->min('date'), $chunk->max('date'));

                    // create mass insert
                    foreach ($chunk as $index => $dividend) {
                        $rate_to_base_date = 1 / Arr::get($rate_to_base, Carbon::parse(Arr::get($dividend, 'date'))->toDateString(), 1);

                        $dividend['dividend_amount_base'] = $dividend['dividend_amount'] * $rate_to_base_date;

                        $chunk[$index] = [...$dividend, ...[
                            'id' => Str::uuid()->toString(),
                            'source' => self::SOURCE_SYNC,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]];
                    }

                    // insert records; conflicts on (date, symbol) are ignored
                    (new self)->insertOrIgnore($chunk->toArray());
                });

            // re-invest only the rows this run actually added
            self::reinvestDividends($freshDividends, $market_data);
        }

        self::syncDerivedData($symbol);
    }

    /**
     * Calendar dates the provider must not insert for this symbol.
     *
     * Every stored dividend is left as-is, so a hand-edited amount cannot be
     * overwritten. Exclusions keep a deleted or moved date from coming back.
     *
     * @return array<string, true>
     */
    public static function providerSkipDates(string $symbol): array
    {
        $dates = self::query()
            ->where('symbol', $symbol)
            ->pluck('date')
            ->merge(
                DividendSyncExclusion::query()->where('symbol', $symbol)->pluck('date')
            );

        $skip = [];

        foreach ($dates as $date) {
            $skip[Carbon::parse($date)->toDateString()] = true;
        }

        return $skip;
    }

    /**
     * Recalculate stored dividend totals and the caches derived from them.
     */
    public static function syncDerivedData(string $symbol): void
    {
        self::syncHoldings($symbol);
        self::syncMarketDividendSnapshot($symbol);
        self::forgetPortfolioMetricsCache($symbol);
    }

    /**
     * Point market data at the latest stored dividend, including hand-entered rows.
     */
    public static function syncMarketDividendSnapshot(string $symbol): void
    {
        $marketData = MarketData::query()->where('symbol', $symbol)->first();

        if ($marketData === null) {
            return;
        }

        $latest = self::query()->where('symbol', $symbol)->orderByDesc('date')->first();

        $marketData->last_dividend_amount = $latest?->dividend_amount;
        $marketData->last_dividend_date = $latest?->date;
        $marketData->save();
    }

    /**
     * Drop cached portfolio metrics that include this symbol's dividends.
     */
    public static function forgetPortfolioMetricsCache(string $symbol): void
    {
        Holding::query()
            ->where('symbol', $symbol)
            ->pluck('portfolio_id')
            ->unique()
            ->each(function ($portfolioId) {
                cache()->forget('portfolio-metrics-'.$portfolioId);

                DB::table('portfolio_user')
                    ->where('portfolio_id', $portfolioId)
                    ->pluck('user_id')
                    ->each(function ($userId) use ($portfolioId) {
                        cache()->tags(['metrics-'.$userId])->forget('portfolio-metrics-'.$portfolioId);
                    });
            });
    }

    /**
     * Remove synthetic reinvestment buys that were created for a dividend date.
     */
    public static function deleteReinvestedTransactions(string $symbol, string $date): void
    {
        Transaction::query()
            ->where('symbol', $symbol)
            ->where('reinvested_dividend', true)
            ->whereDate('date', $date)
            ->get()
            ->each(fn (Transaction $transaction) => $transaction->delete());
    }

    /**
     * Keep an existing reinvestment buy in line with a corrected per-share amount.
     * Does not create a reinvestment buy when one does not already exist.
     */
    public static function refreshReinvestedTransactions(self $dividend): void
    {
        $marketData = MarketData::query()->where('symbol', $dividend->symbol)->first();

        if ($marketData === null || $marketData->market_value <= 0) {
            return;
        }

        Holding::query()
            ->where('symbol', $dividend->symbol)
            ->where('reinvest_dividends', true)
            ->get()
            ->each(function (Holding $holding) use ($dividend, $marketData) {
                $transaction = Transaction::query()
                    ->where('portfolio_id', $holding->portfolio_id)
                    ->where('symbol', $holding->symbol)
                    ->where('reinvested_dividend', true)
                    ->whereDate('date', $dividend->date->toDateString())
                    ->first();

                if ($transaction === null) {
                    return;
                }

                $holding->unsetRelation('transactions');
                $owned = $holding->qtyOwned($dividend->date) - $transaction->quantity;

                if ($owned <= 0) {
                    $transaction->delete();

                    return;
                }

                $transaction->quantity = ($dividend->dividend_amount * $owned) / $marketData->market_value;
                $transaction->save();
            });
    }

    public static function syncHoldings(string $symbol): void
    {
        // group by holdings
        $subQuery = self::select([
            'holdings.portfolio_id',
            'dividends.date',
            'dividends.symbol',
            'dividends.dividend_amount',
        ])->selectRaw("
            (COALESCE(SUM(CASE WHEN transactions.transaction_type = 'BUY'
                AND date(transactions.date) <= date(dividends.date)
                THEN transactions.quantity ELSE 0 END), 0)
            - COALESCE(SUM(CASE WHEN transactions.transaction_type = 'SELL'
                AND date(transactions.date) <= date(dividends.date)
                THEN transactions.quantity ELSE 0 END), 0))
            * dividends.dividend_amount
            AS total_received
        ")->join('transactions', 'transactions.symbol', '=', 'dividends.symbol')
            ->join('holdings', function ($join) {
                $join->on('transactions.portfolio_id', '=', 'holdings.portfolio_id')
                    ->on('holdings.symbol', '=', 'dividends.symbol');
            })
            ->where('dividends.symbol', $symbol)
            ->groupBy('holdings.portfolio_id', 'dividends.date', 'dividends.symbol', 'dividends.dividend_amount', 'dividends.dividend_amount_base');

        $dividends = DB::table(DB::raw("({$subQuery->toSql()}) as sub"))
            ->mergeBindings($subQuery->getQuery())
            ->where('total_received', '>', 0)
            ->get();

        // iterate through holdings and update
        Holding::where(['symbol' => $symbol])
            ->get()
            ->each(function ($holding) use ($dividends) {
                $holding->update([
                    'dividends_earned' => $dividends->where('portfolio_id', $holding->portfolio_id)
                        ->sum('total_received'),
                ]);
            });
    }

    public static function reinvestDividends(iterable $dividend_data, MarketData $market_data): void
    {
        // re-invest dividends
        Holding::where([
            'symbol' => $market_data->symbol,
            'reinvest_dividends' => true,
        ])
            ->get()
            ->each(function ($holding) use ($dividend_data, $market_data) {

                foreach ($dividend_data as $dividend) {

                    Transaction::create([
                        'date' => $dividend['date'],
                        'portfolio_id' => $holding->portfolio_id,
                        'symbol' => $holding->symbol,
                        'currency' => $holding->market_data->currency,
                        'transaction_type' => 'BUY',
                        'reinvested_dividend' => true,
                        'cost_basis' => 0,
                        'quantity' => ($dividend['dividend_amount'] * $holding->qtyOwned(Carbon::parse($dividend['date']))) / $market_data->market_value,
                    ]);
                }
            });
    }
}
