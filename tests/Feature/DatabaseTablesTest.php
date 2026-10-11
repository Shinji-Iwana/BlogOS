<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\User;
use App\Support\DatabaseTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BlogOS の DB の全テーブルの一覧・詳細（D-80）
 */
class DatabaseTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_table_has_a_label(): void
    {
        $missing = array_values(array_filter(DatabaseTables::names(), fn ($table) => DatabaseTables::label($table) === null));

        $this->assertSame([], $missing, '日本語名のないテーブルがあります。App\Support\DatabaseTables の LABELS に足してください。');
    }

    /**
     * 全てのテーブルの全ての列に、説明がある（D-80-04。列を足したときは、App\Support\DatabaseColumns にも足す）
     */
    public function test_every_column_has_a_description(): void
    {
        $missing = [];
        foreach (DatabaseTables::names() as $table) {
            foreach (array_column(DatabaseTables::columns($table), 'name') as $column) {
                if (\App\Support\DatabaseColumns::describe($table, $column) === null) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        $this->assertSame([], $missing, '説明のない列があります。App\Support\DatabaseColumns に足してください。');
    }

    public function test_list_and_detail_show_records_like_wordpress_data_pages(): void
    {
        $user = User::factory()->create(['name' => '管理者']);
        $this->actingAs($user);
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        $other = Blog::create(['home' => 'https://other.example.test', 'display_name' => 'Other Blog', 'is_selected' => false]);
        $old = DB::table('sync_runs')->insertGetId(['blog_id' => $blog->id, 'trigger' => 'manual', 'status' => 'succeeded', 'message' => '古い同期', 'started_at' => now(), 'triggered_by' => $user->id]);
        $new = DB::table('sync_runs')->insertGetId(['blog_id' => $blog->id, 'trigger' => 'manual', 'status' => 'succeeded', 'message' => '新しい同期', 'started_at' => now()]);
        DB::table('sync_runs')->insert(['blog_id' => $other->id, 'trigger' => 'manual', 'status' => 'succeeded', 'message' => 'ほかのブログの同期', 'started_at' => now()]);
        DB::table('sync_run_resources')->insert(['sync_run_id' => $old, 'resource_type' => 'posts', 'status' => 'succeeded']);

        // 一覧：新しい順。選択中のブログの分だけ。長い文章（message）は一覧に出さない
        $this->get(route('database.tables.index', ['table' => 'sync_runs']))->assertOk()
            ->assertSee('<h1>WordPressとの同期の記録（sync_runs）</h1>', false)
            ->assertSee('<h2 data-code="FILTER">条件</h2>', false)
            ->assertSee('（id降順、最大50件。')
            ->assertSeeInOrder(['>' . $new . '</a>', '>' . $old . '</a>'], false)
            ->assertDontSee('<th>message</th>', false)
            ->assertSee('<th>status</th>', false);
        $this->assertSame(2, $this->get(route('database.tables.index', ['table' => 'sync_runs']))->viewData('records')->total());

        // 詳細：全ての列と、外部キーの値の横に「（リンク）」。関連のパネルに、この記録を指している行の件数
        $this->get(route('database.tables.show', ['table' => 'sync_runs', 'id' => $old]))->assertOk()
            ->assertSee('古い同期')
            ->assertSee('（<a href="' . route('database.tables.show', ['table' => 'blogs', 'id' => $blog->id]) . '">Example Blog #' . $blog->id . '</a>）', false)
            ->assertSee('（<a href="' . route('database.tables.show', ['table' => 'users', 'id' => $user->id]) . '">管理者 #' . $user->id . '</a>）', false)
            ->assertSeeInOrder(['<h2 data-code="RELATION">', '同期の対象ごとの結果（sync_run_resources）', 'sync_run_id', '>1</a>'], false)
            ->assertDontSee('<h2 data-code="HISTORY">', false);

        // 関連の件数から開く一覧は、その行に絞る
        $this->get(route('database.tables.index', ['table' => 'sync_run_resources', 'column' => 'sync_run_id', 'value' => $old]))->assertOk()
            ->assertSee('sync_run_id が ' . $old . ' の行');

        // 存在しないテーブル・行は 404
        $this->get(route('database.tables.index', ['table' => 'no_such_table']))->assertNotFound();
        $this->get(route('database.tables.show', ['table' => 'sync_runs', 'id' => 999999]))->assertNotFound();
    }

    public function test_secret_values_are_masked(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);
        $this->actingAs($user);

        $content = $this->get(route('database.tables.show', ['table' => 'users', 'id' => $user->id]))->assertOk()
            ->assertSee('****（パスワードのハッシュを保存）')
            ->getContent();
        $this->assertStringNotContainsString((string) DB::table('users')->where('id', $user->id)->value('password'), $content);

        $this->get(route('database.tables.index', ['table' => 'users']))->assertOk()->assertSee('****（パスワードのハッシュを保存）');
    }

    public function test_blogs_show_all_blogs_and_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        Blog::create(['home' => 'https://other.example.test', 'display_name' => 'Other Blog', 'is_selected' => false]);
        DB::table('blog_histories')->insert(['blog_id' => $blog->id, 'change_set_id' => 'set-1', 'field' => 'display_name', 'old_value' => '旧名', 'new_value' => 'Example Blog', 'source' => 'blogos_manual', 'user_id' => $user->id, 'changed_at' => now()]);

        // ブログは、選択中のブログによらず全て出す
        $this->get(route('database.tables.index', ['table' => 'blogs']))->assertOk()->assertSee('Example Blog')->assertSee('Other Blog');

        // 変更履歴のパネル（履歴のテーブルは、関連のパネルには出さない）
        $this->get(route('database.tables.show', ['table' => 'blogs', 'id' => $blog->id]))->assertOk()
            ->assertSeeInOrder(['<h2 data-code="HISTORY">変更履歴</h2>', '旧名'], false)
            ->assertDontSee('ブログの変更履歴（blog_histories）');
    }

    public function test_server_page_links_every_table(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('server.index'))->assertOk()
            ->assertSee('<a href="' . route('database.tables.index', ['table' => 'sync_runs']) . '">WordPressとの同期の記録</a>（sync_runs）', false);
    }
}
