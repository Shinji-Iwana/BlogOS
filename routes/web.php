<?php

use Illuminate\Support\Facades\Route;
use App\Services\ThemeService;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\BlogSwitchController;
use App\Http\Middleware\ShareCurrentBlog;
use App\Http\Controllers\Database\LoginHistoryController as DatabaseLoginHistoryController;

use App\Http\Controllers\Database\BlogRegisterController as DatabaseBlogRegisterController;
use App\Http\Controllers\Database\BlogListController as DatabaseBlogListController;
use App\Http\Controllers\Database\BlogDetailController as DatabaseBlogDetailController;

use App\Http\Controllers\Database\BlogHistoryListController as DatabaseBlogHistoryListController;
use App\Http\Controllers\Database\BlogHistoryDetailController as DatabaseBlogHistoryDetailController;

use App\Http\Controllers\Database\CategoryListController as DatabaseCategoryListController;

use App\Http\Controllers\Database\CategoryHistoryListController as DatabaseCategoryHistoryListController;


use App\Http\Controllers\Database\TagHistoryListController as DatabaseTagHistoryListController;


use App\Http\Controllers\Database\MediaHistoryListController as DatabaseMediaHistoryListController;


use App\Http\Controllers\Database\StatusHistoryListController as DatabaseStatusHistoryListController;


use App\Http\Controllers\Database\TypeHistoryListController as DatabaseTypeHistoryListController;


use App\Http\Controllers\Database\TaxonomyHistoryListController as DatabaseTaxonomyHistoryListController;

use App\Http\Controllers\Database\AuthorListController as DatabaseAuthorListController;

use App\Http\Controllers\Database\AuthorHistoryListController as DatabaseAuthorHistoryListController;


use App\Http\Controllers\Database\PostHistoryListController as DatabasePostHistoryListController;


use App\Http\Controllers\Database\PageHistoryListController as DatabasePageHistoryListController;

use App\Http\Controllers\Api\BlogDetailController as ApiBlogDetailController;

use App\Http\Controllers\Api\CategoryListController as ApiCategoryListController;
use App\Http\Controllers\Api\CategoryDetailController as ApiCategoryDetailController;

use App\Http\Controllers\Api\TagListController as ApiTagListController;
use App\Http\Controllers\Api\TagDetailController as ApiTagDetailController;

use App\Http\Controllers\Api\MediaListController as ApiMediaListController;
use App\Http\Controllers\Api\MediaDetailController as ApiMediaDetailController;

use App\Http\Controllers\Api\StatusListController as ApiStatusListController;
use App\Http\Controllers\Api\StatusDetailController as ApiStatusDetailController;

use App\Http\Controllers\Api\TypeListController as ApiTypeListController;
use App\Http\Controllers\Api\TypeDetailController as ApiTypeDetailController;

use App\Http\Controllers\Api\TaxonomyListController as ApiTaxonomyListController;
use App\Http\Controllers\Api\TaxonomyDetailController as ApiTaxonomyDetailController;

use App\Http\Controllers\Api\AuthorListController as ApiAuthorListController;
use App\Http\Controllers\Api\AuthorDetailController as ApiAuthorDetailController;

use App\Http\Controllers\Api\PostListController as ApiPostListController;
use App\Http\Controllers\Api\PostDetailController as ApiPostDetailController;

use App\Http\Controllers\Api\PageListController as ApiPageListController;
use App\Http\Controllers\Api\PageDetailController as ApiPageDetailController;

use App\Http\Controllers\Api\SiteSearchController as ApiSiteSearchController;
use App\Http\Controllers\Api\AnalyticsInfoController as ApiAnalyticsInfoController;
use App\Http\Controllers\Api\SearchConsoleInfoController as ApiSearchConsoleInfoController;
use App\Http\Controllers\Api\AdsenseOAuthController;
use App\Http\Controllers\Api\AdsenseInfoController as ApiAdsenseInfoController;

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
 *    ・設定
 *    ・各種API確認
 *
 * 認証必須の画面については、
 * authミドルウェアによってログイン済みユーザーだけが
 * アクセスできるようにする。
 *
 * また、認証必須ルートにはShareCurrentBlogを適用し、
 * 全ページ共通ヘッダーで使用する
 *
 * ・blogs
 * ・selectedBlog
 *
 * をViewへ共有する。
 *
 * ==========================================================
 */


/**
 * ==========================================================
 * ログイン関連ルート
 * ----------------------------------------------------------
 * 認証不要
 * ==========================================================
 */

Route::get(
    '/login',
    [LoginController::class, 'showLoginForm']
)->name('login');

Route::post(
    '/login',
    [LoginController::class, 'login']
);

Route::post(
    '/logout',
    [LoginController::class, 'logout']
)->name('logout');


/**
 * ==========================================================
 * 認証必須ルート
 * ----------------------------------------------------------
 * auth + ShareCurrentBlog
 * ==========================================================
 *
 * auth：
 *     ログイン済みユーザーのみアクセス可能にする。
 *
 * ShareCurrentBlog：
 *     全ページ共通ヘッダーで使用する
 *     ブログ一覧と現在選択中ブログをViewへ共有する。
 *
 * ==========================================================
 */

