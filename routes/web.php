<?php

use App\Http\Controllers\Ai\AiBatchController;
use App\Http\Controllers\Ai\AiCreditController;
use App\Http\Controllers\Ai\AiGenerationController;
use App\Http\Controllers\Ai\AiPriceController;
use App\Http\Controllers\Ai\AiSettingsController;
use App\Http\Controllers\Analytics\AnalyticsController;
use App\Http\Controllers\Analytics\PerformanceController;
use App\Http\Controllers\Api\SiteSearchController as ApiSiteSearchController;
use App\Http\Controllers\Api\SyncStatusController as ApiSyncStatusController;
use App\Http\Controllers\Articles\ArticleController;
use App\Http\Controllers\Articles\ArticleManagementController;
use App\Http\Controllers\Articles\ManagementSuggestionController;
use App\Http\Controllers\Articles\ArticleTitleController;
use App\Http\Controllers\Articles\InternalLinkCheckController;
use App\Http\Controllers\ScheduledTaskController;
use App\Http\Controllers\Articles\ArticleTrashController;
use App\Http\Controllers\Articles\DraftConflictController;
use App\Http\Controllers\Articles\DraftController;
use App\Http\Controllers\Articles\DraftPreviewController;
use App\Http\Controllers\Articles\DraftPushController;
use App\Http\Controllers\Articles\LinkSwitchController;
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
use App\Http\Controllers\Google\GoogleIndexController;
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
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\ServerStatusController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Topics\CategoryLaunchController;
use App\Http\Controllers\Topics\TopicPlanningController;
use App\Http\Controllers\WordPressUpdateController;
use App\Http\Controllers\Terms\CategoryController;
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

    // 画面「設定」（/settings）はなくした。設定はメニューの「設定」のポップアップ（D-63-23）
    // 音声の操作（ジャービス。D-58）。1分あたりの回数に上限を付ける
    Route::post('/voice/turn', [\App\Http\Controllers\VoiceController::class, 'turn'])->middleware('throttle:' . (int) config('blogos.voice.turns_per_minute', 10) . ',1')->name('voice.turn');
    Route::post('/voice/reset', [\App\Http\Controllers\VoiceController::class, 'reset'])->name('voice.reset');
    // リアルタイム会話（方式 d・e。D-58-06）：会話を始める・AI が呼んだ道具を実行する・使用量を残す
    Route::post('/voice/realtime/session', [\App\Http\Controllers\VoiceController::class, 'realtimeSession'])->middleware('throttle:10,1')->name('voice.realtime.session');
    Route::post('/voice/realtime/tool', [\App\Http\Controllers\VoiceController::class, 'realtimeTool'])->middleware('throttle:60,1')->name('voice.realtime.tool');
    Route::post('/voice/realtime/usage', [\App\Http\Controllers\VoiceController::class, 'realtimeUsage'])->middleware('throttle:120,1')->name('voice.realtime.usage');
    Route::put('/voice/settings', [\App\Http\Controllers\VoiceController::class, 'updateSettings'])->name('voice.settings.update');
    // 画面のテーマ（D-49）。BlogOS 全体の設定のため、選択中のブログの照合はしない
    Route::put('/settings/theme', [SettingsController::class, 'updateTheme'])->name('settings.theme.update');
    // アークリアクター（ironman テーマ）の状態と、動きを見比べる画面（D-76）
    Route::get('/api/reactor-status', [\App\Http\Controllers\ReactorController::class, 'status'])->name('api.reactor.status');
    Route::get('/reactor', [\App\Http\Controllers\ReactorController::class, 'compare'])->name('reactor.compare');
    // アクティビティログ（D-77。BlogOS 全体の作業の記録のため、選択中のブログの照合はしない）
    Route::get('/activities', [\App\Http\Controllers\ActivityLogController::class, 'index'])->name('activities.index');
    // PageSpeed Insightsとの同期履歴（D-78。選択中のブログの測定の記録）
    Route::get('/pagespeed/runs', [\App\Http\Controllers\PageSpeedController::class, 'runs'])->name('pagespeed.runs.index');
    // PageSpeed Insights情報（メニューの「情報」）と、今すぐ測定（記事・トップページ。D-78）
    Route::get('/pagespeed', [\App\Http\Controllers\PageSpeedController::class, 'index'])->name('pagespeed.index');
    Route::post('/pagespeed/measure', [\App\Http\Controllers\PageSpeedController::class, 'measure'])->middleware(EnsureSelectedBlog::class)->name('pagespeed.measure');

    // 定期実行（D-44）。ブログ全体の処理のため、選択中のブログの照合はしない
    // 画面「定期実行」はなくした（設定・今すぐ実行はメニュー。D-63-10）
    // 定期実行履歴（メニューの「履歴 → 定期実行履歴」。D-63-08）
    Route::get('/scheduled-tasks/runs', [ScheduledTaskController::class, 'runs'])->name('scheduled-tasks.runs');
    Route::put('/scheduled-tasks/{key}', [ScheduledTaskController::class, 'update'])->where('key', '[a-z:-]+')->name('scheduled-tasks.update');
    Route::post('/scheduled-tasks/{key}/run', [ScheduledTaskController::class, 'run'])->where('key', '[a-z:-]+')->name('scheduled-tasks.run');

    // ブログの登録（WORDPRESS_API 29章）。入力は、メニューの「設定 → ブログを登録」のポップアップ（D-63-21）
    Route::post('/blogs', [BlogRegistrationController::class, 'store'])->name('blogs.store');

    // 選択中のブログの認証情報（D-03-02、D-03-03）。入力は、メニューの「設定 → ブログ → 認証情報」のポップアップ（D-63-22）

    Route::middleware(EnsureSelectedBlog::class)->group(function () {
        Route::put('/blogs/credentials', [BlogCredentialController::class, 'update'])->name('blogs.credentials.update');
        Route::post('/blogs/credentials/verify', [BlogCredentialController::class, 'verify'])->name('blogs.credentials.verify');
    });

    // 記事（業務画面。ARCHITECTURE 17章）。{type} は posts / pages
    // AIが作った記事の管理情報の案の確認（D-27）
    Route::get('/articles/management-suggestions', [ManagementSuggestionController::class, 'index'])->name('management-suggestions.index');
    Route::get('/articles/titles', [ArticleTitleController::class, 'index'])->name('articles.titles');
    Route::get('/links/check', [InternalLinkCheckController::class, 'index'])->name('links.check');
    Route::get('/articles/{type}', [ArticleController::class, 'index'])->whereIn('type', ['posts', 'pages'])->name('articles.index');
    Route::get('/articles/{type}/{id}', [ArticleController::class, 'show'])->whereIn('type', ['posts', 'pages'])->whereNumber('id')->name('articles.show');
    Route::get('/articles/{type}/{id}/trash', [ArticleTrashController::class, 'confirm'])->whereIn('type', ['posts', 'pages'])->whereNumber('id')->name('articles.trash.confirm');

    // 編集案と反映（ARCHITECTURE 13-5・17-5）
    Route::get('/drafts', [DraftController::class, 'index'])->name('drafts.index');
    Route::get('/drafts/link-switch', [LinkSwitchController::class, 'index'])->name('drafts.link-switch');
    Route::get('/topics', [TopicPlanningController::class, 'index'])->name('topics.index');
    Route::get('/launches', [CategoryLaunchController::class, 'index'])->name('launches.index');
    Route::get('/launches/{id}', [CategoryLaunchController::class, 'show'])->whereNumber('id')->name('launches.show');
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
        Route::post('/drafts/link-switch', [LinkSwitchController::class, 'create'])->name('drafts.link-switch.create');
        Route::post('/drafts/link-switch/push', [LinkSwitchController::class, 'push'])->name('drafts.link-switch.push');
        Route::post('/topics', [TopicPlanningController::class, 'store'])->name('topics.store');
        Route::post('/topics/{id}/review', [TopicPlanningController::class, 'review'])->whereNumber('id')->name('topics.review');
        Route::post('/launches', [CategoryLaunchController::class, 'store'])->name('launches.store');
        Route::post('/launches/parents', [CategoryLaunchController::class, 'storeParent'])->name('launches.parents.store');
        Route::post('/launches/{id}/children', [CategoryLaunchController::class, 'addChildren'])->whereNumber('id')->name('launches.children.store');
        Route::post('/launches/{id}/status', [CategoryLaunchController::class, 'updateStatus'])->whereNumber('id')->name('launches.status');
        Route::post('/launches/{id}/parent-roadmap', [CategoryLaunchController::class, 'parentRoadmap'])->whereNumber('id')->name('launches.parent-roadmap');
        Route::post('/launches/{id}/parent-roadmap/publish', [CategoryLaunchController::class, 'publishParentRoadmap'])->whereNumber('id')->name('launches.parent-roadmap.publish');
        Route::post('/launch-children/{id}/plan', [CategoryLaunchController::class, 'plan'])->whereNumber('id')->name('launches.children.plan');
        Route::post('/launch-children/{id}/articles', [CategoryLaunchController::class, 'articles'])->whereNumber('id')->name('launches.children.articles');
        Route::post('/launch-children/{id}/roadmap', [CategoryLaunchController::class, 'roadmap'])->whereNumber('id')->name('launches.children.roadmap');
        Route::post('/launch-children/{id}/publish', [CategoryLaunchController::class, 'publish'])->whereNumber('id')->name('launches.children.publish');
        Route::post('/drafts/{id}/state', [DraftController::class, 'changeState'])->whereNumber('id')->name('drafts.state');
        Route::post('/drafts/{id}/push', [DraftPushController::class, 'store'])->whereNumber('id')->name('drafts.push.store');
        Route::post('/drafts/{id}/conflict', [DraftConflictController::class, 'resolve'])->whereNumber('id')->name('drafts.conflict.resolve');
        Route::post('/push-operations/{id}/resolve', [PushOperationController::class, 'resolve'])->whereNumber('id')->name('push-operations.resolve');

        Route::put('/terms/{type}/{id}', [TermController::class, 'update'])->whereIn('type', ['categories', 'tags', 'media'])->whereNumber('id')->name('terms.update');
        Route::delete('/terms/{type}/{id}', [TermController::class, 'destroy'])->whereIn('type', ['categories', 'tags', 'media'])->whereNumber('id')->name('terms.destroy');
    });

    // カテゴリ・タグ・メディアの情報の更新と削除（WORDPRESS_API 21-3）
    // カテゴリの一覧（D-53）。更新は terms.edit
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
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
    // OpenAI API料金表との同期履歴（メニューの「履歴 → OpenAI API料金表との同期履歴」。全ブログ共通。D-63-27）
    Route::get('/ai/prices/history', [AiPriceController::class, 'history'])->name('ai.prices.history');
    // OpenAI API料金表情報（メニューの「情報 → OpenAI API料金表情報」。全ブログ共通。D-63-28）
    Route::get('/ai/prices', [AiPriceController::class, 'index'])->name('ai.prices.index');
    // お知らせ（ヘッダーのお知らせのボタン・メニューの「履歴 → お知らせ」。全ブログ共通。D-74）
    Route::get('/notices', [NoticeController::class, 'index'])->name('notices.index');
    Route::post('/notices/confirm', [NoticeController::class, 'confirm'])->name('notices.confirm');
    // XServer情報（メニューの「情報 → XServer情報」。読み取りだけ。全ブログ共通。D-71）
    Route::get('/server', [ServerStatusController::class, 'index'])->name('server.index');
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
        // 定期実行のいつと、選択中のブログの有効・無効（教材の定期チェック。メニューのポップアップ。D-63-03）
        Route::put('/scheduled-tasks/{key}/blog', [ScheduledTaskController::class, 'updateWithBlog'])->where('key', '[a-z:-]+')->name('scheduled-tasks.update-blog');
        // AIの費用と残高（D-31-04）
        Route::post('/ai/credits/balance', [AiCreditController::class, 'storeBalance'])->name('ai.credits.balance');
        Route::post('/ai/credits/purchase', [AiCreditController::class, 'storePurchase'])->name('ai.credits.purchase');
        Route::delete('/ai/credits/{id}', [AiCreditController::class, 'destroy'])->whereNumber('id')->name('ai.credits.destroy');
        // API実行の料金表（D-31-03）
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
    // Googleとの同期履歴（メニューの「履歴 → Googleとの同期履歴」）。接続と対応先は、メニューの「設定 → Google」のポップアップ（D-63-19）
    Route::get('/google/fetch-runs', [GoogleSettingsController::class, 'runs'])->name('google.fetch-runs.index');
    Route::get('/google/candidates/{service}', [GoogleSettingsController::class, 'candidates'])->whereIn('service', ['ga4', 'search_console', 'adsense'])->name('google.candidates');
    // 接続の解除は、ブログを選んでいなくてもできる
    Route::delete('/google/accounts/{id}', [GoogleSettingsController::class, 'destroyAccount'])->whereNumber('id')->name('google.accounts.destroy');
    Route::get('/google/index-status', [GoogleIndexController::class, 'index'])->name('google.index-status');
    Route::get('/wordpress-updates', [WordPressUpdateController::class, 'index'])->name('wordpress-updates.index');
    Route::get('/google/oauth/redirect', [GoogleOAuthController::class, 'redirect'])->name('google.oauth.redirect');
    Route::get('/google/oauth/callback', [GoogleOAuthController::class, 'callback'])->name('google.oauth.callback');
    // 試作のときに Google Cloud に登録したリダイレクトURIのまま使えるようにする
    Route::get('/adsense/oauth/callback', [GoogleOAuthController::class, 'callback']);
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    // 記事の実績と次にやること（D-47 S4）
    Route::get('/analytics/performance', [PerformanceController::class, 'index'])->name('analytics.performance');
    // AdSense（推定収益額・残高・パフォーマンス・広告ユニットなど。D-67）
    Route::get('/analytics/adsense', [\App\Http\Controllers\Analytics\AdsenseController::class, 'index'])->name('analytics.adsense');

    Route::middleware(EnsureSelectedBlog::class)->group(function () {
        Route::put('/google/properties/{service}', [GoogleSettingsController::class, 'updateProperty'])->whereIn('service', ['ga4', 'search_console', 'adsense'])->name('google.properties.update');
        Route::post('/google/index-status', [GoogleIndexController::class, 'run'])->name('google.index-status.run');
    });
});
