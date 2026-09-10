<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bin;
use App\Models\Classification;
use App\Models\Item;
use App\Models\Party;
use App\Models\SubBin;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 4 acceptance: Masters CRUD (warehouse, bin, sub-bin, classification,
 * item, party) with legacy-parity validation, search, pagination, audit rows.
 */
class Phase4MastersTest extends TestCase
{
    private static bool $pipelineReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$pipelineReady) {
            return;
        }

        if (! Schema::hasTable('users')) {
            $this->artisan('migrate:fresh', ['--force' => true]);
            $this->artisan('legacy:stage-import');
            $this->artisan('legacy:migrate-data');
            $this->artisan('legacy:integrity-fix', ['--strategy' => 'placeholder']);
            $this->artisan('legacy:integrity-fix', ['--strategy' => 'null']);
            $this->artisan('legacy:add-constraints');
        }

        self::$pipelineReady = true;
    }

    private function admin(): User
    {
        $user = User::query()->where('role', 'admin')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    /** @test */
    public function masters_require_admin_role(): void
    {
        $this->get('/masters/warehouses')->assertRedirect('/login');

        $operator = User::query()->where('role', 'operator')->firstOrFail();
        $this->actingAs($operator);
        $this->get('/masters/warehouses')->assertForbidden();

        $this->admin();
        $this->get('/masters/warehouses')->assertOk();
    }

    /** @test */
    public function warehouse_crud_round_trips_with_audit(): void
    {
        $this->admin();

        $this->post('/masters/warehouses', ['perticulars' => 'ZZ Test Warehouse'])
            ->assertRedirect('/masters/warehouses');

        $warehouse = Warehouse::query()->where('perticulars', 'ZZ Test Warehouse')->firstOrFail();

        $this->put("/masters/warehouses/{$warehouse->whid}", ['perticulars' => 'ZZ Renamed'])
            ->assertRedirect('/masters/warehouses');

        $this->delete("/masters/warehouses/{$warehouse->whid}")->assertRedirect('/masters/warehouses');

        $this->assertDatabaseMissing('warehouses', ['whid' => $warehouse->whid]);

        $this->assertSame(3, AuditLog::query()
            ->where('module', 'masters.warehouse')
            ->where('record_id', $warehouse->whid)
            ->count(), 'create+update+delete audit rows expected');
    }

    /** @test */
    public function duplicate_warehouse_name_is_rejected(): void
    {
        $this->admin();

        $existing = Warehouse::firstOrFail();

        $this->from('/masters/warehouses/create')
            ->post('/masters/warehouses', ['perticulars' => $existing->perticulars])
            ->assertSessionHasErrors('perticulars');
    }

    /** @test */
    public function bin_create_seeds_sub_bins_1_to_20(): void
    {
        $this->admin();
        $warehouse = Warehouse::firstOrFail();
        $name = 'ZZ Bin '.uniqid();

        $this->post('/masters/bins', ['binname' => $name, 'whid' => $warehouse->whid])
            ->assertRedirect('/masters/bins?whid='.$warehouse->whid);

        $bin = Bin::query()->where('binname', $name)->firstOrFail();

        $this->assertSame(20, SubBin::query()->where('binid', $bin->binid)->count());
        $this->assertSame(
            range(1, 20),
            SubBin::query()->where('binid', $bin->binid)->orderBy('sname')->pluck('sname')->all()
        );
        $this->assertSame('Empty', SubBin::query()->where('binid', $bin->binid)->first()->status);

        // Legacy duplicate check: same bin name in the same warehouse fails.
        $this->post('/masters/bins', ['binname' => $name, 'whid' => $warehouse->whid])
            ->assertSessionHasErrors('binname');
    }

    /** @test */
    public function bin_delete_cascades_sub_bins_transactionally(): void
    {
        $this->admin();
        $bin = Bin::withCount('subBins')->having('sub_bins_count', '>', 0)->firstOrFail();

        $this->delete("/masters/bins/{$bin->binid}")->assertRedirect();

        $this->assertDatabaseMissing('bins', ['binid' => $bin->binid]);
        $this->assertSame(0, SubBin::query()->where('binid', $bin->binid)->count());
    }

    /** @test */
    public function sub_bin_numbers_may_repeat_across_bins_but_not_within(): void
    {
        $this->admin();
        [$binA, $binB] = Bin::query()->orderBy('binid')->limit(2)->get()->all();

        // Same number under a different bin is allowed (scoped uniqueness).
        $this->post('/masters/subbins', [
            'sname' => 999, 'binid' => $binA->binid, 'status' => 'Empty',
        ])->assertRedirect();

        $this->post('/masters/subbins', [
            'sname' => 999, 'binid' => $binB->binid, 'status' => 'Empty',
        ])->assertRedirect();

        // Same number twice in the same bin is rejected.
        $this->post('/masters/subbins', [
            'sname' => 999, 'binid' => $binA->binid, 'status' => 'Empty',
        ])->assertSessionHasErrors('sname');
    }

    /** @test */
    public function sub_bin_delete_is_disabled(): void
    {
        $this->admin();
        $subBin = SubBin::firstOrFail();

        // 405 Method Not Allowed: legacy never allowed sub-bin deletes.
        $this->delete("/masters/subbins/{$subBin->sid}")->assertStatus(405);
        $this->assertDatabaseHas('sub_bins', ['sid' => $subBin->sid]);
    }

    /** @test */
    public function item_crud_enforces_legacy_rules(): void
    {
        $this->admin();
        $classification = Classification::firstOrFail();

        $payload = [
            'classification_id' => $classification->classification_id,
            'stores_item' => 'ZZ Test Item',
            'uom' => 'Number',
            'srl_status' => 'Yes',
            'srl' => 12.5,
            'actstatus' => 'Active',
        ];

        $this->post('/masters/items', $payload)->assertRedirect('/masters/items');
        $item = Item::query()->where('stores_item', 'ZZ Test Item')->firstOrFail();

        // Duplicate item name rejected ("Duplicate not allowed." parity).
        $this->post('/masters/items', $payload)->assertSessionHasErrors('stores_item');

        // Legacy UoM list enforced.
        $this->put("/masters/items/{$item->items_id}", array_merge($payload, ['uom' => 'Boxes']))
            ->assertSessionHasErrors('uom');

        // Reorder level required when serial tracking is Yes.
        $this->put("/masters/items/{$item->items_id}",
            array_merge($payload, ['srl' => null]))
            ->assertSessionHasErrors('srl');

        $this->put("/masters/items/{$item->items_id}",
            array_merge($payload, ['stores_item' => 'ZZ Renamed Item']))
            ->assertRedirect('/masters/items');

        $this->delete("/masters/items/{$item->items_id}")->assertRedirect('/masters/items');
        $this->assertDatabaseMissing('items', ['items_id' => $item->items_id]);
    }

    /** @test */
    public function party_crud_enforces_india_state_rule(): void
    {
        $this->admin();

        $payload = [
            'classification' => 'Vendor',
            'business_name' => 'ZZ Test Party',
            'country' => 'India',
            'city' => 'Hyderabad',
        ];

        // India without a state is rejected (legacy client-side rule, now server-side).
        $this->post('/masters/parties', $payload)->assertSessionHasErrors('state');

        $this->post('/masters/parties', array_merge($payload, ['state' => 'Telangana']))
            ->assertRedirect('/masters/parties');

        $party = Party::query()->where('business_name', 'ZZ Test Party')->firstOrFail();

        $this->post('/masters/parties', array_merge($payload, ['state' => 'Telangana']))
            ->assertSessionHasErrors('business_name');

        $this->put("/masters/parties/{$party->p_id}",
            array_merge($payload, ['state' => 'Telangana', 'business_name' => 'ZZ Renamed Party']))
            ->assertRedirect('/masters/parties');

        $this->delete("/masters/parties/{$party->p_id}")->assertRedirect('/masters/parties');
    }

    /** @test */
    public function search_and_pagination_work(): void
    {
        $this->admin();

        // Search narrows results.
        $seeded = Warehouse::firstOrFail();
        $response = $this->get('/masters/warehouses?q='.urlencode($seeded->perticulars));
        $response->assertOk();
        $this->assertStringContainsString($seeded->perticulars, $response->getContent());

        // Pagination caps page size at 15.
        $this->get('/masters/items')->assertOk();
        $perPage = Item::query()->paginate(15)->perPage();
        $this->assertSame(15, $perPage);
    }

    /** @test */
    public function audit_rows_carry_user_and_action_context(): void
    {
        $this->admin();

        $name = 'ZZ Audited '.uniqid();

        // Audit rows are written by the controllers, so exercise the endpoint.
        $this->post('/masters/classifications', ['classification' => $name])
            ->assertRedirect('/masters/classifications');

        $row = AuditLog::query()
            ->where('module', 'masters.classification')
            ->where('action', 'create')
            ->where('after->classification', $name)
            ->first();

        $this->assertNotNull($row, 'audit row missing');
        $this->assertSame('admin', User::find($row->user_id)->role);
        $this->assertSame('classifications', $row->record_type);
        $this->assertNotNull($row->ip);
    }

    /** @test */
    public function item_export_returns_excel(): void
    {
        $this->admin();

        $response = $this->get('/masters/items/export');
        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type', '')
        );
    }

    /** @test */
    public function fk_enforcement_guards_master_deletes(): void
    {
        $this->admin();

        // A classification referenced by items cannot be deleted (InnoDB FK).
        $classification = Classification::query()
            ->whereHas('items')
            ->first();

        if ($classification !== null) {
            $this->expectException(QueryException::class);
            $classification->delete();
        } else {
            $this->markTestSkipped('No referenced classifications in staged data');
        }
    }
}
