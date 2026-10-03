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
        $this->put(route('settings.theme.update'), ['theme' => 'ironman'])->assertRedirect(route('settings'));
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

    public function test_unknown_theme_is_rejected_and_falls_back_to_default(): void
    {
        $this->put(route('settings.theme.update'), ['theme' => 'unknown'])->assertSessionHasErrors('theme');

        // 登録から消えたテーマが保存されていても、既定のテーマで表示する
        SystemSetting::put(ThemeService::SETTING_KEY, 'removed');
        $this->assertSame('blank', app(ThemeService::class)->current());
    }
}
