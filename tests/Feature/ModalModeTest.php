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
            // アイコンには、マウスを乗せるとツールチップで名前を出す（D-108-01）
            foreach (ModalMode::ICON_LABELS as $iconMode => $label) {
                $this->assertStringContainsString('<span data-modal-icon="' . $iconMode . '" title="' . $label . '"><svg', $modal, $id);
            }
            // 送るボタンの名前は、モードで決める
            $this->assertMatchesRegularExpression('/<button type="submit" data-modal-submit\s*>' . ModalMode::SUBMIT_LABELS[$mode] . '<\/button>/u', $modal, $id);
        }

        // 題名（D-100-04）：新規のポップアップの題名から「～を登録」を外し、テーマは「テーマ切替」（ブログ切替とそろえる）
        foreach (['credit-balance-modal' => 'OpenAIの画面で見た残高', 'credit-purchase-modal' => '課金額', 'blog-register-modal' => 'ブログ', 'theme-switch-modal' => 'テーマ切替'] as $id => $title) {
            $this->assertStringContainsString('<span class="modal-title-text">' . $title . '</span>', $this->modalHtml($html, $id), $id);
        }

        // これまでのボタンの名前は、もう出さない
        foreach (['残高を登録する', '課金を登録する', '確認して登録する', '接続を確認して保存する'] as $old) {
            $this->assertStringNotContainsString('>' . $old . '<', $html, $old);
        }

        // 入力欄のラベルは青、値は白（ironman。スマホの表のカードの列の見出しと同じ形。D-109-01）
        $css = file_get_contents(public_path('themes/ironman/css/components/header.css'));
        $this->assertMatchesRegularExpression('/\.site-modal \.modal-label \{[^}]*color: var\(--im-cyan\);/', $css);

        // 開く要素の data-modal-mode で、モードを変えて開ける（ボタンの名前は ModalMode の1か所から）
        $this->assertStringContainsString('opener.dataset.modalMode', $html);
        $this->assertStringContainsString('const submitLabels = ' . json_encode(ModalMode::SUBMIT_LABELS), $html);
    }

    public function test_info_mode_has_no_submit_label_and_unknown_mode_is_rejected(): void
    {
        $this->assertNull(ModalMode::submitLabel(ModalMode::INFO));
        $this->assertSame('登録', ModalMode::submitLabel(ModalMode::CREATE));

        $this->expectException(\InvalidArgumentException::class);
        ModalMode::submitLabel('remove');
    }

    /**
     * 削除のモード（D-120-01）：ごみ箱のアイコン、送るボタンは「削除」。見た目は、ほかのモードの送るボタンと同じ（赤枠にしない）
     */
    public function test_delete_mode_has_trash_icon_and_delete_button(): void
    {
        $this->assertSame('削除', ModalMode::submitLabel(ModalMode::DELETE));
        $this->assertSame('削除', ModalMode::ICON_LABELS[ModalMode::DELETE]);

        $title = view('partials.modal-title', ['title' => 'カテゴリ'])->render();
        $this->assertStringContainsString('<span data-modal-icon="delete" title="削除"><svg', $title);

        // 送るボタンは、ほかのモード（更新など）と同じ形（class なし）で、名前だけが「削除」
        $submit = view('partials.modal-submit', ['mode' => ModalMode::DELETE])->render();
        $this->assertMatchesRegularExpression('/<button type="submit" data-modal-submit\s*>削除<\/button>/u', $submit);
        $this->assertStringNotContainsString('btn-danger', $submit);

        // アイコンの出し分けの CSS
        $css = file_get_contents(public_path('css/blogos.css'));
        $this->assertStringContainsString('[data-modal-mode="delete"] .modal-mode-icon > [data-modal-icon="delete"]', $css);

        // 開く要素の data-modal-mode="delete" で切り替える JavaScript（モードの一覧は ModalMode から。ボタンの見た目は変えない）
        $html = $this->get(route('scheduled-tasks.runs'))->assertOk()->getContent();
        $this->assertStringContainsString('const modalModes = ' . json_encode(ModalMode::ALL), $html);
        $this->assertStringNotContainsString('dangerModes', $html);
    }
}
