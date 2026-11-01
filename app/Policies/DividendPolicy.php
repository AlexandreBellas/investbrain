<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Dividend;
use App\Models\Holding;
use App\Models\User;

class DividendPolicy
{
    public function view(User $user, Dividend $dividend): bool
    {
        return $this->canRead($user, $dividend->symbol);
    }

    public function create(User $user, string $symbol): bool
    {
        return $this->canWrite($user, $symbol);
    }

    public function update(User $user, Dividend $dividend): bool
    {
        return $this->canWrite($user, $dividend->symbol);
    }

    public function delete(User $user, Dividend $dividend): bool
    {
        return $this->canWrite($user, $dividend->symbol);
    }

    /**
     * Dividends are market data shared by every holding of the symbol.
     * Reading requires membership on a portfolio that holds it.
     */
    private function canRead(User $user, string $symbol): bool
    {
        return Holding::query()
            ->where('symbol', strtoupper($symbol))
            ->myHoldings($user->id)
            ->exists();
    }

    /**
     * Writing requires full access (or ownership) on a portfolio that holds it.
     */
    private function canWrite(User $user, string $symbol): bool
    {
        return Holding::query()
            ->where('symbol', strtoupper($symbol))
            ->whereHas('portfolio', fn ($query) => $query->fullAccess($user->id))
            ->exists();
    }
}
