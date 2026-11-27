<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 削除・取り消せない操作のボタンの見た目（D-122-01・D-122-02。docs/BLOGOS_UI.md 4-5）：主な操作のボタンと同じ（赤枠の btn-danger は使わない）。
 */
class DeleteButtonStyleTest extends TestCase
{
    public function test_delete_and_irreversible_buttons_use_the_main_style(): void
    {
        $found = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            // 送るボタン（1行に書いたもの）の、開始タグと名前
            preg_match_all('/<button\b([^\n]*?)>(?:@include\([^)]*\))?([^<\n]*)<\/button>/u', $file->getContents(), $matches, PREG_SET_ORDER);
            foreach ($matches as [, $attributes, $label]) {
                if (str_contains($attributes, 'type="button"') || ! preg_match('/削除|ゴミ箱|破棄|取り消す|解除/u', $label)) {
                    continue;
                }
                $found[] = $relative;
                // 主な操作と同じ（class を付けない）
                $this->assertStringNotContainsString('class=', $attributes, $relative . '：' . trim($label));
            }
        }
        // 削除（4か所。記事とカテゴリ・タグ・メディアの完全削除は、共通の削除のポップアップの送るボタンになった。D-125-01・D-128-04）と、破棄・取り消し・解除（6つ）が見つかる
        $this->assertGreaterThanOrEqual(10, count($found));

        // 赤枠の btn-danger は、画面にも CSS にもない
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $this->assertDoesNotMatchRegularExpression('/class="[^"]*btn-danger/', $file->getContents(), $file->getRelativePathname());
            }
        }
        $sheets = collect(array_merge(File::allFiles(public_path('css')), File::allFiles(public_path('themes'))))
            ->filter(fn ($file) => $file->getExtension() === 'css');
        $this->assertNotEmpty($sheets);
        foreach ($sheets as $css) {
            $this->assertDoesNotMatchRegularExpression('/\.btn-danger[^\n]*\{/', $css->getContents(), $css->getPathname());
        }
    }
}
