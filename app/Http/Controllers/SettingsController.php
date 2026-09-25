<?php

namespace App\Http\Controllers;

class SettingsController extends Controller
{
    /**
     * 設定画面（各確認画面への入口）。
     *
     * 対象のブログは選択中のブログとし、URLには含めない（BLOGOS_DECISIONS.md D-02-05）。
     * 選択中のブログは ShareCurrentBlog が全画面に共有している。
     */
    public function index()
    {
        return view('settings');
    }
}
