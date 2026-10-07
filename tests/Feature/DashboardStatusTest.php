<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\User;
use App\Models\WordPressComponent;
use App\Services\Dashboard\DashboardStatusService;
use App\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * トップページの状態のパネルと、アークリアクターの色（D-49-07）。
 */
class DashboardStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function data(array $overrides = []): array
    {
        return $overrides + [
            'selectedBlog'      => new Blog(['display_name' => 'Example']),
            'syncStatus'        => ['state' => 'idle', 'unresolved_issue_count' => 0, 'latest_run' => ['status' => 'succeeded', 'status_label' => '成功', 'started_at' => now(), 'finished_at' => now()]],
            'scheduleNotice'    => ['failed' => [], 'stopped' => false],
            'wordpressNotice'   => ['updates' => 0, 'closed' => 0],
            'priceNotice'       => ['pending' => 0, 'failed' => false, 'applied' => 0],
            'brokenLinks'       => 0,
            'linkSwitchDrafts'  => 0,
            'affiliateSuspects' => 0,
        ];
    }

    protected function panels(array $data, string $creditLevel = 'ok'): array
    {
        $credit = ['balance' => 10.0, 'level' => $creditLevel];

        return app(DashboardStatusService::class)->panels($data, $credit, true);
    }

    public function test_overall_state_is_the_worst_panel(): void
    {
        $service = app(DashboardStatusService::class);

        $normal = $this->panels($this->data());
        $this->assertSame('normal', $service->overall($normal));
        $this->assertSame(['error' => 0, 'warn' => 0], $service->counts($normal));
        // パネルのリンクの文言
        $this->assertSame(['WordPressとの同期履歴', '定期実行履歴', 'WordPress情報'], [
            collect($normal)->firstWhere('key', 'sync')['link'],
            collect($normal)->firstWhere('key', 'schedule')['link'],
            collect($normal)->firstWhere('key', 'wordpress')['link'],
        ]);

        // WordPress の更新は注意、AI の残高が少ないのも注意 → 金
        $warning = $this->panels($this->data(['wordpressNotice' => ['updates' => 2, 'closed' => 0]]), 'warning');
        $this->assertSame('warning', $service->overall($warning));
        $this->assertSame(['error' => 0, 'warn' => 2], $service->counts($warning));

        // OpenAI API料金表の行（トップページのお知らせと同じ書き出し。D-63-29）
        $pricePanels = $this->panels($this->data(['priceNotice' => ['pending' => 2, 'failed' => true, 'applied' => 0]]));
        $this->assertSame(['OpenAI API料金表：読み取れなかった料金あり', 'OpenAI API料金表：値下がりの確認待ち 2件'], collect($pricePanels)->firstWhere('key', 'credit')['lines']);

        // リンク切れは要対応 → 赤
        $critical = $this->panels($this->data(['brokenLinks' => 3]));
        $this->assertSame('critical', $service->overall($critical));
        $links = collect($critical)->firstWhere('key', 'links');
        $this->assertSame(['error', 'リンク切れ 3件'], [$links['state'], $links['value']]);

        // 同期の実行中は、HUD の円を速く回す
        $this->assertTrue($service->busy($this->data(['syncStatus' => ['state' => 'running', 'unresolved_issue_count' => 0, 'latest_run' => null]])));
    }

    public function test_ironman_dashboard_shows_panels_and_reactor_state(): void
    {
        $this->actingAs(User::factory()->create());
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        WordPressComponent::create(['blog_id' => $blog->id, 'type' => 'plugin', 'slug' => 'old-plugin', 'name' => 'Old Plugin', 'wporg_state' => 'closed']);
        app(ThemeService::class)->select('ironman', null);

        $this->get(route('home'))->assertOk()
            ->assertSee('SYSTEM STATUS')
            ->assertSee('data-state="critical"', false)
            ->assertSee('公開停止のプラグイン 1件')
            ->assertSee('ARTICLES')
            ->assertSee('今すぐ同期')
            // アークリアクターから全てのパネルへ線を引く範囲（D-73-03）
            ->assertSee('<div class="hud-board">', false)
            ->assertSee('<svg class="hud-connectors"', false);
    }

    public function test_blank_dashboard_lists_links_by_group(): void
    {
        $this->actingAs(User::factory()->create());
        Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);

        $this->get(route('home'))->assertOk()
            ->assertSee('記事：', false)
            ->assertSee(route('drafts.index'))
            ->assertSee('今すぐ同期')
            ->assertDontSee('SYSTEM STATUS')
            // ログアウトは、ヘッダーのアイコンだけ（D-54）
            ->assertSee('class="logout-button"', false)
            ->assertDontSee('>ログアウト</button>', false);
    }
}
