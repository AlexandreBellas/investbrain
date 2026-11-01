<?php

declare(strict_types=1);

namespace Tests;

use App\Interfaces\MarketData\FakeMarketData;
use App\Interfaces\MarketData\Types\Dividend as DividendData;
use App\Models\Dividend;
use App\Models\Holding;
use App\Models\MarketData;
use App\Models\Portfolio;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;

class DividendsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_dividends_update_holding(): void
    {
        $this->actingAs($user = User::factory()->create());

        $portfolio = Portfolio::factory()->create();
        Transaction::factory()->buy()->yearsAgo()->portfolio($portfolio->id)->symbol('ACME')->create();

        $holding = Holding::query()->portfolio($portfolio->id)->symbol('ACME')->first();

        $this->assertEquals(0, $holding->dividends_earned);

        Dividend::refreshDividendData('ACME');

        $holding->refresh();

        $this->assertEquals(4.95, $holding->dividends_earned);
    }

    public function test_new_dividends_are_reinvested(): void
    {
        $this->actingAs($user = User::factory()->create());

        $portfolio = Portfolio::factory()->create();
        Transaction::factory()->buy()->yearsAgo()->portfolio($portfolio->id)->symbol('ACME')->create();

        $holding = Holding::query()->portfolio($portfolio->id)->symbol('ACME')->first();
        $holding->reinvest_dividends = true;
        $holding->save();

        $this->assertEquals(0, $holding->dividends_earned);

        Dividend::refreshDividendData('ACME');

        $transactions = Transaction::where(['reinvested_dividend' => true])->symbol('ACME')->portfolio($portfolio->id)->get();

        $market_data = MarketData::symbol('ACME')->first();
        $dividendsReinvested = $transactions->sum('quantity');

        $this->assertCount(3, $transactions);
        $this->assertEqualsWithDelta(4.95, $dividendsReinvested * $market_data->market_value, 0.01);
    }

    public function test_cannot_insert_duplicate_dividends(): void
    {
        // first insert
        Dividend::refreshDividendData('ACME');

        // try to duplicate
        Dividend::refreshDividendData('ACME');

        $dividend_count = Dividend::count();

        $this->assertEquals(3, $dividend_count);
    }

    public function test_dividend_earnings_are_not_shared_between_portfolios(): void
    {
        $this->actingAs($user = User::factory()->create());

        $portfolioOne = Portfolio::factory()->create();
        Transaction::factory()->buy()->yearsAgo()->portfolio($portfolioOne->id)->symbol('ACME')->create();

        $portfolioTwo = Portfolio::factory()->create();
        Transaction::factory(2)->buy()->sixMonthsAgo()->portfolio($portfolioTwo->id)->symbol('ACME')->create();

        Dividend::refreshDividendData('ACME');

        $holdingOne = Holding::query()->portfolio($portfolioOne->id)->symbol('ACME')->first();
        $holdingTwo = Holding::query()->portfolio($portfolioTwo->id)->symbol('ACME')->first();

        $this->assertEquals(4.95, $holdingOne->dividends_earned);
        $this->assertEquals(8, $holdingTwo->dividends_earned);
    }

    public function test_dividend_earnings_not_shared_in_same_portfolio_with_multiple_symbols(): void
    {
        $this->actingAs($user = User::factory()->create());

        $portfolio = Portfolio::factory()->create();
        Transaction::factory()->buy()->yearsAgo()->portfolio($portfolio->id)->symbol('ACME')->create();
        Transaction::factory()->buy()->yearsAgo()->portfolio($portfolio->id)->symbol('GOOG')->create();

        Dividend::refreshDividendData('ACME');

        $acmeHolding = Holding::query()->portfolio($portfolio->id)->symbol('ACME')->first();
        $googHolding = Holding::query()->portfolio($portfolio->id)->symbol('GOOG')->first();

        $this->assertEquals(4.95, $acmeHolding->dividends_earned);

        $this->assertEquals(0, $googHolding->dividends_earned);
    }

    public function test_dividends_are_calculated_for_symbols_containing_quotes(): void
    {
        $this->actingAs($user = User::factory()->create());

        $symbol = "AC'ME";

        $portfolio = Portfolio::factory()->create();
        Transaction::factory()->buy()->yearsAgo()->portfolio($portfolio->id)->symbol($symbol)->create();

        Dividend::refreshDividendData($symbol);

        $holding = Holding::query()->portfolio($portfolio->id)->symbol($symbol)->first();

        $this->assertEquals(4.95, $holding->dividends_earned);
    }

    public function test_second_refresh_does_not_duplicate_reinvested_dividends(): void
    {
        $this->actingAs(User::factory()->create());

        $portfolio = Portfolio::factory()->create();
        Transaction::factory()->buy()->yearsAgo()->portfolio($portfolio->id)->symbol('ACME')->create();

        $holding = Holding::query()->portfolio($portfolio->id)->symbol('ACME')->first();
        $holding->reinvest_dividends = true;
        $holding->save();

        Dividend::refreshDividendData('ACME');
        Dividend::refreshDividendData('ACME');

        $this->assertSame(3, Transaction::query()->where('reinvested_dividend', true)->count());
        $this->assertSame(3, Dividend::query()->count());
    }

    public function test_refresh_cursor_ignores_manual_dividends_and_keeps_their_amounts(): void
    {
        $this->freezeTime();
        $this->actingAs(User::factory()->create());

        $portfolio = Portfolio::factory()->create();
        Transaction::factory()->buy()->date('2020-01-10')->portfolio($portfolio->id)->symbol('ACME')->create();

        $synced = Dividend::factory()->create([
            'symbol' => 'ACME',
            'date' => '2020-06-01',
            'dividend_amount' => 1,
            'source' => Dividend::SOURCE_SYNC,
        ]);
        $synced->created_at = now()->subDays(10);
        $synced->saveQuietly();

        $manual = Dividend::factory()->manual()->create([
            'symbol' => 'ACME',
            'date' => '2021-02-01',
            'dividend_amount' => 4.5,
        ]);

        $starts = [];
        $fake = Mockery::mock(FakeMarketData::class)->makePartial();
        $fake->shouldReceive('dividends')->twice()->withArgs(function (string $symbol, mixed $start) use (&$starts): bool {
            $starts[] = Carbon::parse($start)->toDateString();

            return $symbol === 'ACME';
        })->andReturn(collect([
            new DividendData([
                'symbol' => 'ACME',
                'date' => '2024-03-01',
                'dividend_amount' => 0.4,
            ]),
            new DividendData([
                'symbol' => 'ACME',
                'date' => '2021-02-01',
                'dividend_amount' => 99,
            ]),
        ]));
        $this->app->instance(FakeMarketData::class, $fake);

        $this->artisan('refresh:dividend-data', ['--force' => true])->assertSuccessful();
        $this->artisan('refresh:dividend-data')->assertSuccessful();

        $this->assertSame(now()->subDays(10)->toDateString(), $starts[0]);
        $this->assertSame(4.5, $manual->fresh()->dividend_amount);
        $this->assertSame(Dividend::SOURCE_MANUAL, $manual->fresh()->source);
        $this->assertEqualsWithDelta(0.4, Dividend::query()->whereDate('date', '2024-03-01')->first()->dividend_amount, 0.0000001);
        $this->assertNull(Dividend::query()->where('dividend_amount', 99)->first());
    }

    public function test_force_refresh_includes_closed_holdings_without_replacing_manual_dividends(): void
    {
        $this->actingAs(User::factory()->create());

        $portfolio = Portfolio::factory()->create();
        Transaction::factory()->buy()->date('2020-01-10')->portfolio($portfolio->id)->symbol('ACME')->create();
        Transaction::factory()->sell()->date('2020-06-01')->portfolio($portfolio->id)->symbol('ACME')->create();

        $manual = Dividend::factory()->manual()->create([
            'symbol' => 'ACME',
            'date' => '2019-01-01',
            'dividend_amount' => 3.25,
        ]);

        $this->artisan('refresh:dividend-data')->assertSuccessful();

        $this->assertSame(1, Dividend::query()->count());

        $this->artisan('refresh:dividend-data', ['--force' => true])->assertSuccessful();

        $manual->refresh();

        $this->assertSame(4, Dividend::query()->count());
        $this->assertSame(3.25, $manual->dividend_amount);
        $this->assertSame(Dividend::SOURCE_MANUAL, $manual->source);
        $this->assertSame('2019-01-01', $manual->date->toDateString());
    }
}
