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

    public function test_wordpress_tables_are_shown_like_wordpress_data_page(): void
    {
        $blog = \App\Models\Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        \App\Models\Tag::create(['blog_id' => $blog->id, 'wordpress_id' => 1, 'name' => 'A', 'slug' => 'a']);
        \App\Models\Tag::create(['blog_id' => $blog->id, 'wordpress_id' => 2, 'name' => 'B', 'slug' => 'b', 'wordpress_deleted_at' => now()]);

        // 取り込んだ WordPress のデータのテーブルは「名前（テーブル名）」のリンクと、WordPress側で削除済みの件数を、件数と容量の間に出す（D-71-07）
        $this->actingAs(User::factory()->create())
            ->get(route('server.index'))
            ->assertOk()
            ->assertSeeInOrder(['<h2>DB（BlogOS）</h2>', '<th>テーブル</th><th>件数</th><th>うちWordPress側で削除済み</th><th>容量（データと索引）</th>'], false)
            ->assertSeeInOrder(['<a href="' . route('database.wordpress-records.index', ['table' => 'tags']) . '">タグ</a>（tags）</td>', '>2</td>', '>1</td>'], false)
            // ほかのテーブルも「日本語名（テーブル名）」で、全テーブルの一覧へつなぐ（D-80）
            ->assertSee('<a href="' . route('database.tables.index', ['table' => 'scheduled_task_runs']) . '">定期実行の記録</a>（scheduled_task_runs）', false);
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
            $this->actingAs(User::factory()->create())->get(route('server.index'))->assertOk()->assertSee('WordPress：' . $blog->display_name)
                // WordPress の DB のテーブルは、WordPress の DB の一覧へつなぐ（BlogOS の DB の一覧へのリンクにしない。D-81）
                ->assertSee('<a href="' . route('database.wordpress-db.index', ['schema' => $schema, 'table' => 'wp_options']) . '">サイトの設定</a>（wp_options）', false)
                ->assertDontSee(route('database.tables.index', ['table' => 'wp_options']), false);
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
