<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Repositories\CategoryHistoryRepository;

/**
 * カテゴリ変更履歴の一覧画面からのリクエストを処理するController。
 *
 * BlogOSでは、categoriesテーブルに保存されているカテゴリ情報が変更された際に、
 * 変更前の値・変更後の値・変更された項目・変更経路を
 * category_historiesテーブルへ履歴として保存する。
 *
 * カテゴリ情報はブログごとに管理されるため、
 * カテゴリ一覧を表示する際には、
 * 「どのブログのカテゴリを表示するのか」を
 * blog_idによって指定する。
 *
 * このControllerでは、category_historiesテーブルに登録されている
 * カテゴリ変更履歴を取得し、履歴一覧画面へ渡す処理を行う。
 *
 * 主な処理の流れは以下の通り。
 *
 * 1. URLからblog_idを受け取る
 * 2. BlogRepositoryを利用して対象ブログを取得する
 * 3. 対象ブログが存在することを確認する
 * 4. CategoryHistoryRepositoryを利用して変更履歴を取得する
 * 5. 登録されている変更履歴件数を取得する
 * 6. 変更履歴情報と件数を一覧画面へ渡す
 *
 * 変更履歴のDB取得処理そのものは
 * CategoryHistoryRepositoryへ委譲する。
 *
 * そのため、このControllerではDBへ直接アクセスせず、
 * 「一覧画面を表示するために必要なデータを取得し、Viewへ渡す」
 * というController本来の役割に集中する。
 */
class CategoryHistoryListController extends Controller
{
    /**
     * カテゴリ変更履歴情報を操作するRepository。
     *
     * category_historiesテーブルからの変更履歴取得など、
     * DBに関する処理はCategoryHistoryRepositoryへ委譲する。
     *
     * Controllerから直接Eloquentモデルを操作するのではなく、
     * Repositoryを経由することで、
     * 変更履歴の取得方法をControllerから分離する。
     */
    public function __construct(
        protected BlogRepository $blogRepository,
        protected CategoryHistoryRepository $categoryHistoryRepository
    ) {
    }

    /**
     * カテゴリ変更履歴の一覧画面を表示する。
     *
     * BlogOSに保存されているカテゴリ変更履歴を取得し、
     * カテゴリ変更履歴一覧画面へ渡す。
     *
     * 画面では、取得した変更履歴情報だけでなく、
     * 現在何件の変更履歴が登録されているかも表示できるようにする。
     *
     * 処理の流れ：
     *
     * 1. CategoryHistoryRepositoryから変更履歴をすべて取得する
     * 2. 取得した変更履歴一覧から登録件数を取得する
     * 3. 変更履歴一覧と登録件数をViewへ渡す
     *
     * @return \Illuminate\View\View
     *     カテゴリ変更履歴の一覧画面。
     */
    public function index(int $blogId)
    {
        // ==========================================================
        // 対象ブログを取得
        // ==========================================================

        // BlogRepositoryを利用して、
        // URLから受け取ったblog_idに一致するブログを
        // blogsテーブルから取得する。
        //
        // WordPress REST APIへはアクセスせず、
        // BlogOSのDBに保存されているブログ情報のみを使用する。
        $blog = $this->blogRepository->findById($blogId);

        // 指定されたblog_idに対応するブログが存在しない場合。
        //
        // 存在しないブログのカテゴリ一覧を表示することはできないため、
        // Laravelの404として処理する。
        abort_if($blog === null, 404);

        // CategoryHistoryRepositoryを利用して、
        // category_historiesテーブルに登録されている
        // カテゴリ変更履歴をすべて取得する。
        //
        // Controllerから直接CategoryHistory::all()などを実行するのではなく、
        // DB取得処理をRepositoryへ委譲する。
        //
        // これにより、将来的に
        //
        // ・取得条件を変更する
        // ・並び順を変更する
        // ・取得対象の項目を変更する
        // ・ページネーションを導入する
        // ・ブログIDなどによる絞り込みを追加する
        // ・カテゴリIDなどによる絞り込みを追加する
        //
        // といった変更が発生した場合でも、
        // DB取得処理をCategoryHistoryRepository側へ集約できる。
        $histories = $this->categoryHistoryRepository->getAll($blogId);

        // カテゴリ変更履歴一覧画面を表示する。
        //
        // Viewには以下の2つの情報を渡す。
        //
        // histories
        //     BlogOSに登録されているカテゴリ変更履歴。
        //
        // total
        //     登録されているカテゴリ変更履歴の総件数。
        //
        // 例えばcategory_historiesに20件の履歴が存在する場合、
        // totalには20が設定される。
        return view('category-history-list', [
            'blog'      => $blog,
            'histories' => $histories,
            'total'     => $histories->count(),
        ]);
    }
}
