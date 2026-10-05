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

        $this->get(route('settings'))->assertOk()->assertSee('画面のテーマ')->assertSee('ironman')->assertDontSee('（制作中）');
    }

    public function test_switching_to_ironman_overrides_only_its_own_views(): void
    {
        // テーマ切替ポップアップは全画面にあるため、開いていた画面に戻る（D-57）
        $this->from(route('scheduled-tasks.index'))->put(route('settings.theme.update'), ['theme' => 'ironman'])->assertRedirect(route('scheduled-tasks.index'));
        $this->assertSame('ironman', SystemSetting::value(ThemeService::SETTING_KEY));

        // トップページは ironman の画面（アークリアクター＋共通の中身）
        $this->get(route('home'))->assertOk()
            ->assertSee('/themes/ironman/css/tokens.css?v=', false)
            ->assertDontSee('/themes/ironman/css/style.css', false)
            ->assertSee('class="theme-ironman"', false)
            ->assertSee('reactor-core', false)
            ->assertSee('ブログを登録する');

        // 上書きしていない画面は共通の画面のまま、CSS だけ ironman
        $this->get(route('settings'))->assertOk()->assertSee('/themes/ironman/css/components/table.css?v=', false)->assertSee('画面のテーマ');
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

    public function test_menu_bar_links_to_theme_setting_on_every_page(): void
    {
        // ヘッダーの下のメニューバー（D-56）：どの画面からでも「画面のテーマ」へ
        foreach ([route('settings'), route('scheduled-tasks.index')] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('class="site-menu"', false)
                ->assertSee('SETTING')
                // 押すとテーマ切替ポップアップを開く（D-57）
                ->assertSee('data-modal-open="theme-switch-modal"', false)
                ->assertSee('id="theme-switch-modal"', false);
        }
        // 設定の画面には、画面のテーマの欄を出さない（D-57）
        $this->get(route('settings'))->assertDontSee('テーマを切り替える')->assertDontSee('data-code="THEME"', false);

        // 「設定」の左に「履歴」。その中の「WordPressとの同期」で、同期の履歴の画面を開く（D-69）
        $html = $this->get(route('settings'))->getContent();
        $this->assertMatchesRegularExpression('/HISTORY<\/span>\s*<span class="site-menu-label">履歴<\/span>.*?href="' . preg_quote(route('database.sync-runs.index'), '/') . '"\s*>WordPressとの同期<\/a>.*?SETTING<\/span>/s', $html);
        \App\Models\Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        $this->get(route('database.sync-runs.index'))->assertOk()->assertSee('<h1>WordPressとの同期の履歴</h1>', false);
    }

    public function test_unknown_theme_is_rejected_and_falls_back_to_default(): void
    {
        $this->put(route('settings.theme.update'), ['theme' => 'unknown'])->assertSessionHasErrors('theme');

        // 登録から消えたテーマが保存されていても、既定のテーマで表示する
        SystemSetting::put(ThemeService::SETTING_KEY, 'removed');
        $this->assertSame('blank', app(ThemeService::class)->current());
    }
}
