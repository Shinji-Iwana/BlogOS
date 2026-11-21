<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * 表で出す一覧の、ページ送り（全画面で共通。D-98-01）。
 *
 * 表で出す一覧は、全て1ページ最大50件で、ページに分けて全件を出す。
 * 表の上には、表示件数の行（partials/history-count）を出す。
 * DB から読む一覧は ->paginate(ListPage::PER_PAGE, ['*'], $pageName)->withQueryString()、
 * 画面のために集めた配列・コレクションは ListPage::of() でページに分ける。
 * 1つの画面に表が複数あるときは、表ごとに別のページの名前（$pageName）を使う。
 */
class ListPage
{
    public const PER_PAGE = 50;

    /**
     * 配列・コレクションを、今のページの分に分ける（キーは保つ。フォームの行の番号に使う画面があるため）
     *
     * @param  iterable<array-key, mixed>  $items
     */
    public static function of(iterable $items, string $pageName = 'page'): LengthAwarePaginator
    {
        $items = $items instanceof Collection ? $items : collect($items);
        $page = Paginator::resolveCurrentPage($pageName);

        return (new LengthAwarePaginator($items->forPage($page, self::PER_PAGE), $items->count(), self::PER_PAGE, $page, [
            'path'     => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]))->withQueryString();
    }
}
