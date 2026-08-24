<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;

/**
 * 登録済みブログの一覧画面からのリクエストを処理するController。
 *
 * BlogOSでは、複数のWordPressブログを管理対象として登録できる。
 *
 * このControllerでは、blogsテーブルに登録されているブログ情報を取得し、
 * ブログ一覧画面へ渡す処理を行う。
 *
 * 主な処理の流れは以下の通り。
 *
 * 1. ブログ一覧画面へのアクセスを受け取る
 * 2. BlogRepositoryを利用して登録済みブログを取得する
 * 3. 登録されているブログ件数を取得する
 * 4. ブログ情報と件数を一覧画面へ渡す
 *
 * ブログ情報のDB取得処理そのものはBlogRepositoryへ委譲する。
 *
 * そのため、このControllerではDBへ直接アクセスせず、
 * 「一覧画面を表示するために必要なデータを取得し、Viewへ渡す」
 * というController本来の役割に集中する。
 */
class BlogListController extends Controller
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
     * 登録済みブログの一覧画面を表示する。
     *
     * BlogOSに登録されているブログを取得し、
     * ブログ一覧画面へ渡す。
     *
     * 画面では、取得したブログ情報だけでなく、
     * 現在何件のブログが登録されているかも表示できるようにする。
     *
     * 処理の流れ：
     *
     * 1. BlogRepositoryから登録済みブログをすべて取得する
     * 2. 取得したブログ一覧から登録件数を取得する
     * 3. ブログ一覧と登録件数をViewへ渡す
     *
     * @return \Illuminate\View\View
     *     登録済みブログの一覧画面。
     */
    public function index()
    {
        // BlogRepositoryを利用して、
        // blogsテーブルに登録されているブログをすべて取得する。
        //
        // Controllerから直接Blog::all()などを実行するのではなく、
        // DB取得処理をRepositoryへ委譲する。
        //
        // これにより、将来的に
        //
        // ・取得条件を変更する
        // ・並び順を変更する
        // ・取得対象の項目を変更する
        // ・ページネーションを導入する
        //
        // といった変更が発生した場合でも、
        // DB取得処理をBlogRepository側へ集約できる。
        $blogs = $this->blogRepository->getAll();

        // ブログ一覧画面を表示する。
        //
        // Viewには以下の2つの情報を渡す。
        //
        // blogs
        //     BlogOSに登録されているブログ情報。
        //
        // total
        //     登録されているブログの総件数。
        //
        // 例えばblogsに10件のブログが存在する場合、
        // totalには10が設定される。
        return view('blog-list', [
            'blogs' => $blogs,
            'total' => $blogs->count(),
        ]);
    }
}
