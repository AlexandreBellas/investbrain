# Dividends API

Authenticated JSON API for per-symbol dividends. Same auth as the rest of `/api`: Laravel Passport bearer token (`Authorization: Bearer <token>`). No extra OAuth scope. Global `security` in `openapi.json` is `api_token`.

Dividends are market data shared by every holding of a symbol, not rows owned by one portfolio.

Base path: `/api` (self-hosted: `https://{base_url}/api`).

## Auth and visibility

1. No token: `401`.
2. `GET /api/dividend` lists dividends whose symbol appears on a portfolio you belong to (owner, full access, or read-only).
3. `GET /api/dividend/{id}` returns `404` when you do not hold that symbol, so existence is not leaked.
4. `POST`, `PUT`, and `DELETE` require full access or ownership of a portfolio that holds the symbol. A read-only member gets `403`. A user who does not hold the symbol gets `403` on create and `404` on update or delete.
5. Moving a dividend onto a symbol you cannot fully access returns `403` and leaves the row unchanged.
6. Another user who holds the same symbol sees the same row. A write recalculates `dividends_earned` on every holding of that symbol.

## Endpoints

| Method | Path | Success |
| --- | --- | --- |
| `GET` | `/api/dividend` | `200` paginated list |
| `POST` | `/api/dividend` | `201` one dividend, unwrapped |
| `GET` | `/api/dividend/{id}` | `200` one dividend, unwrapped |
| `PUT` | `/api/dividend/{id}` | `200` one dividend, unwrapped |
| `DELETE` | `/api/dividend/{id}` | `204` empty body |

`{id}` is the dividend UUID.

`JsonResource::withoutWrapping()` is on, so show, create, and update are the object itself. The index is paginated, so it still has `data`, `links`, and `meta`.

### Dividend object

```json
{
  "id": "9b0b6c3e-1c2d-4e5f-a6b7-c8d9e0f1a2b3",
  "symbol": "PETR4.SA",
  "date": "2024-08-15",
  "dividend_amount": 0.85,
  "dividend_amount_base": 0.85,
  "source": "manual",
  "created_at": "2026-11-01T16:00:00.000000Z",
  "updated_at": "2026-11-01T16:00:00.000000Z"
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | uuid | Primary key |
| `symbol` | string | Uppercase ticker, max 25 characters |
| `date` | `Y-m-d` | Inclusive entitlement date. For B3 this is data-com, not the payment date |
| `dividend_amount` | number | Per share, in the symbol's trading currency. Stored with 8 decimal places |
| `dividend_amount_base` | number | Same amount converted to the app base currency. Computed on save |
| `source` | `sync` or `manual` | `sync` came from the provider. `manual` was created or edited by hand |
| `created_at`, `updated_at` | date-time | ISO-8601 |

`source` and `dividend_amount_base` in a write body are ignored. Create and update always store `source=manual`.

### Write body

Create requires all three fields. Update accepts any subset; omitted fields stay as stored.

```json
{
  "symbol": "PETR4.SA",
  "date": "2024-08-15",
  "dividend_amount": 0.85
}
```

| Field | Rules |
| --- | --- |
| `symbol` | Required on create. String, max 25. Must exist in market data or with the configured provider. Uppercased |
| `date` | Required on create. `Y-m-d`. Unique per symbol |
| `dividend_amount` | Required on create. Numeric, greater than 0, max `999999999999`. Rounded to 8 decimal places |

Validation errors are `422`:

```json
{
  "message": "A dividend for this symbol and date already exists.",
  "errors": {
    "date": ["A dividend for this symbol and date already exists."]
  }
}
```

Other messages use the app's short validation copy (`This field is required.`, `This field does not match the format Y-m-d.`, `This field must be greater than 0.`, `This field must be a number.`, `This field must not be greater than 25 characters.`, `The symbol provided (NOTREAL) is not valid`).

### List query

| Param | Shape |
| --- | --- |
| `page` | integer, min 1, default 1 |
| `itemsPerPage` | integer, 1–100, default 15 |
| `sort` | Comma-separated: `symbol`, `date`, `dividend_amount`, `source`, `created_at`, `updated_at`. Prefix `-` for descending |
| `search` | String, max 255. Case-insensitive partial match on `symbol` |
| `filter[symbol]` | One symbol or comma-separated symbols. Case-insensitive |
| `filter[source]` | `sync` or `manual` |
| `filter[date]` | Exact `Y-m-d` |

Unknown `sort` or `filter` names return `422` with `errors.sort` or `errors.filter`.

Default order is `date` descending, then `id` ascending.

Paginated body:

```json
{
  "data": [],
  "links": {
    "first": "https://example.test/api/dividend?page=1",
    "last": "https://example.test/api/dividend?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": null,
    "last_page": 1,
    "path": "https://example.test/api/dividend",
    "per_page": 15,
    "to": null,
    "total": 0
  }
}
```

## What a write changes

1. `holdings.dividends_earned` is recalculated for every holding of each affected symbol.
2. `market_data.last_dividend_amount` and `last_dividend_date` follow the latest stored dividend by `date`, including manual rows.
3. Cached portfolio metrics for those portfolios are forgotten (`portfolio-metrics-{portfolioId}`, including the `metrics-{userId}` tag).
4. Creating a dividend does not create a dividend-reinvestment buy.
5. Changing only the amount updates an existing reinvestment buy on that date when the holding has `reinvest_dividends` on. It does not create one.
6. Deleting a dividend, or moving its symbol or date, deletes reinvestment buys for the old symbol and date.
7. Deleting, or moving the symbol/date, writes a row to `dividend_sync_exclusions` for the old symbol and date. Creating or updating onto that symbol and date removes the exclusion.

## How `dividends_earned` is calculated

For each holding and each dividend of that symbol:

```text
(shares bought on or before dividend.date
 - shares sold on or before dividend.date)
 * dividend_amount
```

A buy or sell on the dividend date counts. A trade the next day does not.

There is one row per symbol per calendar date. Dividendo and JCP (and any other provento) that share a data-com must be summed into one `dividend_amount`. The app does not withhold tax. Enter the per-share figure you want counted (gross, to match B3's gross proventos).

`date` is the entitlement date. Yahoo's historical dividend date is an ex-date and can differ from B3 data-com or the payment date. Set `date` to the B3 data-com when you want the total to match B3.

## Sync

`php artisan refresh:dividend-data` and the scheduler (`RefreshDividendData`, Monday/Wednesday/Friday) both call `Dividend::refreshDividendData`.

1. The provider is queried only forward from the newest `source=sync` row's `created_at`. A newer manual row does not move that cursor. `--force` only adds closed holdings (`quantity = 0`); it does not rewrite stored dividends.
2. A calendar date is skipped when any dividend already exists for that symbol and date, or when `dividend_sync_exclusions` has that symbol and date.
3. New provider rows are inserted with `source=sync`. Existing rows are never updated or deleted.
4. Reinvestment buys are created only for rows inserted on that run.
5. `dividends_earned`, the market-data snapshot, and the portfolio metrics cache are refreshed after a provider response, including when every date was skipped.

`--force` and a later scheduled run leave manual creates, manual edits, deletions, and moved dates in place, with no duplicate row.
