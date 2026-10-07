<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\ServerStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XServer情報（D-71）：読み取るだけで、DB を変えない
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
            ->assertSee('<h1>XServer情報 <span class="tip"', false)
            ->assertSee('最後の読み取り：')
            ->assertSee('サーバーの基本情報')
            ->assertDontSee('サーバーの負荷')
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
        $this->assertSame(5000 * 1024 * 1024, $database['capacity_bytes']);
        $this->assertEqualsWithDelta($database['total_bytes'] / (5000 * 1024 * 1024), $database['usage_ratio'], 0.000001);
    }

    public function test_other_database_is_read_and_matched_to_blog(): void
    {
        $schema = 'blogos_testing_wp';
        try {
            DB::statement("create database if not exists `{$schema}`");
        } catch (\Throwable) {
            $this->markTestSkipped('テスト用の DB を作れない');
        }

        try {
            DB::statement("create table `{$schema}`.`wp_options` (option_id int primary key auto_increment, option_name varchar(191), option_value text)");
            DB::table("{$schema}.wp_options")->insert([
                ['option_name' => 'home', 'option_value' => 'https://blog.example.test'],
                ['option_name' => 'blogname', 'option_value' => 'テスト'],
            ]);
            $blog = \App\Models\Blog::create(['display_name' => 'テストのブログ', 'home' => 'http://blog.example.test/', 'is_selected' => true]);

            $other = collect(app(ServerStatusService::class)->otherDatabases())->first(fn ($item) => $item['database']['name'] === $schema);

            $this->assertNotNull($other);
            $this->assertSame('https://blog.example.test', $other['home']);
            $this->assertTrue($blog->is($other['blog']));
            $this->assertSame([['name' => 'wp_options', 'rows' => 2]], array_map(fn ($table) => ['name' => $table['name'], 'rows' => $table['rows']], $other['database']['tables']));

            // 読むだけで、WordPress の DB を変えない
            $this->actingAs(User::factory()->create())->get(route('server.index'))->assertOk()->assertSee('WordPress：' . $blog->display_name);
            $this->assertSame(2, DB::table("{$schema}.wp_options")->count());
        } finally {
            DB::statement("drop database if exists `{$schema}`");
        }
    }

    public function test_usage_ratio_is_not_shown_without_capacity(): void
    {
        config(['blogos.server.db_capacity_mb' => null]);

        $database = app(ServerStatusService::class)->database();

        $this->assertNull($database['capacity_bytes']);
        $this->assertNull($database['usage_ratio']);
    }
}
