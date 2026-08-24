<?php

use Illuminate\Support\Facades\Route;
use App\Services\ThemeService;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BlogRegisterController;
use App\Http\Controllers\BlogListController;
use App\Http\Controllers\BlogDetailController;
use App\Http\Controllers\BlogHistoryListController;

/**
 * ==========================================================
 * BlogOS Webルート定義
 * ==========================================================
 *
 * このファイルでは、ブラウザからBlogOSへアクセスした際の
 * URLと、それに対応する処理を定義する。
 *
 * 基本的には、
 *
 *     URL
 *       ↓
 *     Route
 *       ↓
 *     Controller / 処理
 *       ↓
 *     View
 *
 * という流れで、ユーザーからのリクエストを
 * BlogOS内部の処理へ振り分ける。
 *
 * BlogOSでは、以下のように大きく2種類のルートに分けている。
 *
 * 1. 認証不要のルート
 *    ・ログイン画面
 *    ・ログイン処理
 *    ・ログアウト処理
 *
 * 2. 認証必須のルート
 *    ・トップページ
 *    ・ブログ登録
 *    ・ブログ一覧
 *    ・ブログ詳細
 *
 * 認証必須の画面については、
 * authミドルウェアによってログイン済みユーザーだけが
 * アクセスできるようにする。
 *
 * ==========================================================
 */


/**
 * ==========================================================
 * 使用するクラス
 * ==========================================================
 *
 * Route
 *     Laravelのルート定義を行うために使用する。
 *
 * ThemeService
 *     BlogOSで使用するテーマを管理するService。
 *     トップページで使用するViewを取得するために使用する。
 *
 * LoginController
 *     ログイン画面の表示、
 *     ログイン処理、
 *     ログアウト処理を担当するController。
 *
 * BlogRegisterController
 *     WordPressブログの登録処理を担当するController。
 *
 * BlogListController
 *     BlogOSに登録されているブログ一覧の表示を担当するController。
 *
 * BlogDetailController
 *     指定されたブログの詳細画面の表示を担当するController。
 *
 * ==========================================================
 */


/**
 * ==========================================================
 * ログイン関連ルート
 * ----------------------------------------------------------
 * 認証不要
 * ==========================================================
 *
 * まだBlogOSへログインしていないユーザーでも
 * アクセスできるルート。
 *
 * ログイン処理そのものはLoginControllerへ委譲する。
 */


/**
 * ----------------------------------------------------------
 * ログイン画面表示
 * ----------------------------------------------------------
 *
 * GET /login
 *
 * ブラウザからログイン画面へアクセスした場合に、
 * LoginControllerのshowLoginForm()を呼び出す。
 *
 * ルート名：
 *
 *     login
 *
 * Blade側などから、
 *
 *     route('login')
 *
 * のようにURLを生成する際に使用できる。
 */
Route::get(
    '/login',
    [LoginController::class, 'showLoginForm']
)->name('login');


/**
 * ----------------------------------------------------------
 * ログイン処理
 * ----------------------------------------------------------
 *
 * POST /login
 *
 * ログイン画面から送信された認証情報を受け取り、
 * LoginControllerのlogin()を実行する。
 *
 * GETではなくPOSTを使用することで、
 * ログイン情報を登録・送信する処理であることを明確にする。
 *
 * このルートにはルート名を設定していない。
 */
Route::post(
    '/login',
    [LoginController::class, 'login']
);


/**
 * ----------------------------------------------------------
 * ログアウト処理
 * ----------------------------------------------------------
 *
 * POST /logout
 *
 * 現在ログインしているユーザーをログアウトさせる。
 *
 * ルート名：
 *
 *     logout
 *
 * ログアウトボタンなどから、
 *
 *     route('logout')
 *
 * のようにURLを生成する際に使用できる。
 *
 * ログアウトは状態を変更する処理であるため、
 * POSTリクエストとして定義している。
 */
Route::post(
    '/logout',
    [LoginController::class, 'logout']
)->name('logout');


/**
 * ==========================================================
 * 認証必須ルート
 * ----------------------------------------------------------
 * authミドルウェア
 * ==========================================================
 *
 * ここから下に定義するルートは、
 * ログイン済みユーザーのみがアクセスできる。
 *
 * middleware('auth')によって、
 * 未ログイン状態でアクセスした場合は
 * Laravelの認証処理へ制御が渡される。
 *
 * BlogOSでは、
 *
 *     トップページ
 *     ブログ登録
 *     ブログ一覧
 *     ブログ詳細
 *
 * など、BlogOS内部の管理機能を
 * ログインユーザーだけが利用できるようにする。
 *
 * ==========================================================
 */
