<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Interfaces\MarketData\FakeMarketData;
use App\Models\Dividend;
use App\Models\DividendSyncExclusion;
use App\Models\Holding;
use App\Models\MarketData;
use App\Models\Portfolio;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;

class DividendsTest extends ApiTestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Portfolio $portfolio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->portfolio = Portfolio::factory()->makeOne();
        $this->portfolio->setOwnerIdAttribute($this->user->id);
        $this->portfolio->save();
    }

    public function test_returns_401_for_dividend_routes_without_a_token(): void
    {
        $id = (string) Str::uuid();

        $this->getJson(route('api.dividend.index'))->assertUnauthorized();
        $this->postJson(route('api.dividend.store'), [])->assertUnauthorized();
        $this->getJson(route('api.dividend.show', $id))->assertUnauthorized();
        $this->putJson(route('api.dividend.update', $id), [])->assertUnauthorized();
        $this->deleteJson(route('api.dividend.destroy', $id))->assertUnauthorized();
    }

    public function test_lists_dividends_only_for_symbols_the_user_holds(): void
    {
        $this->buy($this->portfolio, 'ACME', 10, '2020-01-10');
        Dividend::factory()->create(['symbol' => 'ACME', 'date' => '2024-01-01', 'dividend_amount' => 1]);
        Dividend::factory()->create(['symbol' => 'GOOG', 'date' => '2024-01-01', 'dividend_amount' => 2]);

        $this->actingAs($this->user)
            ->getJson(route('api.dividend.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.symbol', 'ACME')
            ->assertJsonStructure([
                'data' => [['id', 'symbol', 'date', 'dividend_amount', 'dividend_amount_base', 'source', 'created_at', 'updated_at']],
                'meta' => ['current_page', 'last_page', 'total'],
                'links' => ['first', 'last', 'prev', 'next'],
            ]);
    }

    public function test_filters_dividends_by_symbol_ignoring_case(): void
    {
        $this->buy($this->portfolio, 'ACME', 1, '2020-01-10');
        $this->buy($this->portfolio, 'GOOG', 1, '2020-01-10');
        Dividend::factory()->create(['symbol' => 'ACME', 'date' => '2024-01-01']);
        Dividend::factory()->create(['symbol' => 'GOOG', 'date' => '2024-01-01']);

        $this->actingAs($this->user)
            ->getJson(route('api.dividend.index', ['filter' => ['symbol' => 'acme']]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.symbol', 'ACME');
    }

    public function test_sorts_dividends_by_amount_descending(): void
    {
        $this->buy($this->portfolio, 'ACME', 1, '2020-01-10');
        Dividend::factory()->create(['symbol' => 'ACME', 'date' => '2024-01-01', 'dividend_amount' => 1]);
        Dividend::factory()->create(['symbol' => 'ACME', 'date' => '2024-02-01', 'dividend_amount' => 3]);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.dividend.index', ['sort' => '-dividend_amount']));

        $response->assertOk();
        $this->assertSame([3.0, 1.0], array_map('floatval', array_column($response->json('data'), 'dividend_amount')));
    }

    public function test_returns_422_when_sorting_by_a_column_that_is_not_sortable(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('api.dividend.index', ['sort' => 'symbol; DROP TABLE dividends']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort' => 'The sort field may only contain: symbol, date, dividend_amount, source, created_at, updated_at.']);
    }

    public function test_returns_422_when_filtering_by_a_column_that_is_not_filterable(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('api.dividend.index', ['filter' => ['dividend_amount' => '1']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filter' => 'The filter field may only contain: symbol, source, date.']);
    }

    public function test_returns_422_when_search_is_not_a_string(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('api.dividend.index', ['search' => ['ACME']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search']);
    }

    public function test_returns_422_when_items_per_page_is_above_100(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('api.dividend.index', ['itemsPerPage' => 101]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['itemsPerPage']);
    }

    public function test_creates_a_manual_dividend_and_recalculates_every_holding(): void
    {
        $this->buy($this->portfolio, 'ACME', 10, '2020-01-10');

        $other = User::factory()->create();
        $this->actingAs($other);
        $otherPortfolio = Portfolio::factory()->create();
        $this->buy($otherPortfolio, 'ACME', 4, '2020-01-10');

        cache()->tags(['metrics-'.$this->user->id])->put('portfolio-metrics-'.$this->portfolio->id, 'stale', 60);

        $response = $this->actingAs($this->user)
            ->postJson(route('api.dividend.store'), [
                'symbol' => 'acme',
                'date' => '2024-08-15',
                'dividend_amount' => 1.5,
                'source' => Dividend::SOURCE_SYNC,
                'dividend_amount_base' => 999,
            ]);

        $response->assertCreated()
            ->assertJsonPath('symbol', 'ACME')
            ->assertJsonPath('date', '2024-08-15')
            ->assertJsonPath('dividend_amount', 1.5)
            ->assertJsonPath('source', Dividend::SOURCE_MANUAL)
            ->assertJsonStructure(['id', 'dividend_amount_base', 'created_at', 'updated_at']);

        $this->assertNotSame(999.0, (float) $response->json('dividend_amount_base'));
        $this->assertDatabaseHas('dividends', [
            'id' => $response->json('id'),
            'symbol' => 'ACME',
            'source' => Dividend::SOURCE_MANUAL,
        ]);
        $this->assertEquals(15.0, Holding::query()->where('portfolio_id', $this->portfolio->id)->where('symbol', 'ACME')->first()->dividends_earned);
        $this->assertEquals(6.0, Holding::query()->where('portfolio_id', $otherPortfolio->id)->where('symbol', 'ACME')->first()->dividends_earned);
        $this->assertEquals(1.5, MarketData::query()->find('ACME')->last_dividend_amount);
        $this->assertNull(cache()->tags(['metrics-'.$this->user->id])->get('portfolio-metrics-'.$this->portfolio->id));
    }

    public function test_counts_shares_bought_on_the_dividend_date_and_ignores_later_buys(): void
    {
        $this->buy($this->portfolio, 'ACME', 10, '2020-01-10');
        Transaction::factory()->sell(4)->date('2024-08-15')->portfolio($this->portfolio->id)->symbol('ACME')->create();
        Transaction::factory()->buy(2)->date('2024-08-16')->portfolio($this->portfolio->id)->symbol('ACME')->create();

        $this->actingAs($this->user)
            ->postJson(route('api.dividend.store'), [
                'symbol' => 'ACME',
                'date' => '2024-08-15',
                'dividend_amount' => 2,
            ])
            ->assertCreated();

        $this->assertEquals(12.0, Holding::query()->where('portfolio_id', $this->portfolio->id)->where('symbol', 'ACME')->first()->dividends_earned);
    }

    public function test_returns_422_when_required_dividend_fields_are_missing(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('api.dividend.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'symbol' => 'This field is required.',
                'date' => 'This field is required.',
                'dividend_amount' => 'This field is required.',
            ]);
    }

    #[TestWith([0, 'This field must be greater than 0.'])]
    #[TestWith([-1, 'This field must be greater than 0.'])]
    #[TestWith(['nope', 'This field must be a number.'])]
    public function test_returns_422_when_dividend_amount_is_not_a_positive_number(mixed $amount, string $message): void
    {
        $this->buy($this->portfolio, 'ACME', 1, '2020-01-10');

        $this->actingAs($this->user)
            ->postJson(route('api.dividend.store'), [
                'symbol' => 'ACME',
                'date' => '2024-08-15',
                'dividend_amount' => $amount,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dividend_amount' => $message]);

        $this->assertSame(0, Dividend::query()->count());
    }

    public function test_returns_422_when_the_dividend_date_is_not_ymd(): void
    {
        $this->buy($this->portfolio, 'ACME', 1, '2020-01-10');

        $this->actingAs($this->user)
            ->postJson(route('api.dividend.store'), [
                'symbol' => 'ACME',
                'date' => '15/08/2024',
                'dividend_amount' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'This field does not match the format Y-m-d.']);
    }

    public function test_returns_422_when_a_dividend_already_exists_for_the_symbol_and_date(): void
    {
        $this->buy($this->portfolio, 'ACME', 1, '2020-01-10');
        Dividend::factory()->create(['symbol' => 'ACME', 'date' => '2024-08-15', 'dividend_amount' => 1]);

        $this->actingAs($this->user)
            ->postJson(route('api.dividend.store'), [
                'symbol' => 'ACME',
                'date' => '2024-08-15',
                'dividend_amount' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'A dividend for this symbol and date already exists.']);

        $this->assertSame(1, Dividend::query()->count());
    }

    public function test_returns_422_when_the_symbol_is_unknown(): void
    {
        $fake = Mockery::mock(FakeMarketData::class)->makePartial();
        $fake->shouldReceive('exists')->with('NOTREAL')->andReturn(false);
        $this->app->instance(FakeMarketData::class, $fake);

        $this->actingAs($this->user)
            ->postJson(route('api.dividend.store'), [
                'symbol' => 'NOTREAL',
                'date' => '2024-08-15',
                'dividend_amount' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['symbol' => 'The symbol provided (NOTREAL) is not valid']);
    }

    public function test_returns_422_when_the_symbol_is_longer_than_25_characters(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('api.dividend.store'), [
                'symbol' => str_repeat('A', 26),
                'date' => '2024-08-15',
                'dividend_amount' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['symbol' => 'This field must not be greater than 25 characters.']);
    }

    public function test_returns_403_when_creating_a_dividend_for_a_symbol_the_user_does_not_hold(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('api.dividend.store'), [
                'symbol' => 'ACME',
                'date' => '2024-08-15',
                'dividend_amount' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(0, Dividend::query()->count());
    }

    public function test_returns_403_when_a_read_only_member_creates_a_dividend(): void
    {
        $this->buy($this->portfolio, 'ACME', 1, '2020-01-10');

        $reader = User::factory()->create();
        $this->actingAs($this->user);
        $this->portfolio->share($reader->email, false);

        $this->actingAs($reader)
            ->postJson(route('api.dividend.store'), [
                'symbol' => 'ACME',
                'date' => '2024-08-15',
                'dividend_amount' => 1,
            ])
            ->assertForbidden();
    }

    public function test_shows_a_dividend_for_a_symbol_the_user_holds(): void
    {
        $this->buy($this->portfolio, 'ACME', 1, '2020-01-10');
        $dividend = Dividend::factory()->create(['symbol' => 'ACME', 'date' => '2024-08-15', 'dividend_amount' => 1.25]);

        $this->actingAs($this->user)
            ->getJson(route('api.dividend.show', $dividend))
            ->assertOk()
            ->assertJsonPath('id', $dividend->id)
            ->assertJsonPath('dividend_amount', 1.25);
    }

    public function test_returns_404_when_showing_a_dividend_for_a_symbol_the_user_does_not_hold(): void
    {
        $dividend = Dividend::factory()->create(['symbol' => 'ACME']);

        $this->actingAs($this->user)
            ->getJson(route('api.dividend.show', $dividend))
            ->assertNotFound();
    }

    public function test_updates_a_dividend_and_recalculates_holdings(): void
    {
        $this->buy($this->portfolio, 'ACME', 10, '2020-01-10');
        $dividend = Dividend::factory()->create([
            'symbol' => 'ACME',
            'date' => '2024-08-15',
            'dividend_amount' => 1,
            'source' => Dividend::SOURCE_SYNC,
        ]);
        Dividend::syncDerivedData('ACME');

        $this->actingAs($this->user)
            ->putJson(route('api.dividend.update', $dividend), ['dividend_amount' => 2])
            ->assertOk()
            ->assertJsonPath('dividend_amount', 2)
            ->assertJsonPath('source', Dividend::SOURCE_MANUAL)
            ->assertJsonPath('date', '2024-08-15');

        $this->assertEquals(20.0, Holding::query()->where('symbol', 'ACME')->first()->dividends_earned);
        $this->assertSame(Dividend::SOURCE_MANUAL, $dividend->fresh()->source);
    }

    public function test_moving_the_dividend_date_changes_who_is_eligible_and_suppresses_the_old_date(): void
    {
        $this->buy($this->portfolio, 'ACME', 10, '2020-01-10');
        Transaction::factory()->sell(10)->date('2024-08-16')->portfolio($this->portfolio->id)->symbol('ACME')->create();

        $dividend = Dividend::factory()->create([
            'symbol' => 'ACME',
            'date' => '2024-08-15',
            'dividend_amount' => 2,
            'source' => Dividend::SOURCE_SYNC,
        ]);
        Dividend::syncDerivedData('ACME');

        $this->assertEquals(20.0, Holding::query()->where('symbol', 'ACME')->first()->dividends_earned);

        $this->actingAs($this->user)
            ->putJson(route('api.dividend.update', $dividend), ['date' => '2024-08-16'])
            ->assertOk()
            ->assertJsonPath('date', '2024-08-16')
            ->assertJsonPath('source', Dividend::SOURCE_MANUAL);

        $this->assertEquals(0.0, Holding::query()->where('symbol', 'ACME')->first()->dividends_earned);
        $this->assertTrue(
            DividendSyncExclusion::query()->where('symbol', 'ACME')->whereDate('date', '2024-08-15')->exists()
        );
    }

    public function test_returns_403_when_a_read_only_member_updates_a_dividend(): void
    {
        $this->buy($this->portfolio, 'ACME', 1, '2020-01-10');
        $dividend = Dividend::factory()->create(['symbol' => 'ACME']);

        $reader = User::factory()->create();
        $this->actingAs($this->user);
        $this->portfolio->share($reader->email, false);

        $this->actingAs($reader)
            ->putJson(route('api.dividend.update', $dividend), ['dividend_amount' => 9])
            ->assertForbidden();

        $this->assertEquals(1.25, $dividend->fresh()->dividend_amount);
    }

    public function test_returns_404_when_updating_a_dividend_for_a_symbol_the_user_does_not_hold(): void
    {
        $dividend = Dividend::factory()->create(['symbol' => 'ACME', 'dividend_amount' => 1.25]);

        $this->actingAs($this->user)
            ->putJson(route('api.dividend.update', $dividend), ['dividend_amount' => 9])
            ->assertNotFound();

        $this->assertEquals(1.25, $dividend->fresh()->dividend_amount);
    }

    public function test_returns_403_when_moving_a_dividend_onto_a_symbol_the_user_does_not_hold(): void
    {
        $this->buy($this->portfolio, 'ACME', 1, '2020-01-10');
        $dividend = Dividend::factory()->create(['symbol' => 'ACME', 'date' => '2024-08-15']);

        $this->actingAs($this->user)
            ->putJson(route('api.dividend.update', $dividend), ['symbol' => 'GOOG'])
            ->assertForbidden();

        $this->assertSame('ACME', $dividend->fresh()->symbol);
    }

    public function test_moving_a_dividend_to_another_held_symbol_recalculates_both(): void
    {
        $this->buy($this->portfolio, 'ACME', 10, '2020-01-10');
        $this->buy($this->portfolio, 'GOOG', 2, '2020-01-10');
        $dividend = Dividend::factory()->create([
            'symbol' => 'ACME',
            'date' => '2024-08-15',
            'dividend_amount' => 3,
        ]);
        Dividend::syncDerivedData('ACME');

        $this->actingAs($this->user)
            ->putJson(route('api.dividend.update', $dividend), ['symbol' => 'GOOG'])
            ->assertOk()
            ->assertJsonPath('symbol', 'GOOG');

        $this->assertEquals(0.0, Holding::query()->where('symbol', 'ACME')->first()->dividends_earned);
        $this->assertEquals(6.0, Holding::query()->where('symbol', 'GOOG')->first()->dividends_earned);
    }

    public function test_deletes_a_dividend_and_recalculates_holdings_to_zero(): void
    {
        $this->buy($this->portfolio, 'ACME', 10, '2020-01-10');
        $older = Dividend::factory()->create(['symbol' => 'ACME', 'date' => '2024-01-01', 'dividend_amount' => 1]);
        $latest = Dividend::factory()->create(['symbol' => 'ACME', 'date' => '2024-08-15', 'dividend_amount' => 2]);
        Dividend::syncDerivedData('ACME');

        $this->actingAs($this->user)
            ->deleteJson(route('api.dividend.destroy', $latest))
            ->assertNoContent();

        $this->assertModelMissing($latest);
        $this->assertEquals(10.0, Holding::query()->where('symbol', 'ACME')->first()->dividends_earned);
        $this->assertEquals(1.0, MarketData::query()->find('ACME')->last_dividend_amount);
        $this->assertTrue(DividendSyncExclusion::query()->where('symbol', 'ACME')->whereDate('date', '2024-08-15')->exists());
        $this->assertModelExists($older);
    }

    public function test_returns_403_when_a_read_only_member_deletes_a_dividend(): void
    {
        $this->buy($this->portfolio, 'ACME', 1, '2020-01-10');
        $dividend = Dividend::factory()->create(['symbol' => 'ACME']);

        $reader = User::factory()->create();
        $this->actingAs($this->user);
        $this->portfolio->share($reader->email, false);

        $this->actingAs($reader)
            ->deleteJson(route('api.dividend.destroy', $dividend))
            ->assertForbidden();

        $this->assertModelExists($dividend);
    }

    public function test_returns_404_when_deleting_a_dividend_for_a_symbol_the_user_does_not_hold(): void
    {
        $dividend = Dividend::factory()->create(['symbol' => 'ACME']);

        $this->actingAs($this->user)
            ->deleteJson(route('api.dividend.destroy', $dividend))
            ->assertNotFound();

        $this->assertModelExists($dividend);
    }

    public function test_refresh_keeps_a_hand_created_dividend(): void
    {
        $this->buy($this->portfolio, 'ACME', 10, '2020-01-10');

        $created = $this->actingAs($this->user)
            ->postJson(route('api.dividend.store'), [
                'symbol' => 'ACME',
                'date' => '2024-05-01',
                'dividend_amount' => 1.75,
            ])
            ->assertCreated()
            ->json();

        $this->artisan('refresh:dividend-data')->assertSuccessful();
        $this->artisan('refresh:dividend-data', ['--force' => true])->assertSuccessful();

        $dividend = Dividend::query()->find($created['id']);

        $this->assertSame('2024-05-01', $dividend->date->toDateString());
        $this->assertEquals(1.75, $dividend->dividend_amount);
        $this->assertSame(Dividend::SOURCE_MANUAL, $dividend->source);
        $this->assertSame(1, Dividend::query()->where('symbol', 'ACME')->whereDate('date', '2024-05-01')->count());
        $this->assertEquals(67.0, Holding::query()->where('symbol', 'ACME')->first()->dividends_earned);
    }

    public function test_refresh_does_not_restore_a_corrected_synced_dividend(): void
    {
        $this->buy($this->portfolio, 'ACME', 10, '2020-01-10');

        $this->artisan('refresh:dividend-data')->assertSuccessful();

        $dividend = Dividend::query()->orderBy('date')->first();
        $originalDate = $dividend->date->toDateString();

        $this->actingAs($this->user)
            ->putJson(route('api.dividend.update', $dividend), ['dividend_amount' => 5])
            ->assertOk();

        $this->artisan('refresh:dividend-data')->assertSuccessful();
        $this->artisan('refresh:dividend-data', ['--force' => true])->assertSuccessful();

        $dividend->refresh();

        $this->assertEquals(5.0, $dividend->dividend_amount);
        $this->assertSame(Dividend::SOURCE_MANUAL, $dividend->source);
        $this->assertSame($originalDate, $dividend->date->toDateString());
        $this->assertSame(1, Dividend::query()->where('symbol', 'ACME')->whereDate('date', $originalDate)->count());
    }

    public function test_refresh_does_not_recreate_a_deleted_dividend(): void
    {
        $this->buy($this->portfolio, 'ACME', 1, '2020-01-10');

        $this->artisan('refresh:dividend-data')->assertSuccessful();

        $dividend = Dividend::query()->orderBy('date')->first();
        $date = $dividend->date->toDateString();

        $this->actingAs($this->user)
            ->deleteJson(route('api.dividend.destroy', $dividend))
            ->assertNoContent();

        $this->artisan('refresh:dividend-data', ['--force' => true])->assertSuccessful();

        $this->assertNull(Dividend::query()->where('symbol', 'ACME')->whereDate('date', $date)->first());
        $this->assertSame(2, Dividend::query()->count());
    }

    public function test_refresh_does_not_recreate_a_date_a_dividend_was_moved_away_from(): void
    {
        $this->buy($this->portfolio, 'ACME', 1, '2020-01-10');

        $this->artisan('refresh:dividend-data')->assertSuccessful();

        $dividend = Dividend::query()->orderBy('date')->first();
        $oldDate = $dividend->date->toDateString();

        $this->actingAs($this->user)
            ->putJson(route('api.dividend.update', $dividend), ['date' => '2019-04-01'])
            ->assertOk();

        $this->artisan('refresh:dividend-data', ['--force' => true])->assertSuccessful();

        $this->assertNull(Dividend::query()->where('symbol', 'ACME')->whereDate('date', $oldDate)->first());
        $this->assertSame('2019-04-01', $dividend->fresh()->date->toDateString());
        $this->assertSame(Dividend::SOURCE_MANUAL, $dividend->fresh()->source);
    }

    public function test_deleting_a_dividend_removes_its_reinvested_buy(): void
    {
        $this->buy($this->portfolio, 'ACME', 10, '2020-01-10');
        Holding::query()->where('symbol', 'ACME')->update(['reinvest_dividends' => true]);

        $fake = Mockery::mock(FakeMarketData::class)->makePartial();
        $fake->shouldReceive('dividends')->andReturn(collect([
            new \App\Interfaces\MarketData\Types\Dividend([
                'symbol' => 'ACME',
                'date' => '2024-08-15',
                'dividend_amount' => 2,
            ]),
        ]));
        $this->app->instance(FakeMarketData::class, $fake);

        $this->artisan('refresh:dividend-data')->assertSuccessful();

        $this->assertSame(1, Transaction::query()->where('reinvested_dividend', true)->count());

        $dividend = Dividend::query()->first();

        $this->actingAs($this->user)
            ->deleteJson(route('api.dividend.destroy', $dividend))
            ->assertNoContent();

        $this->assertSame(0, Transaction::query()->where('reinvested_dividend', true)->count());
        $this->assertEquals(10.0, Holding::query()->where('symbol', 'ACME')->first()->quantity);
        $this->assertEquals(0.0, Holding::query()->where('symbol', 'ACME')->first()->dividends_earned);
    }

    public function test_updating_the_amount_updates_an_existing_reinvested_buy(): void
    {
        $this->buy($this->portfolio, 'ACME', 10, '2020-01-10');
        Holding::query()->where('symbol', 'ACME')->update(['reinvest_dividends' => true]);

        $fake = Mockery::mock(FakeMarketData::class)->makePartial();
        $fake->shouldReceive('dividends')->andReturn(collect([
            new \App\Interfaces\MarketData\Types\Dividend([
                'symbol' => 'ACME',
                'date' => '2024-08-15',
                'dividend_amount' => 2,
            ]),
        ]));
        $this->app->instance(FakeMarketData::class, $fake);

        $this->artisan('refresh:dividend-data')->assertSuccessful();

        $dividend = Dividend::query()->first();
        $marketValue = MarketData::query()->find('ACME')->market_value;

        $this->actingAs($this->user)
            ->putJson(route('api.dividend.update', $dividend), ['dividend_amount' => 4])
            ->assertOk();

        $reinvested = Transaction::query()->where('reinvested_dividend', true)->first();

        $this->assertEqualsWithDelta((4 * 10) / $marketValue, $reinvested->quantity, 0.0001);
        $this->assertEqualsWithDelta(40 + ((4 * 10) / $marketValue) * 4, Holding::query()->where('symbol', 'ACME')->first()->dividends_earned, 0.01);
    }

    private function buy(Portfolio $portfolio, string $symbol, float $quantity, string $date): void
    {
        // TransactionFactory::definition() always creates a portfolio, which needs an authenticated owner.
        if (auth()->user() === null) {
            $this->actingAs($this->user);
        }

        Transaction::factory()->buy($quantity)->date($date)->portfolio($portfolio->id)->symbol($symbol)->costBasis(10)->create();
    }
}
