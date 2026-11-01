<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class DividendSyncExclusion extends Model
{
    use HasUuids;

    protected $fillable = [
        'symbol',
        'date',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    /**
     * Symbols are stored uppercase so they match dividend and holding symbols.
     */
    protected function symbol(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtoupper($value)
        );
    }

    /**
     * Remember a symbol/date the provider must not insert again.
     */
    public static function suppress(string $symbol, mixed $date): void
    {
        static::query()->firstOrCreate([
            'symbol' => strtoupper($symbol),
            'date' => Carbon::parse($date)->toDateString(),
        ]);
    }

    /**
     * Allow a symbol/date to be stored again after the user puts a dividend back.
     */
    public static function release(string $symbol, mixed $date): void
    {
        static::query()
            ->where('symbol', strtoupper($symbol))
            ->whereDate('date', Carbon::parse($date)->toDateString())
            ->delete();
    }
}
