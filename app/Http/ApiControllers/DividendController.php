<?php

declare(strict_types=1);

namespace App\Http\ApiControllers;

use App\Actions\Dividend\CreateDividend;
use App\Actions\Dividend\DeleteDividend;
use App\Actions\Dividend\UpdateDividend;
use App\Http\ApiControllers\Controller as ApiController;
use App\Http\Resources\DividendResource;
use App\Models\Dividend;
use App\Models\Holding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class DividendController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $symbols = Holding::query()->myHoldings()->distinct()->pluck('symbol');

        $query = Dividend::query()
            ->whereIn('symbol', $symbols)
            ->tap($this->search($request, ['symbol']));

        $dividends = QueryBuilder::for($query, $request)
            ->allowedFilters(
                AllowedFilter::callback('symbol', function ($query, mixed $value, string $property): void {
                    $symbols = collect(Arr::wrap($value))
                        ->map(fn ($symbol) => strtoupper((string) $symbol))
                        ->all();

                    $query->whereIn('symbol', $symbols);
                }),
                AllowedFilter::exact('source'),
                AllowedFilter::exact('date'),
            )
            ->allowedSorts('symbol', 'date', 'dividend_amount', 'source', 'created_at', 'updated_at')
            ->orderByDesc('dividends.date')
            ->orderBy('dividends.id')
            ->paginate($this->itemsPerPage($request))
            ->withQueryString();

        return DividendResource::collection($dividends);
    }

    public function store(Request $request, CreateDividend $action): DividendResource
    {
        return DividendResource::make($action->handle($request->all()));
    }

    public function show(Dividend $dividend): DividendResource
    {
        if (! Gate::check('view', $dividend)) {
            abort(404);
        }

        return DividendResource::make($dividend);
    }

    public function update(Request $request, Dividend $dividend, UpdateDividend $action): DividendResource
    {
        if (! Gate::check('view', $dividend)) {
            abort(404);
        }

        return DividendResource::make($action->handle($dividend, $request->all()));
    }

    public function destroy(Dividend $dividend, DeleteDividend $action): Response
    {
        if (! Gate::check('view', $dividend)) {
            abort(404);
        }

        $action->handle($dividend);

        return response()->noContent();
    }
}
