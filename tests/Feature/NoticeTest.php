<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Notice;
use App\Models\User;
use App\Models\WordPressComponent;
use App\Services\Notices\NoticeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * お知らせ（D-74）：記録・変動・解消・確認済み・ヘッダーの数・自動で開くポップアップ
 */
class NoticeTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
    }

    protected function plugins(int $updates): void
    {
        WordPressComponent::where('blog_id', $this->blog->id)->delete();
        foreach (range(1, $updates) as $i) {
            WordPressComponent::create(['blog_id' => $this->blog->id, 'type' => 'plugin', 'slug' => "plugin-{$i}", 'name' => "Plugin {$i}", 'update_available' => true]);
        }
    }

    protected function wordpressNotices()
    {
        return Notice::where('kind', 'wordpress')->orderBy('id')->get();
    }

    public function test_notice_is_recorded_changed_and_resolved(): void
    {
        $service = app(NoticeService::class);

        // 新しく起きた問題：記録する（未確認）
        $this->plugins(2);
        $service->refresh();
        $this->assertCount(1, $this->wordpressNotices());
        $this->assertStringContainsString('更新が2件あります', $this->wordpressNotices()[0]->message);

        // 同じ内容なら、記録を増やさない
        $service->refresh();
        $this->assertCount(1, $this->wordpressNotices());

        // 良くなった（減った）ときも、新しいお知らせを記録し、前のものに変動した日時を残す（解消ではない）
        $this->plugins(1);
        $service->refresh();
        [$old, $new] = $this->wordpressNotices()->all();
        $this->assertNotNull($old->changed_at);
        $this->assertNull($old->resolved_at);
        $this->assertNull($new->changed_at);
        $this->assertStringContainsString('更新が1件あります', $new->message);

        // 問題がなくなった：最新のお知らせにだけ、解消した日時を残す
        WordPressComponent::where('blog_id', $this->blog->id)->delete();
        $service->refresh();
        [$old, $new] = $this->wordpressNotices()->all();
        $this->assertNull($old->resolved_at);
        $this->assertNotNull($new->resolved_at);
        $this->assertSame(0, Notice::open()->where('kind', 'wordpress')->count());
    }

    public function test_page_confirms_notices_without_removing_them(): void
    {
        $this->plugins(2);
        app(NoticeService::class)->refresh();
        $notice = $this->wordpressNotices()->first();

        // ヘッダーの数は、変動も解消もしていない未確認のお知らせの数
        $this->get(route('notices.index'))->assertOk()
            ->assertSee('<h1>お知らせ履歴', false)
            ->assertSee('最後の照合：')
            ->assertSee('更新が2件あります')
            ->assertSee('<span class="notice-badge" aria-hidden="true">' . Notice::open()->count() . '</span>', false)
            ->assertSee('<strong>未確認</strong>', false)
            // 要対応・注意は、アークリアクターの小さな絵の色で表す（D-74-06）
            ->assertSee('<symbol id="notice-reactor"', false)
            ->assertSee('class="notice-icon notice-icon-error"', false);

        $this->post(route('notices.confirm'), ['ids' => [$notice->id]])->assertRedirect();

        $this->assertNotNull($notice->fresh()->confirmed_at);
        // 確認済みにした後は、数え直した数で、ヘッダーの数（横の画面のパネルの中なら、トップページのヘッダーも）を直す
        $this->get(route('notices.index'))->assertOk()->assertSee('更新が2件あります')->assertSee('確認済み')
            ->assertSee('const count = ' . Notice::open()->count() . ';', false);
    }

    public function test_popup_opens_once_per_login_and_again_for_new_notices(): void
    {
        $this->plugins(2);

        // ログインして最初にトップページを開いたとき
        $this->get(route('home'))->assertOk()->assertSee('id="notice-popup"', false)->assertSee('未確認のお知らせがあります');
        // 2回目は開かない
        $this->get(route('home'))->assertOk()->assertDontSee('id="notice-popup"', false);

        // 新しいお知らせが増えたら、また開く
        $this->plugins(3);
        $this->get(route('home'))->assertOk()->assertSee('id="notice-popup"', false);

        // 確認済みにすると、開かない（変動・解消したものも対象外）
        Notice::query()->update(['confirmed_at' => now()]);
        $this->plugins(3);
        $this->get(route('home'))->assertOk()->assertDontSee('id="notice-popup"', false);
    }
}
