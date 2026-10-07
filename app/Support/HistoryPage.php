<?php

namespace App\Support;

/**
 * メニューの「履歴」の画面の、1ページに出す件数（D-72-02）。
 *
 * 6画面とも、新しい順に1ページ最大50件で、ページに分けて全件を出す（表示件数の行は partials/history-count）。
 */
class HistoryPage
{
    public const PER_PAGE = 50;
}
