<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;

/**
 * BlogOSのトップページからのリクエストを処理するController。
 *
 * BlogOSでは、ログイン後に表示するトップページにおいて、
 * 現在操作対象とするブログを選択できるようにする。
 *
 * このControllerでは、blogsテーブルに登録されているブログ情報を取得し、
 * トップページViewへ渡す処理を行う。
 *
 * 主な処理の流れは以下の通り。
 *
 * 1. トップページへのアクセスを受け取る
 * 2. BlogRepositoryを利用して登録済みブログをすべて取得する
 * 3. 取得したブログ情報をトップページViewへ渡す
 *
 * ブログ情報のDB取得処理そのものはBlogRepositoryへ委譲する。
 *
 * そのため、このControllerではDBへ直接アクセスせず、
 * 「トップページを表示するために必要なデータを取得し、Viewへ渡す」
 * というController本来の役割に集中する。
 *
 * トップページでは、取得したブログ一覧を利用して
 * 「対象ブログ」の選択リストを表示する。
 *
 * 選択リストではブログ名を表示しながら、
 * 選択されたブログのIDをBlogOS内部で保持できるようにする。
 */
class DashboardController extends Controller
{
    /**
     * ブログ情報を操作するRepository。
     *
     * blogsテーブルからのブログ情報取得など、
     * DBに関する処理はBlogRepositoryへ委譲する。
     *
     * Controllerから直接Eloquentモデルを操作するのではなく、
     * Repositoryを経由することで、
     * ブログ情報の取得方法をControllerから分離する。
     */
    public function __construct(
        protected BlogRepository $blogRepository
    ) {
    }

    /**
     * BlogOSのトップページを表示する。
     *
     * BlogOSに登録されているブログをすべて取得し、
     * トップページViewへ渡す。
     *
     * トップページでは、取得したブログ一覧を利用して
     * 「対象ブログ」の選択リストを表示する。
     *
     * blogsテーブルに複数のブログが登録されている場合は、
     * すべてのブログを選択肢として表示する。
     *
     * 選択リストの先頭にあるブログをデフォルト選択状態とする。
     *
     * blogsテーブルにブログが1件も登録されていない場合は、
     * $blogsが空のCollectionとなるため、
     * View側でブログ選択リストを表示せず、
     * ブログ登録画面への案内のみを表示する。
     *
     * 処理の流れ：
     *
     * 1. BlogRepositoryから登録済みブログをすべて取得する
     * 2. 取得したブログ一覧をトップページViewへ渡す
     *
     * @return \Illuminate\View\View
     *     BlogOSのトップページ。
     */
    public function index()
    {
        // BlogRepositoryを利用して、
        // blogsテーブルに登録されているブログをすべて取得する。
        //
        // Controllerから直接Blog::all()などを実行するのではなく、
        // DB取得処理をRepositoryへ委譲する。
        //
        // これにより、ブログの取得条件や並び順などを変更する場合でも、
        // DB取得処理をBlogRepository側へ集約できる。
        $blogs = $this->blogRepository->getAll();

        // 現在使用しているテーマのトップページViewを表示する。
        //
        // ThemeService::index()を利用することで、
        // 現在設定されているテーマに応じた
        // トップページViewを取得する。
        //
        // $blogsをViewへ渡すことで、
        // テーマ側のトップページから
        // 「対象ブログ」の選択リストを生成できるようにする。
        return view(\App\Services\ThemeService::index(), [
            'blogs' => $blogs,
        ]);
    }
}
