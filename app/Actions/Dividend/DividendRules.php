<?php

declare(strict_types=1);

namespace App\Actions\Dividend;

use App\Models\Dividend;
use App\Rules\SymbolValidationRule;
use DateTimeInterface;

class DividendRules
{
    /**
     * Normalize a create or update payload. Omitted fields keep the stored value.
     *
     * @param  array<string, mixed>  $data
     * @return array{symbol: mixed, date: mixed, dividend_amount: mixed}
     */
    public function payload(array $data, ?Dividend $dividend = null): array
    {
        $symbol = array_key_exists('symbol', $data) ? $data['symbol'] : $dividend?->symbol;
        $date = array_key_exists('date', $data) ? $data['date'] : $dividend?->date?->toDateString();
        $amount = array_key_exists('dividend_amount', $data) ? $data['dividend_amount'] : $dividend?->dividend_amount;

        if ($date instanceof DateTimeInterface) {
            $date = $date->format('Y-m-d');
        }

        return [
            'symbol' => is_string($symbol) ? strtoupper($symbol) : $symbol,
            'date' => $date,
            'dividend_amount' => $amount,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<int, mixed>>
     */
    public function build(array $payload, ?Dividend $dividend = null): array
    {
        $symbol = is_string($payload['symbol'] ?? null) ? $payload['symbol'] : '';

        return [
            'symbol' => ['required', 'string', 'max:25', new SymbolValidationRule],
            'date' => [
                'required',
                'date_format:Y-m-d',
                function (string $attribute, mixed $value, \Closure $fail) use ($symbol, $dividend): void {
                    if (! is_string($value) || $symbol === '') {
                        return;
                    }

                    $exists = Dividend::query()
                        ->where('symbol', $symbol)
                        ->whereDate('date', $value)
                        ->when($dividend !== null, fn ($query) => $query->whereKeyNot($dividend->getKey()))
                        ->exists();

                    if ($exists) {
                        $fail('A dividend for this symbol and date already exists.');
                    }
                },
            ],
            'dividend_amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
        ];
    }
}
