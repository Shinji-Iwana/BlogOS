<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\ServerStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * サーバー情報（D-71）：読み取るだけで、DB を変えない
 */
class ServerStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_shows_status_without_changing_db(): void
    {
        ScheduledTaskRun::create(['task_key' => 'model:prune', 'trigger' => 'scheduled', 'status' => 'succeeded', 'started_at' => now()->subYears(2)]);
        ScheduledTaskRun::create(['task_key' => 'model:prune', 'trigger' => 'scheduled', 'status' => 'succeeded', 'started_at' => now()->subDay()]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);

        $this->actingAs(User::factory()->create())
            ->get(route('server.index'))
            ->assertOk()
            ->assertSee('サーバーの負荷')
            ->assertSee('scheduled_task_runs')
            ->assertSee('default 1件');

        // 削除の対象は数えるだけで、消さない
        $this->assertSame(2, ScheduledTaskRun::count());
    }

    public function test_prunable_counts_records_past_retention(): void
    {
        ScheduledTaskRun::create(['task_key' => 'model:prune', 'trigger' => 'scheduled', 'status' => 'succeeded', 'started_at' => now()->subYears(2)]);
        ScheduledTaskRun::create(['task_key' => 'model:prune', 'trigger' => 'scheduled', 'status' => 'succeeded', 'started_at' => now()->subDay()]);

        $item = collect(app(ServerStatusService::class)->prunable())->firstWhere('table', 'scheduled_task_runs');

        $this->assertSame(2, $item['rows']);
        $this->assertSame(1, $item['prunable']);
        $this->assertNotNull($item['oldest']);
    }

    public function test_database_lists_tables_with_size(): void
    {
        $database = app(ServerStatusService::class)->database();

        $this->assertContains('scheduled_task_runs', array_column($database['tables'], 'name'));
        $this->assertGreaterThan(0, $database['total_bytes']);
        $this->assertGreaterThanOrEqual($database['total_bytes'], $database['file_bytes']);
        $this->assertSame(5000 * 1024 * 1024, $database['capacity_bytes']);
        $this->assertEqualsWithDelta($database['file_bytes'] / (5000 * 1024 * 1024), $database['usage_ratio'], 0.000001);
    }

    public function test_usage_ratio_is_not_shown_without_capacity(): void
    {
        config(['blogos.server.db_capacity_mb' => null]);

        $database = app(ServerStatusService::class)->database();

        $this->assertNull($database['capacity_bytes']);
        $this->assertNull($database['usage_ratio']);
    }
}