Route::middleware([
    'auth',
    ShareCurrentBlog::class,
])->group(function () {

    /**
     * ======================================================
     * トップページ
     * ======================================================
     */

    Route::get(
        '/',
        [DashboardController::class, 'index']
    )->name('home');


    /**
     * ======================================================
     * ブログ切替
     * ------------------------------------------------------
     * 共通ヘッダーのブログ切替ポップアップから
     * 選択されたblog_idを受け取る。
     *
     * 切替後はトップページへリダイレクトする。
     * ======================================================
     */

    Route::post(
        '/blog-switch',
        [BlogSwitchController::class, 'switch']
    )->name('blog-switch');


    /**
     * ======================================================
     * ブログ管理
     * ======================================================
     */

    Route::get(
        '/database/blog-register',
        [DatabaseBlogRegisterController::class, 'index']
    )->name('database-blog-register');

    Route::post(
        '/database/blog-register/check',
        [DatabaseBlogRegisterController::class, 'check']
    )->name('database-blog-register.check');

    Route::post(
        '/database/blog-register/store',
        [DatabaseBlogRegisterController::class, 'store']
    )->name('database-blog-register.store');

    Route::get(
        '/database/blog-list',
        [DatabaseBlogListController::class, 'index']
    )->name('database-blog-list');

    Route::get(
        '/database/blog-detail/{id}',
        [DatabaseBlogDetailController::class, 'show']
    )->name('database-blog-detail');

    Route::get(
        '/database/blog-history-list',
        [DatabaseBlogHistoryListController::class, 'index']
    )->name('database-blog-history-list');


    /**
     * ======================================================
     * カテゴリ
     * ======================================================
     */

    Route::get(
        '/database/category-list',
        [DatabaseCategoryListController::class, 'index']
    )->name('database-category-list');

    Route::get(
        '/database/category-history-list',
        [DatabaseCategoryHistoryListController::class, 'index']
    )->name('database-category-history-list');


    /**
     * ======================================================
     * 記事一覧・詳細
     * ======================================================
     */

    Route::get(
        '/api/post-list',
        [ApiPostListController::class, 'index']
    )->name('post-list');

    Route::get(
        '/api/post-detail/{id}',
        [ApiPostDetailController::class, 'index']
    )->name('api-post-detail');


    /**
     * ======================================================
     * 固定ページ一覧・詳細
     * ======================================================
     */

    Route::get(
        '/api/page-list',
        [ApiPageListController::class, 'index']
    )->name('api-page-list');

    Route::get(
        '/api/page-detail/{id}',
        [ApiPageDetailController::class, 'index']
    )->name('api-page-detail');


    /**
     * ======================================================
     * カテゴリ・タグ・メディア
     * ======================================================
     */

    Route::get(
        '/api/category-list',
        [ApiCategoryListController::class, 'index']
    )->name('api-category-list');

    Route::get(
        '/api/category-detail/{categoryId}',
        [ApiCategoryDetailController::class, 'index']
    )->name('api-category-detail');

    Route::get(
        '/api/tag-list',
        [ApiTagListController::class, 'index']
    )->name('api-tag-list');

    Route::get(
        '/api/media-list',
        [ApiMediaListController::class, 'index']
    )->name('api-media-list');


    /**
     * ======================================================
     * 設定
     * ------------------------------------------------------
     * 現在選択中のブログはSettingsController側で取得する。
     *
     * そのため、URLにはblogIdを含めない。
     * ======================================================
     */

    Route::get(
        '/settings',
        [SettingsController::class, 'index']
    )->name('settings');

    /**
     * ======================================================
     * ログイン履歴（DB確認画面）
     * ------------------------------------------------------
     * ルート名は新しい規則（機能.操作。D-11-04）に従う。
     * ======================================================
     */
    Route::get(
        '/database/login-histories',
        [DatabaseLoginHistoryController::class, 'index']
    )->name('database.login-histories.index');


    /**
     * ======================================================
     * サイト情報・ステータス・タイプ・タクソノミー・ユーザー
     * ======================================================
     */

    Route::get(
        '/api/blog-detail',
        [ApiBlogDetailController::class, 'index']
    )->name('api-blog-detail');

    Route::get(
        '/api/status-list',
        [ApiStatusListController::class, 'index']
    )->name('api-status-list');

    Route::get(
        '/api/type-list',
        [ApiTypeListController::class, 'index']
    )->name('api-type-list');

    Route::get(
        '/api/taxonomy-list',
        [ApiTaxonomyListController::class, 'index']
    )->name('api-taxonomy-list');

    Route::get(
        '/api/author-list',
        [ApiAuthorListController::class, 'index']
    )->name('api-author-list');


    /**
     * ======================================================
     * サイト内検索
     * ======================================================
     */

    Route::get(
        '/api/site-search',
        [ApiSiteSearchController::class, 'index']
    )->name('api-site-search');


    /**
     * ======================================================
     * Analytics
     * ======================================================
     */

    Route::get(
        '/api/analytics-info',
        [ApiAnalyticsInfoController::class, 'index']
    )->name('api-analytics-info');

    Route::get(
        '/api/analytics-catalog',
        [ApiAnalyticsInfoController::class, 'catalog']
    )->name('api-analytics-catalog');


    /**
     * ======================================================
     * Search Console
     * ======================================================
     */

    Route::get(
        '/api/search-console-info',
        [ApiSearchConsoleInfoController::class, 'index']
    )->name('api-search-console-info');


    /**
     * ======================================================
     * AdSense
     * ======================================================
     */

    Route::get(
        '/adsense/oauth/redirect',
        [AdsenseOAuthController::class, 'redirect']
    )->name('adsense.oauth.redirect');

    Route::get(
        '/adsense/oauth/callback',
        [AdsenseOAuthController::class, 'callback']
    )->name('adsense.oauth.callback');

    Route::get(
        '/api/adsense-info',
        [ApiAdsenseInfoController::class, 'index']
    )->name('api-adsense-info');
});
