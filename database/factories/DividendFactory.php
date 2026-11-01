<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Dividend;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dividend>
 */
class DividendFactory extends Factory
{
    protected $model = Dividend::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'symbol' => 'ACME',
            'date' => '2024-06-15',
            'dividend_amount' => 1.25,
            'source' => Dividend::SOURCE_SYNC,
        ];
    }

    public function manual(): static
    {
        return $this->state(fn () => [
            'source' => Dividend::SOURCE_MANUAL,
        ]);
    }
}
