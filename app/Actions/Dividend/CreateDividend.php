<?php

declare(strict_types=1);

namespace App\Actions\Dividend;

use App\Models\Dividend;
use App\Models\DividendSyncExclusion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class CreateDividend
{
    public function __construct(private readonly DividendRules $rules) {}

    /**
     * Validate, authorize, and store a hand-entered dividend.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): Dividend
    {
        $payload = $this->rules->payload($data);

        $validated = Validator::make($payload, $this->rules->build($payload))->validate();

        $validated['dividend_amount'] = round((float) $validated['dividend_amount'], 8);

        Gate::authorize('create', [Dividend::class, $validated['symbol']]);

        return DB::transaction(function () use ($validated) {
            DividendSyncExclusion::release($validated['symbol'], $validated['date']);

            $dividend = Dividend::create([
                ...$validated,
                'source' => Dividend::SOURCE_MANUAL,
            ]);

            Dividend::syncDerivedData($dividend->symbol);

            return $dividend;
        });
    }
}
