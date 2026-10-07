<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 画面のテーマの切り替え（D-49）。
 */
class ThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_blank_is_used_until_a_theme_is_selected(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertSee('/themes/blank/css/style.css', false)
            ->assertSee('class="theme-blank"', false)
            ->assertDontSee('reactor-core', false);

        $this->get(route('scheduled-tasks.runs'))->assertOk()->assertSee('画面のテーマ')->assertSee('ironman')->assertDontSee('（制作中）');
    }

    public function test_switching_to_ironman_overrides_only_its_own_views(): void
    {
        // テーマ切替ポップアップは全画面にあるため、開いていた画面に戻る（D-57）
        $this->from(route('scheduled-tasks.runs'))->put(route('settings.theme.update'), ['theme' => 'ironman'])->assertRedirect(route('scheduled-tasks.runs'));
        $this->assertSame('ironman', SystemSetting::value(ThemeService::SETTING_KEY));

        // トップページは ironman の画面（アークリアクター＋共通の中身）
        $this->get(route('home'))->assertOk()
            ->assertSee('/themes/ironman/css/tokens.css?v=', false)
            ->assertDontSee('/themes/ironman/css/style.css', false)
            ->assertSee('class="theme-ironman"', false)
            ->assertSee('reactor-core', false)
            // アークリアクターは重ねた SVG に分け、回転・脈打ちは SVG そのものに付ける（D-73-01）
            ->assertSee('<svg class="reactor-layer reactor-hud reactor-hud-a"', false)
            ->assertSee('<svg class="reactor-layer reactor-coil-spark-a"', false)
            ->assertSee('<svg class="reactor-layer reactor-center"', false)
            // アニメーションの設定（画面のテーマのポップアップ。このブラウザに保存。D-73-02）と、表示する前の読み込み
            ->assertSee('id="animation-settings"', false)
            ->assertSee('data-anim-key="connectors"', false)
            ->assertSee("localStorage.getItem('blogos.animations')", false)
            ->assertSee('ブログを登録する');

        // 上書きしていない画面は共通の画面のまま、CSS だけ ironman
        $this->get(route('scheduled-tasks.runs'))->assertOk()->assertSee('/themes/ironman/css/components/table.css?v=', false)->assertSee('画面のテーマ');
    }

    public function test_login_page_uses_the_selected_theme(): void
    {
        auth()->logout();

        $this->get(route('login'))->assertOk()
            ->assertSee('class="theme-blank page-guest"', false)
            ->assertSee('/css/blogos.css', false)
            ->assertDontSee('reactor-svg', false);

        app(ThemeService::class)->select('ironman', null);

        // ironman はアークリアクターと BlogOS の名前を加えたログイン画面（themes/ironman/auth/login）
        $this->get(route('login'))->assertOk()
            ->assertSee('/themes/ironman/css/components/login.css', false)
            ->assertSee('reactor-svg', false)
            ->assertSee('SYSTEM ACCESS')
            ->assertSee('name="password"', false);
    }

    public function test_error_pages_and_site_search_use_the_theme(): void
    {
        app(ThemeService::class)->select('ironman', null);

        // エラーの画面（ログイン前の枠。400番台は BlogOS が添えた理由も出す）
        $this->get('/no-such-page')->assertNotFound()
            ->assertSee('/themes/ironman/css/tokens.css', false)
            ->assertSee('ERROR 404', false)
            ->assertSee('ページが見つかりません');

        // ブログを選んでいないときのサイト内検索：abort(404, 'ブログが選択されていません。')
        $this->get(route('api-site-search'))->assertNotFound()
            ->assertSee('ブログが選択されていません。')
            ->assertSee('トップページへ');
    }

    public function test_menu_groups_are_in_the_order_set_by_the_user(): void
    {
        // 履歴・設定の中の順（D-63-26）
        $groups = collect(\App\Support\MenuItems::GROUPS)->keyBy('key');
        $this->assertSame(['ログイン履歴', 'ブログ情報の同期履歴', 'WordPressとの同期履歴', 'Googleとの同期履歴', 'OpenAI API料金表との同期履歴', '定期実行履歴'], array_column($groups['history']['links'], 'label'));
        $this->assertSame(['画面のテーマ', 'ブログ', 'Google', 'OpenAI', 'アフィリエイト提携先を登録', '即時実行', '定期実行'], array_column($groups['setting']['links'], 'label'));
    }

    public function test_menu_bar_links_to_theme_setting_on_every_page(): void
    {
        // ヘッダーの下のメニューバー（D-56）：どの画面からでも「画面のテーマ」へ
        foreach ([route('scheduled-tasks.runs'), route('scheduled-tasks.runs')] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('class="site-menu"', false)
                // ヘッダーの右端の今の日時（日本時間。ironman テーマで表示する）
                ->assertSee('<span class="site-clock-label">SYSTEM TIME</span>', false)
                ->assertSee('SETTING')
                // 押すとテーマ切替ポップアップを開く（D-57）
                ->assertSee('data-modal-open="theme-switch-modal"', false)
                ->assertSee('id="theme-switch-modal"', false);
        }
        // 画面「設定」はなくした（D-63-23）。トップページの入口の「管理」にも出さない
        $this->get('/settings')->assertNotFound();
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('settings'));
        $this->assertNotContains('settings', array_column(array_merge(...array_column(\App\Support\DashboardLinks::GROUPS, 'links')), 'route'));

        // 「設定」の左に「履歴」。その中の「WordPressとの同期」で、同期の履歴の画面を開く（D-69）
        $html = $this->get(route('scheduled-tasks.runs'))->getContent();
        $this->assertMatchesRegularExpression('/HISTORY<\/span>\s*<span class="site-menu-label">履歴<\/span>.*?href="' . preg_quote(route('database.sync-runs.index'), '/') . '"\s*>WordPressとの同期履歴<\/a>.*?SETTING<\/span>/s', $html);
        // 「設定」の右に「情報」。その中の「WordPress情報」で、WordPress情報の画面を開く（D-63-11・D-63-15）
        $this->assertMatchesRegularExpression('/SETTING<\/span>.*?INFO<\/span>\s*<span class="site-menu-label">情報<\/span>.*?href="' . preg_quote(route('wordpress-updates.index'), '/') . '"\s*>WordPress情報<\/a>\s*<\/li>\s*<li><a href="' . preg_quote(route('wp-api.home'), '/') . '"\s*>WordPress API情報<\/a>/s', $html);
        // 設定の画面には、WordPress API情報の欄を出さない（D-63-12）
        $this->assertStringNotContainsString(route('wp-api.home'), explode('<main', $html, 2)[1] ?? '');
        \App\Models\Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        $this->get(route('database.sync-runs.index'))->assertOk()->assertSee('<h1>WordPressとの同期履歴 <span class="tip"', false);
        $this->get(route('wordpress-updates.index'))->assertOk()->assertSee('<h1>WordPress情報 <span class="tip"', false)->assertSee('<h2>WordPress 本体</h2>', false)->assertSee('<h2>プラグイン</h2>', false)->assertSee('<h2>テーマ</h2>', false);
        $this->get(route('wp-api.home'))->assertOk()->assertSee('<h1>WordPress API情報</h1>', false)
            ->assertSee('<a href="' . route('home') . '">トップページに戻る</a>', false)->assertDontSee('設定ページに戻る');

        // トップページの「管理」には、WordPress情報・定期実行履歴の入口を出さない（メニューから開く。D-63-11）
        $routes = array_column(array_merge(...array_column(\App\Support\DashboardLinks::GROUPS, 'links')), 'route');
        $this->assertNotContains('wordpress-updates.index', $routes);
        $this->assertNotContains('scheduled-tasks.runs', $routes);
        $this->assertNotContains('wp-api.home', $routes);
    }

    public function test_unknown_theme_is_rejected_and_falls_back_to_default(): void
    {
        $this->put(route('settings.theme.update'), ['theme' => 'unknown'])->assertSessionHasErrors('theme');

        // 登録から消えたテーマが保存されていても、既定のテーマで表示する
        SystemSetting::put(ThemeService::SETTING_KEY, 'removed');
        $this->assertSame('blank', app(ThemeService::class)->current());
    }
}
