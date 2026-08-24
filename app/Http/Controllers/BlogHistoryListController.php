<?php

namespace App\Http\Controllers;

use App\Repositories\BlogHistoryRepository;

/**
 * ブログ変更履歴の一覧画面からのリクエストを処理するController。
 *
 * BlogOSでは、blogsテーブルに保存されているブログ情報が変更された際に、
 * 変更前の値・変更後の値・変更された項目・変更経路を
 * blog_historiesテーブルへ履歴として保存する。
 *
 * このControllerでは、blog_historiesテーブルに登録されている
 * ブログ変更履歴を取得し、履歴一覧画面へ渡す処理を行う。
 *
 * 主な処理の流れは以下の通り。
 *
 * 1. ブログ変更履歴一覧画面へのアクセスを受け取る
 * 2. BlogHistoryRepositoryを利用して変更履歴を取得する
 * 3. 登録されている変更履歴件数を取得する
 * 4. 変更履歴情報と件数を一覧画面へ渡す
 *
 * 変更履歴のDB取得処理そのものは
 * BlogHistoryRepositoryへ委譲する。
 *
 * そのため、このControllerではDBへ直接アクセスせず、
 * 「一覧画面を表示するために必要なデータを取得し、Viewへ渡す」
 * というController本来の役割に集中する。
 */
class BlogHistoryListController extends Controller
{
    /**
     * ブログ変更履歴情報を操作するRepository。
     *
     * blog_historiesテーブルからの変更履歴取得など、
     * DBに関する処理はBlogHistoryRepositoryへ委譲する。
     *
     * Controllerから直接Eloquentモデルを操作するのではなく、
     * Repositoryを経由することで、
     * 変更履歴の取得方法をControllerから分離する。
     */
    public function __construct(
        protected BlogHistoryRepository $blogHistoryRepository
    ) {
    }

    /**
     * ブログ変更履歴の一覧画面を表示する。
     *
     * BlogOSに保存されているブログ変更履歴を取得し、
     * ブログ変更履歴一覧画面へ渡す。
     *
     * 画面では、取得した変更履歴情報だけでなく、
     * 現在何件の変更履歴が登録されているかも表示できるようにする。
     *
     * 処理の流れ：
     *
     * 1. BlogHistoryRepositoryから変更履歴をすべて取得する
     * 2. 取得した変更履歴一覧から登録件数を取得する
     * 3. 変更履歴一覧と登録件数をViewへ渡す
     *
     * @return \Illuminate\View\View
     *     ブログ変更履歴の一覧画面。
     */
    public function index()
    {
        // BlogHistoryRepositoryを利用して、
        // blog_historiesテーブルに登録されている
        // ブログ変更履歴をすべて取得する。
        //
        // Controllerから直接BlogHistory::all()などを実行するのではなく、
        // DB取得処理をRepositoryへ委譲する。
        //
        // これにより、将来的に
        //
        // ・取得条件を変更する
        // ・並び順を変更する
        // ・取得対象の項目を変更する
        // ・ページネーションを導入する
        // ・ブログIDなどによる絞り込みを追加する
        //
        // といった変更が発生した場合でも、
        // DB取得処理をBlogHistoryRepository側へ集約できる。
        $histories = $this->blogHistoryRepository->getAll();

        // ブログ変更履歴一覧画面を表示する。
        //
        // Viewには以下の2つの情報を渡す。
        //
        // histories
        //     BlogOSに登録されているブログ変更履歴。
        //
        // total
        //     登録されているブログ変更履歴の総件数。
        //
        // 例えばblog_historiesに20件の履歴が存在する場合、
        // totalには20が設定される。
        return view('blog-history-list', [
            'histories' => $histories,
            'total' => $histories->count(),
        ]);
    }
}
