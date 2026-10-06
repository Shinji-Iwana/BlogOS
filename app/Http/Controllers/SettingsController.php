<?php

namespace App\Http\Controllers;

use App\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 設定。画面「設定」はなくした（中身はメニューの「設定」のポップアップに移った。D-63-23）。
 */
class SettingsController extends Controller
{
    public function __construct(
        protected ThemeService $themes
    ) {
    }

    /**
     * 画面のテーマを変える（D-49）。ブログごとではなく、BlogOS 全体の設定
     */
    public function updateTheme(Request $request)
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', Rule::in(array_keys($this->themes->available()))],
        ]);

        $this->themes->select($validated['theme'], $request->user()?->id);

        // テーマ切替ポップアップは全画面にあるため、開いていた画面に戻る（D-57）
        return back()->with('status', "テーマを「{$this->themes->available()[$validated['theme']]['label']}」に変えました。");
    }
}
