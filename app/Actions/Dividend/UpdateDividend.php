<?php

declare(strict_types=1);

namespace App\Actions\Dividend;

use App\Models\Dividend;
use App\Models\DividendSyncExclusion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class UpdateDividend
{
    public function __construct(private readonly DividendRules $rules) {}

    /**
     * Validate, authorize, and correct a dividend. The row becomes manual
     * so a later provider refresh cannot put the old values back.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Dividend $dividend, array $data): Dividend
    {
        $payload = $this->rules->payload($data, $dividend);

        $validated = Validator::make($payload, $this->rules->build($payload, $dividend))->validate();

        $validated['dividend_amount'] = round((float) $validated['dividend_amount'], 8);

        Gate::authorize('update', $dividend);

        if ($validated['symbol'] !== $dividend->symbol) {
            Gate::authorize('create', [Dividend::class, $validated['symbol']]);
        }

        return DB::transaction(function () use ($dividend, $validated) {
            $originalSymbol = $dividend->symbol;
            $originalDate = $dividend->date->toDateString();
            $keyChanged = $originalSymbol !== $validated['symbol']
                || $originalDate !== $validated['date'];

            if ($keyChanged) {
                DividendSyncExclusion::suppress($originalSymbol, $originalDate);
                Dividend::deleteReinvestedTransactions($originalSymbol, $originalDate);
            }

            DividendSyncExclusion::release($validated['symbol'], $validated['date']);

            $dividend->fill([
                ...$validated,
                'source' => Dividend::SOURCE_MANUAL,
            ]);
            $dividend->save();

            if (! $keyChanged) {
                Dividend::refreshReinvestedTransactions($dividend);
            }

            Dividend::syncDerivedData($validated['symbol']);

            if ($originalSymbol !== $validated['symbol']) {
                Dividend::syncDerivedData($originalSymbol);
            }

            return $dividend->refresh();
        });
    }
}
