<?php

use App\Http\Controllers\Ai\AiBatchController;
use App\Http\Controllers\Ai\AiCreditController;
use App\Http\Controllers\Ai\AiGenerationController;
use App\Http\Controllers\Ai\AiPriceController;
use App\Http\Controllers\Ai\AiSettingsController;
use App\Http\Controllers\Analytics\AnalyticsController;
use App\Http\Controllers\Api\SiteSearchController as ApiSiteSearchController;
use App\Http\Controllers\Api\SyncStatusController as ApiSyncStatusController;
use App\Http\Controllers\Articles\ArticleController;
use App\Http\Controllers\Articles\ArticleManagementController;
use App\Http\Controllers\Articles\ManagementSuggestionController;
use App\Http\Controllers\Articles\ArticleTrashController;
use App\Http\Controllers\Articles\DraftConflictController;
use App\Http\Controllers\Articles\DraftController;
use App\Http\Controllers\Articles\DraftPreviewController;
use App\Http\Controllers\Articles\DraftPushController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Blogs\BlogCredentialController;
use App\Http\Controllers\Blogs\BlogRegistrationController;
use App\Http\Controllers\BlogSwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Database\BlogDetailController as DatabaseBlogDetailController;
use App\Http\Controllers\Database\BlogHistoryListController as DatabaseBlogHistoryListController;
use App\Http\Controllers\Database\BlogListController as DatabaseBlogListController;
use App\Http\Controllers\Database\LoginHistoryController as DatabaseLoginHistoryController;
use App\Http\Controllers\Database\SyncRunController as DatabaseSyncRunController;
use App\Http\Controllers\Database\WordPressRecordController as DatabaseWordPressRecordController;
use App\Http\Controllers\Google\GoogleOAuthController;
use App\Http\Controllers\Google\GoogleSettingsController;
use App\Http\Controllers\Images\EyecatchController;
use App\Http\Controllers\Images\ImageController;
use App\Http\Controllers\Materials\MaterialAiController;
use App\Http\Controllers\Materials\MaterialController;
use App\Http\Controllers\Materials\AffiliateProgramController;
use App\Http\Controllers\Materials\MaterialReviewController;
use App\Http\Controllers\Materials\MaterialSuggestionController;
use App\Http\Controllers\Push\PushOperationController;
use App\Http\Controllers\Quality\EvaluationController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Terms\TermController;
use App\Http\Controllers\Sync\SyncIssueController;
use App\Http\Controllers\Sync\SyncRunController;
use App\Http\Controllers\WordPressApi\ResourceController as WordPressApiResourceController;
use App\Http\Middleware\EnsureSelectedBlog;
use App\Http\Middleware\ShareCurrentBlog;
use Illuminate\Support\Facades\Route;

/**
 * ==========================================================
 * BlogOS Webルート定義
 * ==========================================================
 *
 * ルート名の規則（D-11-04）：
 *   新しく作るルートは「機能.操作」のドット区切りとする（例：blogs.create、wp-api.resources.index）。
 *   旧い規則（ハイフン区切り）のルートは、作り直す段階で新しい規則に移す。
 *
 * 対象のブログはURLに含めず、選択中のブログ（blogs.is_selected）とする（D-02-05）。
 * 更新系のルートには EnsureSelectedBlog を付け、画面を開いた時点のブログと一致することを確認する。
 */

