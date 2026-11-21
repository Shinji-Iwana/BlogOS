<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ModalMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ポップアップのモード（D-100）：題名の左のアイコンと、送るボタンの名前。
 */
class ModalModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * ポップアップ（id）の、開始タグから閉じるまでの HTML の一部（次のポップアップの手前まで）
     */
    private function modalHtml(string $html, string $id): string
    {
        $start = strpos($html, 'id="' . $id . '"');
        $this->assertNotFalse($start, "ポップアップ {$id} がない");
        // 自分の class（id の直後）を飛ばして、次のポップアップの手前まで
        $own = strpos($html, 'class="blog-switch-modal', $start);
        $next = strpos($html, 'class="blog-switch-modal', $own + 1);

        return substr($html, $start, $next === false ? null : $next - $start);
    }

    public function test_each_popup_has_its_mode_icon_and_submit_label(): void
    {
        $html = $this->get(route('scheduled-tasks.runs'))->assertOk()->getContent();

        $expected = [
            'credit-balance-modal'      => 'create',
            'credit-purchase-modal'     => 'create',
            'blog-register-modal'       => 'create',
            'voice-settings-modal'      => 'edit',
            'scheduled-blogs-sync-modal' => 'edit',
            'theme-switch-modal'        => 'switch',
        ];
        foreach ($expected as $id => $mode) {
            $modal = $this->modalHtml($html, $id);
            $this->assertStringContainsString('data-modal-mode="' . $mode . '"', $modal, $id);
            // 題名は共通の部品（4つのアイコンを置き、CSS でモードに合うものだけを見せる）
            $this->assertStringContainsString('<h2 class="modal-title">', $modal, $id);
            $this->assertStringContainsString('data-modal-icon="' . $mode . '"', $modal, $id);
            // 送るボタンの名前は、モードで決める
            $this->assertMatchesRegularExpression('/<button type="submit" data-modal-submit\s*>' . ModalMode::SUBMIT_LABELS[$mode] . '<\/button>/u', $modal, $id);
        }

        // これまでのボタンの名前は、もう出さない
        foreach (['残高を登録する', '課金を登録する', '確認して登録する', '接続を確認して保存する'] as $old) {
            $this->assertStringNotContainsString('>' . $old . '<', $html, $old);
        }

        // 開く要素の data-modal-mode で、モードを変えて開ける（ボタンの名前は ModalMode の1か所から）
        $this->assertStringContainsString('opener.dataset.modalMode', $html);
        $this->assertStringContainsString('const submitLabels = ' . json_encode(ModalMode::SUBMIT_LABELS), $html);
    }

    public function test_info_mode_has_no_submit_label_and_unknown_mode_is_rejected(): void
    {
        $this->assertNull(ModalMode::submitLabel(ModalMode::INFO));
        $this->assertSame('登録', ModalMode::submitLabel(ModalMode::CREATE));

        $this->expectException(\InvalidArgumentException::class);
        ModalMode::submitLabel('delete');
    }
}
