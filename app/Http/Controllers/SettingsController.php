<?php

namespace App\Http\Controllers;

use App\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function __construct(
        protected ThemeService $themes
    ) {
    }

    /**
     * 設定画面（各確認画面への入口）。画面のテーマは、ヘッダーのテーマ切替ポップアップで切り替える（D-57）。
     *
     * 対象のブログは選択中のブログとし、URLには含めない（BLOGOS_DECISIONS.md D-02-05）。
     * 選択中のブログは ShareCurrentBlog が全画面に共有している。
     */
    public function index()
    {
        return view('settings');
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