// ログイン（認証不要）
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// 認証必須（D-03-01）。ShareCurrentBlog は、ブログ一覧と選択中のブログを全画面に共有する
Route::middleware(['auth', ShareCurrentBlog::class])->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('home');

    Route::post('/blog-switch', [BlogSwitchController::class, 'switch'])->name('blog-switch');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');

    // ブログの登録（WORDPRESS_API 29章）
    Route::get('/blogs/create', [BlogRegistrationController::class, 'create'])->name('blogs.create');
    Route::post('/blogs', [BlogRegistrationController::class, 'store'])->name('blogs.store');

    // 選択中のブログの認証情報（D-03-02、D-03-03）
    Route::get('/blogs/credentials', [BlogCredentialController::class, 'edit'])->name('blogs.credentials.edit');

    Route::middleware(EnsureSelectedBlog::class)->group(function () {
        Route::put('/blogs/credentials', [BlogCredentialController::class, 'update'])->name('blogs.credentials.update');
        Route::post('/blogs/credentials/verify', [BlogCredentialController::class, 'verify'])->name('blogs.credentials.verify');
    });

    // 記事（業務画面。ARCHITECTURE 17章）。{type} は posts / pages
    // AIが作った記事の管理情報の案の確認（D-27）
    Route::get('/articles/management-suggestions', [ManagementSuggestionController::class, 'index'])->name('management-suggestions.index');
    Route::get('/articles/{type}', [ArticleController::class, 'index'])->whereIn('type', ['posts', 'pages'])->name('articles.index');
    Route::get('/articles/{type}/{id}', [ArticleController::class, 'show'])->whereIn('type', ['posts', 'pages'])->whereNumber('id')->name('articles.show');
    Route::get('/articles/{type}/{id}/trash', [ArticleTrashController::class, 'confirm'])->whereIn('type', ['posts', 'pages'])->whereNumber('id')->name('articles.trash.confirm');

    // 編集案と反映（ARCHITECTURE 13-5・17-5）
    Route::get('/drafts', [DraftController::class, 'index'])->name('drafts.index');
    Route::get('/drafts/{id}/edit', [DraftController::class, 'edit'])->whereNumber('id')->name('drafts.edit');
    // 編集案のプレビュー（変更前と編集案の比較。D-28）
    Route::get('/drafts/{id}/preview', [DraftPreviewController::class, 'show'])->whereNumber('id')->name('drafts.preview');
    Route::get('/drafts/{id}/preview/frame', [DraftPreviewController::class, 'frame'])->whereNumber('id')->name('drafts.preview.frame');
    Route::get('/drafts/{id}/push', [DraftPushController::class, 'confirm'])->whereNumber('id')->name('drafts.push.confirm');
    Route::get('/drafts/{id}/conflict', [DraftConflictController::class, 'show'])->whereNumber('id')->name('drafts.conflict.show');
    Route::get('/push-operations', [PushOperationController::class, 'index'])->name('push-operations.index');
    Route::get('/push-operations/{id}', [PushOperationController::class, 'show'])->whereNumber('id')->name('push-operations.show');

    Route::middleware(EnsureSelectedBlog::class)->group(function () {
        Route::post('/articles/management-suggestions/review', [ManagementSuggestionController::class, 'review'])->name('management-suggestions.review');
        Route::put('/articles/{type}/{id}/management', [ArticleManagementController::class, 'update'])->whereIn('type', ['posts', 'pages'])->whereNumber('id')->name('articles.management.update');
        Route::post('/articles/{type}/{id}/relations', [ArticleManagementController::class, 'storeRelation'])->whereIn('type', ['posts', 'pages'])->whereNumber('id')->name('articles.relations.store');
        Route::delete('/articles/{type}/{id}/relations/{relationId}', [ArticleManagementController::class, 'destroyRelation'])->whereIn('type', ['posts', 'pages'])->whereNumber(['id', 'relationId'])->name('articles.relations.destroy');
        Route::post('/articles/{type}/{id}/trash', [ArticleTrashController::class, 'destroy'])->whereIn('type', ['posts', 'pages'])->whereNumber('id')->name('articles.trash.destroy');

        Route::post('/drafts', [DraftController::class, 'store'])->name('drafts.store');
        Route::put('/drafts/{id}', [DraftController::class, 'update'])->whereNumber('id')->name('drafts.update');
        Route::post('/drafts/{id}/finish', [DraftController::class, 'finish'])->whereNumber('id')->name('drafts.finish');
        Route::post('/drafts/{id}/state', [DraftController::class, 'changeState'])->whereNumber('id')->name('drafts.state');
        Route::post('/drafts/{id}/push', [DraftPushController::class, 'store'])->whereNumber('id')->name('drafts.push.store');
        Route::post('/drafts/{id}/conflict', [DraftConflictController::class, 'resolve'])->whereNumber('id')->name('drafts.conflict.resolve');
        Route::post('/push-operations/{id}/resolve', [PushOperationController::class, 'resolve'])->whereNumber('id')->name('push-operations.resolve');

        Route::put('/terms/{type}/{id}', [TermController::class, 'update'])->whereIn('type', ['categories', 'tags', 'media'])->whereNumber('id')->name('terms.update');
        Route::delete('/terms/{type}/{id}', [TermController::class, 'destroy'])->whereIn('type', ['categories', 'tags', 'media'])->whereNumber('id')->name('terms.destroy');
    });

    // カテゴリ・タグ・メディアの情報の更新と削除（WORDPRESS_API 21-3）
    Route::get('/terms/{type}/{id}/edit', [TermController::class, 'edit'])->whereIn('type', ['categories', 'tags', 'media'])->whereNumber('id')->name('terms.edit');

    // 同期（D-01-03）。「今すぐ同期」はJobとして登録し、状態は api.sync.status で読む
    Route::get('/sync/issues', [SyncIssueController::class, 'index'])->name('sync.issues.index');
    Route::get('/api/sync-status', [ApiSyncStatusController::class, 'show'])->name('api.sync.status');

    Route::middleware(EnsureSelectedBlog::class)->group(function () {
        Route::post('/sync/runs', [SyncRunController::class, 'store'])->name('sync.runs.store');
        Route::post('/sync/issues/{id}/resolve', [SyncIssueController::class, 'resolve'])->whereNumber('id')->name('sync.issues.resolve');
    });

    // DB確認画面（D-11-02）
    Route::get('/database/blog-list', [DatabaseBlogListController::class, 'index'])->name('database-blog-list');
    Route::get('/database/blog-detail/{id}', [DatabaseBlogDetailController::class, 'show'])->name('database-blog-detail');
    Route::get('/database/blog-history-list', [DatabaseBlogHistoryListController::class, 'index'])->name('database-blog-history-list');
    Route::get('/database/login-histories', [DatabaseLoginHistoryController::class, 'index'])->name('database.login-histories.index');
    Route::get('/database/sync-runs', [DatabaseSyncRunController::class, 'index'])->name('database.sync-runs.index');
    Route::get('/database/wordpress', [DatabaseWordPressRecordController::class, 'tables'])->name('database.wordpress-records.tables');
    Route::get('/database/wordpress/{table}', [DatabaseWordPressRecordController::class, 'index'])->name('database.wordpress-records.index');
    Route::get('/database/wordpress/{table}/{id}', [DatabaseWordPressRecordController::class, 'show'])->whereNumber('id')->name('database.wordpress-records.show');

    // API確認画面（D-11-02、ARCHITECTURE 21-2）
    Route::get('/wordpress-api', [WordPressApiResourceController::class, 'home'])->name('wp-api.home');
    Route::get('/wordpress-api/{resource}', [WordPressApiResourceController::class, 'index'])->name('wp-api.resources.index');
    Route::get('/wordpress-api/{resource}/{id}', [WordPressApiResourceController::class, 'show'])->name('wp-api.resources.show');

    // サイト内検索（Search API。将来の候補 D-10-05。見直しまでは現状のまま）
    Route::get('/api/site-search', [ApiSiteSearchController::class, 'index'])->name('api-site-search');

    // 品質評価（D-06-02、D-07-03）とBlogOSのAI機能（ARCHITECTURE 18章）
    Route::get('/evaluations/create', [EvaluationController::class, 'create'])->name('evaluations.create');
    Route::get('/evaluations/{id}', [EvaluationController::class, 'show'])->whereNumber('id')->name('evaluations.show');
    Route::get('/ai', [AiGenerationController::class, 'index'])->name('ai.generations.index');
    Route::get('/ai/create', [AiGenerationController::class, 'create'])->name('ai.generations.create');
    Route::get('/ai/{id}', [AiGenerationController::class, 'show'])->whereNumber('id')->name('ai.generations.show');
    // まとめて実行・AIの設定（自動の再評価）。D-25
    Route::get('/ai/batches', [AiBatchController::class, 'index'])->name('ai.batches.index');
    Route::get('/ai/batches/create', [AiBatchController::class, 'create'])->name('ai.batches.create');
    Route::get('/ai/batches/{id}', [AiBatchController::class, 'show'])->whereNumber('id')->name('ai.batches.show');
    Route::get('/ai/settings', [AiSettingsController::class, 'edit'])->name('ai.settings.edit');
    Route::get('/ai/credits', [AiCreditController::class, 'index'])->name('ai.credits.index');

    Route::middleware(EnsureSelectedBlog::class)->group(function () {
        Route::post('/evaluations', [EvaluationController::class, 'store'])->name('evaluations.store');
        Route::post('/ai', [AiGenerationController::class, 'store'])->name('ai.generations.store');
        Route::post('/ai/{id}/output', [AiGenerationController::class, 'submit'])->whereNumber('id')->name('ai.generations.submit');
        Route::post('/ai/{id}/cancel', [AiGenerationController::class, 'cancel'])->whereNumber('id')->name('ai.generations.cancel');
        Route::post('/ai/{id}/retry', [AiGenerationController::class, 'retry'])->whereNumber('id')->name('ai.generations.retry');
        Route::post('/ai/{id}/reprocess', [AiGenerationController::class, 'reprocess'])->whereNumber('id')->name('ai.generations.reprocess');
        Route::post('/ai/batches', [AiBatchController::class, 'store'])->name('ai.batches.store');
        Route::post('/ai/batches/{id}/cancel', [AiBatchController::class, 'cancel'])->whereNumber('id')->name('ai.batches.cancel');
        Route::put('/ai/settings', [AiSettingsController::class, 'update'])->name('ai.settings.update');
        // AIの費用と残高（D-31-04）
        Route::post('/ai/credits/balance', [AiCreditController::class, 'storeBalance'])->name('ai.credits.balance');
        Route::post('/ai/credits/purchase', [AiCreditController::class, 'storePurchase'])->name('ai.credits.purchase');
        Route::delete('/ai/credits/{id}', [AiCreditController::class, 'destroy'])->whereNumber('id')->name('ai.credits.destroy');
        // API実行の料金表（D-31-03）
        Route::post('/ai/prices/check', [AiPriceController::class, 'check'])->name('ai.prices.check');
        Route::post('/ai/prices/changes/{id}/apply', [AiPriceController::class, 'apply'])->whereNumber('id')->name('ai.prices.apply');
        Route::post('/ai/prices/changes/{id}/reject', [AiPriceController::class, 'reject'])->whereNumber('id')->name('ai.prices.reject');
    });

    // 収益用の教材（書籍・Udemy・スクール）。D-30
    Route::get('/materials', [MaterialController::class, 'index'])->name('materials.index');
    Route::get('/materials/create', [MaterialController::class, 'create'])->name('materials.create');
    Route::get('/materials/detected', [MaterialController::class, 'detected'])->name('materials.detected');
    Route::get('/materials/discover', [MaterialAiController::class, 'discoverForm'])->name('materials.discover.create');
    Route::get('/materials/suggestions', [MaterialSuggestionController::class, 'index'])->name('materials.suggestions.index');
    Route::get('/materials/suggestions/{id}', [MaterialSuggestionController::class, 'show'])->whereNumber('id')->name('materials.suggestions.show');
    Route::get('/materials/reviews', [MaterialReviewController::class, 'index'])->name('materials.reviews.index');
    Route::get('/materials/programs', [AffiliateProgramController::class, 'index'])->name('materials.programs.index');
    Route::get('/materials/{id}/edit', [MaterialController::class, 'edit'])->whereNumber('id')->name('materials.edit');

    Route::middleware(EnsureSelectedBlog::class)->group(function () {
        Route::post('/materials', [MaterialController::class, 'store'])->name('materials.store');
        Route::put('/materials/{id}', [MaterialController::class, 'update'])->whereNumber('id')->name('materials.update');
        Route::delete('/materials/{id}', [MaterialController::class, 'destroy'])->whereNumber('id')->name('materials.destroy');
        Route::post('/materials/detected', [MaterialController::class, 'registerDetected'])->name('materials.detected.store');
        Route::post('/materials/relink', [MaterialController::class, 'relink'])->name('materials.relink');
        Route::post('/materials/{id}/research', [MaterialAiController::class, 'research'])->whereNumber('id')->name('materials.research');
        Route::post('/materials/research', [MaterialAiController::class, 'researchMany'])->name('materials.research.many');
        Route::post('/materials/discover', [MaterialAiController::class, 'discover'])->name('materials.discover.store');
        Route::post('/materials/suggestions/{id}/apply', [MaterialSuggestionController::class, 'apply'])->whereNumber('id')->name('materials.suggestions.apply');
        Route::post('/materials/suggestions/{id}/register', [MaterialSuggestionController::class, 'register'])->whereNumber('id')->name('materials.suggestions.register');
        Route::post('/materials/suggestions/{id}/reject', [MaterialSuggestionController::class, 'reject'])->whereNumber('id')->name('materials.suggestions.reject');
        Route::post('/materials/programs', [AffiliateProgramController::class, 'store'])->name('materials.programs.store');
        Route::put('/materials/programs/{id}', [AffiliateProgramController::class, 'update'])->whereNumber('id')->name('materials.programs.update');
        Route::post('/materials/programs/register', [AffiliateProgramController::class, 'registerFromLinks'])->name('materials.programs.register');
        Route::post('/materials/programs/check', [AffiliateProgramController::class, 'checkLinks'])->name('materials.programs.check');
        Route::post('/materials/reviews/run', [MaterialAiController::class, 'review'])->name('materials.reviews.run');
        Route::post('/materials/reviews/mark', [MaterialReviewController::class, 'markReviewed'])->name('materials.reviews.mark');
        Route::post('/materials/reviews/{id}/confirm', [MaterialReviewController::class, 'confirm'])->whereNumber('id')->name('materials.reviews.confirm');
        Route::post('/materials/reviews/{id}/reject', [MaterialReviewController::class, 'reject'])->whereNumber('id')->name('materials.reviews.reject');
    });

    // 記事で使う画像（D-32）
    Route::get('/images', [ImageController::class, 'index'])->name('images.index');
    Route::get('/images/eyecatches', [EyecatchController::class, 'index'])->name('images.eyecatches');
    Route::get('/images/{id}', [ImageController::class, 'show'])->whereNumber('id')->name('images.show');
    Route::get('/images/{id}/file', [ImageController::class, 'file'])->whereNumber('id')->name('images.file');

    Route::middleware(EnsureSelectedBlog::class)->group(function () {
        Route::post('/images', [ImageController::class, 'store'])->name('images.store');
        Route::post('/images/design', [ImageController::class, 'design'])->name('images.design');
        Route::put('/images/eyecatches', [EyecatchController::class, 'update'])->name('images.eyecatches.update');
        Route::put('/images/{id}', [ImageController::class, 'update'])->whereNumber('id')->name('images.update');
        Route::put('/images/{id}/svg', [ImageController::class, 'updateSvg'])->whereNumber('id')->name('images.svg');
        Route::post('/images/{id}/png', [ImageController::class, 'storePng'])->whereNumber('id')->name('images.png');
        Route::post('/images/{id}/file', [ImageController::class, 'replaceFile'])->whereNumber('id')->name('images.file.replace');
        Route::post('/images/{id}/ready', [ImageController::class, 'markReady'])->whereNumber('id')->name('images.ready');
        Route::post('/images/{id}/generate', [ImageController::class, 'generate'])->whereNumber('id')->name('images.generate');
        Route::post('/images/{id}/variant', [ImageController::class, 'variant'])->whereNumber('id')->name('images.variant');
        Route::post('/images/{id}/design', [ImageController::class, 'redesign'])->whereNumber('id')->name('images.redesign');
        Route::post('/images/{id}/wordpress', [ImageController::class, 'uploadToWordPress'])->whereNumber('id')->name('images.wordpress');
        Route::delete('/images/{id}', [ImageController::class, 'destroy'])->whereNumber('id')->name('images.destroy');
    });

    // Google連携（D-21-01、D-21-07）と分析
    Route::get('/google', [GoogleSettingsController::class, 'index'])->name('google.settings');
    Route::get('/google/oauth/redirect', [GoogleOAuthController::class, 'redirect'])->name('google.oauth.redirect');
    Route::get('/google/oauth/callback', [GoogleOAuthController::class, 'callback'])->name('google.oauth.callback');
    // 試作のときに Google Cloud に登録したリダイレクトURIのまま使えるようにする
    Route::get('/adsense/oauth/callback', [GoogleOAuthController::class, 'callback']);
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

    Route::middleware(EnsureSelectedBlog::class)->group(function () {
        Route::put('/google/properties/{service}', [GoogleSettingsController::class, 'updateProperty'])->whereIn('service', ['ga4', 'search_console', 'adsense'])->name('google.properties.update');
        Route::delete('/google/accounts/{id}', [GoogleSettingsController::class, 'destroyAccount'])->whereNumber('id')->name('google.accounts.destroy');
        Route::post('/google/fetch', [GoogleSettingsController::class, 'fetch'])->name('google.fetch');
    });
});
