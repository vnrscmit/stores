<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\AuditLog;
use App\Models\Gtod;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 10 slice 2 acceptance: the audit trail screen over the port's
 * audit_logs (legacy audit_trail_debug.php was a developer debug page
 * over QR tables that never existed — see docs/PHASE10.md 2.5; this
 * screen reads the trail App\Support\Audit has been writing since the
 * first Phase 9 slice).
 *
 * Semantics under test:
 *  - newest-first paginated listing with module / action / user-login /
 *    date-range filters (module options collected from the present data);
 *  - the counts-by-action summary under the current filter (legacy debug
 *    screen's section 2, minus its hardcoded action list);
 *  - the per-entry detail view rendering the before/after snapshots;
 *  - gate: viewer + admin only (operators and e-indent raisers bounce to
 *    their own module home), guests to login.
 *
 * Hermeticity: the suite WRITES its own audit rows (deterministic
 * module prefix, swept in setUp AND tearDown so it neither sees nor
 * leaks pipeline/other-suite rows), then reads them back through the
 * real endpoints.
 */
class Phase10AuditTest extends TestCase
{
    private static bool $pipelineReady = false;

    /** The module prefix this suite writes (always swept). */
    private const TEST_MODULE = 'test.audit-slice';

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$pipelineReady) {
            if (! Schema::hasTable('users')) {
                $this->artisan('migrate:fresh', ['--force' => true]);
                $this->artisan('legacy:stage-import', ['--limit' => (string) LegacyStageImport::TEST_SUBSET_LIMIT]);
                $this->artisan('legacy:migrate-data');
                $this->artisan('legacy:integrity-fix', ['--strategy' => 'placeholder']);
                $this->artisan('legacy:integrity-fix', ['--strategy' => 'null']);
                $this->artisan('legacy:add-constraints');
            }

            // Apply pending migrations added after this database was built.
            $this->artisan('migrate', ['--force' => true]);

            self::$pipelineReady = true;
        }

        $this->sweep();
    }

    protected function tearDown(): void
    {
        $this->sweep();

        parent::tearDown();
    }

    private function sweep(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        AuditLog::query()->where('module', self::TEST_MODULE)->delete();
    }

    /** Write deterministic audit rows through the port's own writer. */
    private function seedEntries(): void
    {
        $admin = User::query()->where('role', 'admin')->firstOrFail();

        $rows = [
            ['action' => 'post', 'user_login' => 'op-alpha', 'record' => ['type' => 'gtods', 'id' => 11]],
            ['action' => 'post', 'user_login' => 'op-beta', 'record' => ['type' => 'gtods', 'id' => 12]],
            ['action' => 'open', 'user_login' => 'op-alpha', 'record' => null],
        ];

        foreach ($rows as $i => $row) {
            AuditLog::query()->create([
                'user_id' => $admin->id,
                'user_login' => $row['user_login'],
                'module' => self::TEST_MODULE,
                'action' => $row['action'],
                'record_type' => $row['record']['type'] ?? null,
                'record_id' => $row['record']['id'] ?? null,
                'before' => ['qty' => 1.0, 'ups' => 1],
                'after' => ['qty' => 4.0 + $i, 'ups' => 4 + $i],
                'ip' => '127.0.0.1',
            ]);
        }
    }

    private function asRole(string $role): User
    {
        $user = User::query()->where('role', $role)->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    public function test_module_requires_authentication(): void
    {
        $this->get(route('audit.index'))->assertRedirect(route('login'));
    }

    public function test_non_privileged_roles_bounce_home(): void
    {
        $this->asRole('operator');

        $this->get(route('audit.index'))
            ->assertRedirect(route('operator.home'))
            ->assertSessionHas('warning');

        $this->asRole('eindent');

        $this->get(route('audit.index'))
            ->assertRedirect(route('eindent.home'))
            ->assertSessionHas('warning');
    }

    public function test_admin_and_viewer_can_open_the_trail(): void
    {
        $this->seedEntries();

        $this->asRole('admin');
        $this->get(route('audit.index'))->assertOk();

        $this->asRole('viewer');
        $this->get(route('audit.index'))->assertOk();
    }

    public function test_listing_shows_entries_newest_first(): void
    {
        $this->seedEntries();
        $this->asRole('viewer');

        $resp = $this->get(route('audit.index', ['module' => self::TEST_MODULE]));
        $resp->assertOk();

        $html = $resp->getContent();
        $first = strpos($html, 'gtods #11');
        $second = strpos($html, 'gtods #12');

        // Newest (highest id) first: the #12 row was inserted after #11,
        // so it renders BEFORE it.
        $this->assertNotFalse($first);
        $this->assertNotFalse($second);
        $this->assertGreaterThan($second, $first);
    }

    public function test_filters_narrow_the_listing(): void
    {
        $this->seedEntries();
        $this->asRole('viewer');

        // Module filter finds the suite rows...
        $this->get(route('audit.index', ['module' => self::TEST_MODULE]))
            ->assertOk()
            ->assertSee('op-alpha')
            ->assertSee('op-beta');

        // ...action filter keeps only 'open'...
        $resp = $this->get(route('audit.index', [
            'module' => self::TEST_MODULE,
            'action' => 'open',
        ]))->assertOk();
        $this->assertSame(0, substr_count((string) $resp->getContent(), 'gtods #'));

        // ...user filter matches by LIKE...
        $this->get(route('audit.index', [
            'module' => self::TEST_MODULE,
            'user' => 'beta',
        ]))->assertOk()->assertSee('op-beta')->assertDontSee('op-alpha');

        // ...and a date range in the future excludes everything.
        $this->get(route('audit.index', [
            'module' => self::TEST_MODULE,
            'from' => now()->addYear()->toDateString(),
        ]))->assertOk()->assertSee('No audit entries match the filters.');
    }

    public function test_action_counts_summary_reflects_the_filter(): void
    {
        $this->seedEntries();
        $this->asRole('viewer');

        $resp = $this->get(route('audit.index', ['module' => self::TEST_MODULE]));
        $resp->assertOk();

        $html = (string) $resp->getContent();
        $this->assertStringContainsString('post: 2', $html);
        $this->assertStringContainsString('open: 1', $html);

        $resp = $this->get(route('audit.index', [
            'module' => self::TEST_MODULE,
            'action' => 'post',
        ]));
        $html = (string) $resp->getContent();
        $this->assertStringContainsString('post: 2', $html);
        $this->assertStringNotContainsString('open: 1', $html);
    }

    public function test_detail_view_renders_snapshots(): void
    {
        $this->seedEntries();

        // The LAST post row (op-beta): after = qty 5.0, ups 5.
        $entry = AuditLog::query()
            ->where('module', self::TEST_MODULE)
            ->where('action', 'post')
            ->orderByDesc('id')
            ->firstOrFail();

        $this->asRole('viewer');

        $this->get(route('audit.show', $entry))
            ->assertOk()
            ->assertSee('Audit entry #'.$entry->id)
            ->assertSee('op-beta')
            ->assertSee('&quot;qty&quot;: 5', false)
            ->assertSee('&quot;ups&quot;: 5', false);
    }

    public function test_record_type_renders_readable_alias(): void
    {
        $this->seedEntries();

        $entry = AuditLog::query()
            ->where('module', self::TEST_MODULE)
            ->where('record_type', 'gtods')
            ->orderByDesc('id')
            ->firstOrFail();

        $this->asRole('viewer');

        // The morph alias maps to the model (the provider registers the
        // Phase 9 movement models), so 'gtods' resolves instead of an FQCN.
        $map = Relation::morphMap();
        $this->assertSame(Gtod::class, $map['gtods']);

        $this->get(route('audit.show', $entry))
            ->assertOk()
            ->assertSee('gtods #12');
    }
}
