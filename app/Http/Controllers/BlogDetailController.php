<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;

/**
 * 登録済みブログの詳細画面からのリクエストを処理するController。
 *
 * BlogOSでは、複数のWordPressブログを管理対象として登録できる。
 *
 * このControllerでは、URLなどから受け取ったブログIDをもとに、
 * blogsテーブルから該当するブログ情報を1件取得し、
 * ブログ詳細画面へ渡す処理を行う。
 *
 * 主な処理の流れは以下の通り。
 *
 * 1. ブログ詳細画面へのアクセスを受け取る
 * 2. リクエストからブログIDを受け取る
 * 3. BlogRepositoryを利用して該当するブログを取得する
 * 4. 取得したブログ情報を詳細画面へ渡す
 *
 * ブログ情報のDB取得処理そのものはBlogRepositoryへ委譲する。
 *
 * そのため、このControllerではDBへ直接アクセスせず、
 * 「指定されたブログの情報を取得し、詳細画面へ渡す」
 * というController本来の役割に集中する。
 *
 * なお、指定されたブログIDが存在しない場合、
 * BlogRepository::findById()からnullが返される。
 *
 * このControllerではnullをそのままViewへ渡し、
 * ブログが存在しない場合の画面表示については
 * blog-detail.blade.php側で制御する。
 */
class BlogDetailController extends Controller
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
     * 指定されたブログの詳細画面を表示する。
     *
     * URLなどから受け取ったブログIDを使用して、
     * blogsテーブルから該当するブログ情報を1件取得する。
     *
     * DBからのブログ情報取得処理はBlogRepositoryへ委譲し、
     * 取得したBlog Modelをブログ詳細画面へ渡す。
     *
     * BlogRepositoryのfindById()では、
     * 指定されたIDに該当するブログが存在しない場合、
     * nullが返される。
     *
     * このControllerでは、取得結果をそのままViewへ渡す。
     *
     * そのため、ブログが存在しない場合の画面上の扱いについては、
     * blog-detail.blade.php側で制御する。
     *
     * 処理の流れ：
     *
     * 1. リクエストからブログIDを受け取る
     * 2. BlogRepositoryへブログIDを渡す
     * 3. 指定されたブログ情報を取得する
     * 4. 取得したBlog Modelをブログ詳細画面へ渡す
     *
     * @param int $id
     *     詳細を表示するブログのID（blogs.id）。
     *
     * @return \Illuminate\View\View
     *     指定されたブログの詳細画面。
     */
    public function show(int $id)
    {
        // 指定されたブログIDをBlogRepositoryへ渡し、
        // blogsテーブルから該当するブログを1件取得する。
        //
        // Controllerから直接Blog::find($id)を実行するのではなく、
        // ブログ情報の取得処理をRepositoryへ委譲する。
        //
        // これにより、ブログ情報の取得方法を
        // Controllerから切り離すことができる。
        //
        // 将来的に、
        //
        // ・取得条件を変更する
        // ・取得対象の項目を変更する
        // ・関連するテーブルの情報も取得する
        // ・取得方法を変更する
        //
        // といった変更が発生した場合でも、
        // DB取得処理をBlogRepository側へ集約できる。
        //
        // 該当するブログが存在する場合はBlog Modelが返され、
        // 存在しない場合はnullが返される。
        $blog = $this->blogRepository->findById($id);

        // 取得したブログ情報をブログ詳細画面へ渡す。
        //
        // Viewには「blog」という名前で、
        // 取得したBlog Modelを渡す。
        //
        // View側では$blogを使用して、
        //
        // ・ID
        // ・サイト名
        // ・説明
        // ・URL
        // ・ホームURL
        // ・GMTオフセット
        // ・タイムゾーン
        // ・登録日時
        // ・更新日時
        //
        // などのブログ詳細情報を表示する。
        //
        // 指定されたブログが存在しない場合は
        //$blogがnullになる。
        //
        // その場合の表示内容については、
        // blog-detail.blade.php側で存在チェックを行う。
        return view('blog-detail', [
            'blog' => $blog,
        ]);
    }
}
