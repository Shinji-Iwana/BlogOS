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
            'login histories'         => ['database.login-histories.index'],
            'wp api home'             => ['wp-api.home'],
            'wp api root'             => ['wp-api.resources.index', ['resource' => 'root']],
            'wp api settings'         => ['wp-api.resources.index', ['resource' => 'settings']],
            'wp api posts'            => ['wp-api.resources.index', ['resource' => 'posts']],
            'wp api post detail'      => ['wp-api.resources.show', ['resource' => 'posts', 'id' => 1]],
            'wp api pages'            => ['wp-api.resources.index', ['resource' => 'pages']],
            'wp api categories'       => ['wp-api.resources.index', ['resource' => 'categories']],
            'wp api tags'             => ['wp-api.resources.index', ['resource' => 'tags']],
            'wp api users'            => ['wp-api.resources.index', ['resource' => 'users']],
            'wp api media'            => ['wp-api.resources.index', ['resource' => 'media']],
            'wp api statuses'         => ['wp-api.resources.index', ['resource' => 'statuses']],
            'wp api types'            => ['wp-api.resources.index', ['resource' => 'types']],
            'wp api taxonomies'       => ['wp-api.resources.index', ['resource' => 'taxonomies']],
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
