<?php

declare(strict_types=1);

namespace App\Actions\Dividend;

use App\Models\Dividend;
use App\Models\DividendSyncExclusion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteDividend
{
    /**
     * Authorize and delete a dividend, and keep the provider from inserting it again.
     */
    public function handle(Dividend $dividend): void
    {
        Gate::authorize('delete', $dividend);

        DB::transaction(function () use ($dividend) {
            $symbol = $dividend->symbol;
            $date = $dividend->date->toDateString();

            DividendSyncExclusion::suppress($symbol, $date);

            $dividend->delete();

            Dividend::deleteReinvestedTransactions($symbol, $date);

            Dividend::syncDerivedData($symbol);
        });
    }
}
