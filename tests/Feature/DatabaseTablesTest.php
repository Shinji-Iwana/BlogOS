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
            ->assertSee('選択中のブログ（Example Blog）の行と、どのブログにも属さない行を出します。')
            ->assertSee('<h2 data-code="FILTER">条件</h2>', false)
            ->assertSee('（id降順）')
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

        // XServer情報の DB の一覧から開くテーブルだけ「XServer情報に戻る」を出す。取り込んだ WordPress のデータのテーブル（投稿など）は出さない（D-97-03）
        $this->get(route('database.tables.index', ['table' => 'sync_runs']))->assertSee('XServer情報に戻る');
        $this->get(route('database.tables.index', ['table' => 'posts', 'column' => 'id', 'value' => 1]))->assertOk()->assertDontSee('XServer情報に戻る');

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
        $this->get(route('database.tables.index', ['table' => 'blogs']))->assertOk()->assertSee('Example Blog')->assertSee('Other Blog')
            ->assertSee('ブログの列（blog_id）を持たないテーブルのため、選択中のブログによらず、全ての行を出します。');
        $this->get(route('database.tables.index', ['table' => 'blog_settings']))->assertOk()
            ->assertSee('ブログの情報のテーブルのため、選択中のブログによらず、全てのブログの行を出します。');
        $this->get(route('database.wordpress-records.index', ['table' => 'posts']))->assertOk()
            ->assertSee('選択中のブログ（Example Blog）の行を出します（WordPress側で削除済みの行も含みます）。');

        // 変更履歴のパネル（履歴のテーブルは、関連のパネルには出さない）
        $this->get(route('database.tables.show', ['table' => 'blogs', 'id' => $blog->id]))->assertOk()
            ->assertSeeInOrder(['<h2 data-code="HISTORY">変更履歴</h2>', '旧名'], false)
            // アコーディオンの題名の項目は「 / 」でつなぐ（D-114-01）
            ->assertSee(' / blogos_manual / display_name</summary>', false)
            ->assertDontSee('ブログの変更履歴（blog_histories）');
    }

    /**
     * URL の値は、どの画面でもリンクにし、別のタブで開く（D-113-01）
     */
    public function test_url_values_are_links_that_open_in_a_new_tab(): void
    {
        $this->actingAs(User::factory()->create());
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        $link = '<a href="https://blog.example.test" target="_blank" rel="noopener">https://blog.example.test</a>';

        // DB の確認の画面（一覧・詳細）と、管理の画面（ブログ一覧・ブログ詳細）で、同じ出し方
        $this->get(route('database.tables.index', ['table' => 'blogs']))->assertOk()->assertSee($link, false);
        $this->get(route('database.tables.show', ['table' => 'blogs', 'id' => $blog->id]))->assertOk()->assertSee($link, false);
        $this->get(route('database-blog-list'))->assertOk()->assertSee($link, false);
        $this->get(route('database-blog-detail', ['id' => $blog->id]))->assertOk()->assertSee($link, false);

        // URL でない値・秘密の値は、リンクにしない。長い URL は、見せる文字だけを縮め、リンク先は元の URL のまま
        $this->assertSame('Example Blog', (string) \App\Support\UrlLink::html('Example Blog'));
        $this->assertSame('/path?a=1&amp;b=2', (string) \App\Support\UrlLink::html('/path?a=1&b=2'));
        $this->assertSame('javascript:alert(1)', (string) \App\Support\UrlLink::html('javascript:alert(1)'));
        $this->assertSame('https://a.test x', (string) \App\Support\UrlLink::html('https://a.test x'));
        $this->assertSame('', (string) \App\Support\UrlLink::html(null));
        $long = 'https://example.test/' . str_repeat('a', 100);
        $this->assertSame('<a href="' . $long . '" target="_blank" rel="noopener">' . \Illuminate\Support\Str::limit($long, 80) . '</a>', (string) \App\Support\UrlLink::html($long, 80));
        $this->assertSame('<a href="https://a.test/?q=1&amp;r=2" target="_blank" rel="noopener">https://a.test/?q=1&amp;r=2</a>', (string) \App\Support\UrlLink::html('https://a.test/?q=1&r=2'));
        $this->assertSame('https://a.test/&quot;&gt;', (string) \App\Support\UrlLink::html('https://a.test/">'));
    }

    public function test_server_page_links_every_table(): void
    {
        $this->actingAs(User::factory()->create());
        // 取り込んだ WordPress のデータの11テーブルは、ブログを選んでいるときにリンクになる
        Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);

        // テーブルの一覧は50件ずつのため、全ページをつないで見る（D-98-01）
        $html = $this->serverPageWithAllTables()
            ->assertSee('<a href="' . route('database.tables.index', ['table' => 'sync_runs']) . '">WordPressとの同期の記録</a>（sync_runs）', false)
            ->getContent();

        // 全てのテーブルの一覧に、XServer情報から行ける（D-110-01）
        $missing = array_values(array_filter(DatabaseTables::names(), fn ($table) => ! str_contains($html, 'href="' . e(DatabaseTables::listUrl($table)) . '"')));
        $this->assertSame([], $missing, 'XServer情報の DB の一覧から行けないテーブルがあります。');
    }

    /**
     * 全てのテーブルに、一覧と詳細がある（D-110-01）。
     * 詳細は、行を決める番号（主キー）が1列のときに開ける。テーブルを足すときは、主キーを1列にする
     */
    public function test_every_table_has_a_detail_page(): void
    {
        $missing = array_values(array_filter(DatabaseTables::names(), fn ($table) => DatabaseTables::primaryKey($table) === null));

        $this->assertSame([], $missing, '詳細を開けないテーブル（主キーが1列でない）があります。主キーを1列にしてください。');
    }
}
