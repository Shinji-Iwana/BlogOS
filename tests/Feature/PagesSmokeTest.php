<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ログイン後の画面が、エラーなく表示されることの確認（段階1の完了条件）。
 *
 * WordPressへの通信は偽の応答に置き換える。
 * Google連携の画面は、作り直す対象（D-16-02）で外部の認証を必要とするため対象外とする。
 */
class PagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        Http::fake([
            // API Root（ブログ情報）
            'https://blog.example.test/wp-json' => Http::response([
                'name'            => 'Example Blog',
                'description'     => 'desc',
                'url'             => 'https://blog.example.test',
                'home'            => 'https://blog.example.test',
                'gmt_offset'      => 9,
                'timezone_string' => 'Asia/Tokyo',
                'namespaces'      => ['wp/v2'],
                'routes'          => [],
            ]),
            // 詳細（ID付き）は1件の空のオブジェクト
            'https://blog.example.test/wp-json/wp/v2/*/*' => Http::response(['id' => 1]),
            // 一覧は空の配列
            'https://blog.example.test/wp-json/wp/v2/*' => Http::response([], 200, ['X-WP-TotalPages' => 1]),
        ]);
    }

    protected function createBlog(bool $selected = true): Blog
    {
        return Blog::create([
            'home'         => 'https://blog.example.test',
            'display_name' => 'Example Blog',
            'is_selected'  => $selected,
        ]);
    }

    public function test_dashboard_without_blogs(): void
    {
        $this->actingAs($this->user)->get('/')->assertOk();
    }

    public function test_dashboard_without_selected_blog_does_not_change_db(): void
    {
        $blog = $this->createBlog(selected: false);

        $this->actingAs($this->user)->get('/')->assertOk();

        // 画面の表示だけで選択中のブログを書き換えない
        $this->assertFalse($blog->fresh()->is_selected);
    }

    #[DataProvider('pageProvider')]
    public function test_page_is_shown_with_selected_blog(string $routeName, array $params = []): void
    {
        $this->createBlog();

        $this->actingAs($this->user)
            ->get(route($routeName, $params))
            ->assertOk();
    }

    public static function pageProvider(): array
    {
        return [
            'dashboard'               => ['home'],
            'settings'                => ['settings'],
            'db blog list'            => ['database-blog-list'],
            'db blog history list'    => ['database-blog-history-list'],
            'blog registration'       => ['blogs.create'],
            'blog credentials'        => ['blogs.credentials.edit'],
            'site search'             => ['api-site-search'],
            'db category list'        => ['database-category-list'],
            'db category history'     => ['database-category-history-list'],
            'login histories'         => ['database.login-histories.index'],
            'api blog detail'         => ['api-blog-detail'],
            'api post list'           => ['post-list'],
            'api post detail'         => ['api-post-detail', ['id' => 1]],
            'api page list'           => ['api-page-list'],
            'api page detail'         => ['api-page-detail', ['id' => 1]],
            'api category list'       => ['api-category-list'],
            'api category detail'     => ['api-category-detail', ['categoryId' => 1]],
            'api tag list'            => ['api-tag-list'],
            'api media list'          => ['api-media-list'],
            'api status list'         => ['api-status-list'],
            'api type list'           => ['api-type-list'],
            'api taxonomy list'       => ['api-taxonomy-list'],
            'api author list'         => ['api-author-list'],
        ];
    }

    public function test_db_blog_detail_is_shown(): void
    {
        $blog = $this->createBlog();

        $this->actingAs($this->user)
            ->get(route('database-blog-detail', ['id' => $blog->id]))
            ->assertOk();
    }

    public function test_blog_switch_rejects_unknown_blog(): void
    {
        $this->createBlog();

        $this->actingAs($this->user)
            ->post(route('blog-switch'), ['blog_id' => 99999])
            ->assertSessionHasErrors('blog_id');
    }
}
