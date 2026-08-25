<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;
use App\Repositories\CategoryRepository;

/**
 * カテゴリ一覧画面からのリクエストを処理するController。
 *
 * BlogOSでは、複数のWordPressブログを管理対象として登録できる。
 *
 * カテゴリ情報はブログごとに管理されるため、
 * カテゴリ一覧を表示する際には、
 * 「どのブログのカテゴリを表示するのか」を
 * blog_idによって指定する。
 *
 * このControllerでは、指定されたblog_idを基に、
 *
 * ・対象ブログ情報
 * ・対象ブログのカテゴリ情報
 *
 * をDBから取得し、カテゴリ一覧画面へ渡す。
 *
 * WordPress REST APIへ直接アクセスすることはなく、
 * BlogOSのDBに保存されている情報のみを使用する。
 *
 * 主な処理の流れは以下の通り。
 *
 * 1. URLからblog_idを受け取る
 * 2. BlogRepositoryを利用して対象ブログを取得する
 * 3. 対象ブログが存在することを確認する
 * 4. CategoryRepositoryを利用して対象ブログのカテゴリを取得する
 * 5. 対象ブログ情報とカテゴリ情報をViewへ渡す
 *
 * ブログ情報のDB取得はBlogRepository、
 * カテゴリ情報のDB取得はCategoryRepositoryへ委譲する。
 *
 * ControllerではDBへ直接アクセスせず、
 * 「一覧画面を表示するために必要なデータを取得し、
 * Viewへ渡す」という役割に集中する。
 */
class CategoryListController extends Controller
{
    /**
     * ブログ情報を操作するRepository。
     *
     * blogsテーブルから対象ブログを取得するなど、
     * ブログに関するDB処理はBlogRepositoryへ委譲する。
     */
    public function __construct(
        protected BlogRepository $blogRepository,

        /**
         * カテゴリ情報を操作するRepository。
         *
         * categoriesテーブルから対象ブログの
         * カテゴリ情報を取得するなど、
         * カテゴリに関するDB処理はCategoryRepositoryへ委譲する。
         */
        protected CategoryRepository $categoryRepository
    ) {
    }

    /**
     * 指定されたブログのカテゴリ一覧画面を表示する。
     *
     * URLから受け取ったblog_idを使用して、
     * 対象ブログと、そのブログに紐付くカテゴリを
     * DBから取得する。
     *
     * @param int $blogId
     *     カテゴリ一覧を表示する対象ブログのID。
     *
     *     blogs.idを指定する。
     *
     * @return \Illuminate\View\View
     *     指定ブログのカテゴリ一覧画面。
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     *     指定されたブログが存在しない場合。
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

        // ==========================================================
        // 対象ブログのカテゴリを取得
        // ==========================================================

        // CategoryRepositoryを利用して、
        // 対象ブログに紐付くカテゴリを取得する。
        //
        // 第1引数にblog_idを渡すことで、
        // 他のブログのカテゴリが混ざらないようにする。
        //
        // ここではWordPress REST APIへアクセスせず、
        // categoriesテーブルに保存されている情報を使用する。
        $categories = $this->categoryRepository->getAll($blogId);

        // ==========================================================
        // カテゴリ一覧画面を表示
        // ==========================================================

        // Viewへ以下の情報を渡す。
        //
        // blog
        //     現在選択されているブログ情報。
        //
        // categories
        //     そのブログに登録されているカテゴリ一覧。
        //
        // total
        //     そのブログに登録されているカテゴリ総件数。
        return view('category-list', [
            'blog'       => $blog,
            'categories' => $categories,
            'total'      => $categories->count(),
        ]);
    }
}