Route::middleware('auth')->group(function () {


    /**
     * ======================================================
     * BlogOSトップページ
     * ======================================================
     *
     * GET /
     *
     * BlogOSへログインしたユーザーが
     * 最初にアクセスするトップページ。
     *
     * トップページのViewはThemeServiceから取得する。
     *
     * ThemeService::index()
     *     ↓
     * 現在使用するテーマのトップページView名を取得
     *     ↓
     * view()
     *     ↓
     * トップページを表示
     *
     * このように、ルート側で特定のテーマ名を直接指定せず、
     * ThemeServiceへテーマ管理を委譲している。
     *
     * そのため、将来的にBlogOSのテーマを変更する場合でも、
     * このルート自体を変更せずに対応できる構成を想定している。
     */
    Route::get('/', function () {

        // 現在使用するテーマのトップページViewを取得し、
        // BlogOSのトップページとして表示する。
        return view(ThemeService::index());
    });


    /**
     * ======================================================
     * ブログ登録機能
     * ======================================================
     *
     * WordPressブログをBlogOSの管理対象として
     * 登録するためのルート群。
     *
     * ブログ登録では、
     *
     * 1. 登録画面を表示する
     * 2. WordPress REST APIへの接続確認を行う
     * 3. 取得したブログ情報をDBへ登録する
     *
     * という複数の処理を行う。
     *
     * それぞれの処理を個別のルートとして定義する。
     */


    /**
     * ------------------------------------------------------
     * ブログ登録画面
     * ------------------------------------------------------
     *
     * GET /blog-register
     *
     * ブログ登録画面を表示する。
     *
     * BlogRegisterControllerのindex()が
     * 画面表示を担当する。
     *
     * ルート名：
     *
     *     blog-register
     *
     * Blade側から、
     *
     *     route('blog-register')
     *
     * のようにURLを生成できる。
     */
    Route::get(
        '/blog-register',
        [BlogRegisterController::class, 'index']
    )->name('blog-register');


    /**
     * ------------------------------------------------------
     * WordPress REST API接続確認
     * ------------------------------------------------------
     *
     * POST /blog-register/check
     *
     * ブログ登録画面で入力されたWordPressブログのURLを受け取り、
     * WordPress REST APIへの接続確認を行う。
     *
     * BlogRegisterControllerのcheck()が処理を担当する。
     *
     * ここではブログをDBへ登録するのではなく、
     * WordPress APIからブログ基本情報を取得できるかを
     * 確認するために使用する。
     *
     * ルート名：
     *
     *     blog-register.check
     *
     * blog-register.blade.phpのJavaScriptから
     *
     *     route('blog-register.check')
     *
     * としてURLを取得し、POSTリクエストを送信する。
     */
    Route::post(
        '/blog-register/check',
        [BlogRegisterController::class, 'check']
    )->name('blog-register.check');


    /**
     * ------------------------------------------------------
     * ブログ登録処理
     * ------------------------------------------------------
     *
     * POST /blog-register/store
     *
     * 接続確認によって取得したブログ情報を受け取り、
     * BlogOSのblogsテーブルへ登録する。
     *
     * BlogRegisterControllerのstore()が
     * 登録処理を担当する。
     *
     * ただし、既に同じブログが登録されている場合は、
     * DBの状態とWordPress APIから取得した情報を比較し、
     * 差分がある場合には確認処理を行う。
     *
     * そのため、単純に常に新規INSERTを行うルートではなく、
     * Controller側で登録・既存・差分更新などを判定する。
     *
     * ルート名：
     *
     *     blog-register.store
     *
     * blog-register.blade.phpのJavaScriptから
     *
     *     route('blog-register.store')
     *
     * としてURLを取得し、POSTリクエストを送信する。
     */
    Route::post(
        '/blog-register/store',
        [BlogRegisterController::class, 'store']
    )->name('blog-register.store');


    /**
     * ======================================================
     * ブログ一覧
     * ======================================================
     *
     * GET /blog-list
     *
     * BlogOSへ登録されているブログを一覧表示する。
     *
     * BlogListControllerのindex()が処理を担当する。
     *
     * ControllerではBlogRepositoryを利用して
     * blogsテーブルから登録済みブログを取得し、
     * ブログ一覧Viewへ渡す。
     *
     * ルート名：
     *
     *     blog-list
     *
     * ブログ詳細画面から一覧画面へ戻る場合などに、
     *
     *     route('blog-list')
     *
     * としてURLを生成できる。
     */
    Route::get(
        '/blog-list',
        [BlogListController::class, 'index']
    )->name('blog-list');


    /**
     * ======================================================
     * ブログ詳細
     * ======================================================
     *
     * GET /blog-detail/{id}
     *
     * 一覧画面から選択されたブログの詳細情報を表示する。
     *
     * {id}にはblogsテーブルのIDが入る。
     *
     * 例えば、
     *
     *     /blog-detail/1
     *
     * にアクセスした場合、
     * ブログID「1」の詳細画面を表示する。
     *
     * BlogDetailControllerのshow()が処理を担当する。
     *
     * Controllerでは受け取ったIDをBlogRepositoryへ渡し、
     * blogsテーブルから該当するブログ情報を取得する。
     *
     * 該当するブログが存在しない場合は、
     * Repositoryからnullが返される。
     *
     * その場合の画面表示については、
     * 詳細画面のView側で制御する。
     *
     * ルート名：
     *
     *     blog-detail
     *
     * ただし、このルートへ遷移する際は、
     * ブログ一覧画面のIDリンクなどから
     * ブログIDを指定してURLを生成する。
     *
     * 例えばBlade側では、
     *
     *     route('blog-detail', $blog->id)
     *
     * のように使用できる。
     */
    Route::get(
        '/blog-detail/{id}',
        [BlogDetailController::class, 'show']
    )->name('blog-detail');

    /**
     * ======================================================
     * ブログ変更履歴一覧
     * ======================================================
     *
     * GET /blog-history-list
     *
     * BlogOSに保存されているブログ変更履歴を一覧表示する。
     *
     * BlogHistoryListControllerのindex()が処理を担当する。
     *
     * ControllerではBlogHistoryRepositoryを利用して
     * blog_historiesテーブルから変更履歴を取得し、
     * ブログ変更履歴一覧Viewへ渡す。
     *
     * ルート名：
     *
     *     blog-history-list
     *
     * ブログ変更履歴を確認する場合などに、
     *
     *     route('blog-history-list')
     *
     * としてURLを生成できる。
     */
    Route::get(
        '/blog-history-list',
        [BlogHistoryListController::class, 'index']
    )->name('blog-history-list');
});
