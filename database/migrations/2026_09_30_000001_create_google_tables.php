<?php

use App\Support\Database\BlogosSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Google連携（BLOGOS_DATABASE.md 12章、D-21-01〜D-21-07）。
     */
    public function up(): void
    {
        // Googleアカウントごとの OAuth トークン（暗号化。D-03-05）
        Schema::create('google_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->unique();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // ブログごとの対応先
        Schema::create('blog_google_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            $table->string('service', 20); // App\Enums\GoogleService
            $table->foreignId('google_account_id')->constrained('google_accounts')->cascadeOnDelete();
            $table->string('resource_name', 255);
            $table->string('display_name', 255)->nullable();
            $table->string('adsense_domain', 255)->nullable();
            $table->timestamps();

            $table->unique(['blog_id', 'service']);
        });

        // 取得の実行記録
        Schema::create('google_fetch_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            $table->string('service', 20);
            $table->string('trigger', 20); // App\Enums\SyncTrigger（initial / scheduled / manual）
            $table->string('status', 20); // App\Enums\SyncStatus
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->text('message')->nullable();
            $table->unsignedSmallInteger('error_status')->nullable();
            $table->longText('error_body')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['blog_id', 'service', 'started_at']);
        });

        // GA4
        Schema::create('google_analytics_site_daily', function (Blueprint $table) {
            $this->siteDaily($table);
            $this->analyticsMetrics($table, withNewUsers: true);
        });

        Schema::create('google_analytics_page_daily', function (Blueprint $table) {
            $this->pageDaily($table, 'page_path');
            $this->analyticsMetrics($table);
            $table->unique(['blog_id', 'date', 'page_path_hash'], 'ga_page_daily_unique');
        });
        BlogosSchema::addArticleCheck('google_analytics_page_daily', exactlyOne: false);

        Schema::create('google_analytics_page_channel_daily', function (Blueprint $table) {
            $this->pageDaily($table, 'page_path');
            $table->string('channel_group', 100);
            $this->analyticsMetrics($table);
            $table->unique(['blog_id', 'date', 'page_path_hash', 'channel_group'], 'ga_page_channel_daily_unique');
        });
        BlogosSchema::addArticleCheck('google_analytics_page_channel_daily', exactlyOne: false);

        // Search Console
        Schema::create('google_search_console_site_daily', function (Blueprint $table) {
            $this->siteDaily($table);
            $this->searchMetrics($table);
        });

        Schema::create('google_search_console_page_daily', function (Blueprint $table) {
            $this->pageDaily($table, 'page_url');
            $this->searchMetrics($table);
            $table->unique(['blog_id', 'date', 'page_url_hash'], 'gsc_page_daily_unique');
        });
        BlogosSchema::addArticleCheck('google_search_console_page_daily', exactlyOne: false);

        Schema::create('google_search_console_query_daily', function (Blueprint $table) {
            $this->pageDaily($table, 'page_url');
            $table->text('query');
            $table->char('query_hash', 40);
            $this->searchMetrics($table);
            $table->unique(['blog_id', 'date', 'page_url_hash', 'query_hash'], 'gsc_query_daily_unique');
            $table->index(['blog_id', 'query_hash']);
        });
        BlogosSchema::addArticleCheck('google_search_console_query_daily', exactlyOne: false);

        // AdSense
        Schema::create('google_adsense_site_daily', function (Blueprint $table) {
            $this->siteDaily($table);
            $this->adsenseMetrics($table);
        });

        Schema::create('google_adsense_page_daily', function (Blueprint $table) {
            $this->pageDaily($table, 'page_url');
            $this->adsenseMetrics($table);
            $table->unique(['blog_id', 'date', 'page_url_hash'], 'adsense_page_daily_unique');
        });
        BlogosSchema::addArticleCheck('google_adsense_page_daily', exactlyOne: false);
    }

    public function down(): void
    {
        foreach ([
            'google_adsense_page_daily', 'google_adsense_site_daily',
            'google_search_console_query_daily', 'google_search_console_page_daily', 'google_search_console_site_daily',
            'google_analytics_page_channel_daily', 'google_analytics_page_daily', 'google_analytics_site_daily',
            'google_fetch_runs', 'blog_google_properties', 'google_accounts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * ブログ×日の表に共通の列
     */
    protected function siteDaily(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
        $table->date('date');
        $table->timestamp('fetched_at')->nullable();
        $table->timestamps();
        $table->unique(['blog_id', 'date']);
    }

    /**
     * ページ×日の表に共通の列。URL・パスは長さの制限がないため、一意キーにはハッシュを使う
     */
    protected function pageDaily(Blueprint $table, string $urlColumn): void
    {
        $table->id();
        $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
        $table->date('date');
        $table->text($urlColumn);
        $table->char("{$urlColumn}_hash", 40);
        $table->string('normalized_path', 500)->nullable();
        BlogosSchema::articleReference($table);
        $table->timestamp('fetched_at')->nullable();
        $table->timestamps();
        $table->index(['blog_id', 'normalized_path'], "{$table->getTable()}_path_index");
    }

    protected function analyticsMetrics(Blueprint $table, bool $withNewUsers = false): void
    {
        $table->unsignedInteger('screen_page_views')->default(0);
        $table->unsignedInteger('active_users')->default(0);
        if ($withNewUsers) {
            $table->unsignedInteger('new_users')->default(0);
        }
        $table->unsignedInteger('sessions')->default(0);
        $table->unsignedInteger('engaged_sessions')->default(0);
        $table->unsignedBigInteger('user_engagement_duration')->default(0); // 秒
    }

    protected function searchMetrics(Blueprint $table): void
    {
        $table->unsignedInteger('clicks')->default(0);
        $table->unsignedInteger('impressions')->default(0);
        $table->decimal('ctr', 8, 6)->default(0);
        $table->decimal('position', 8, 3)->default(0);
    }

    protected function adsenseMetrics(Blueprint $table): void
    {
        $table->string('currency_code', 3)->nullable();
        $table->decimal('estimated_earnings', 14, 4)->default(0);
        $table->unsignedInteger('page_views')->default(0);
        $table->unsignedInteger('impressions')->default(0);
        $table->unsignedInteger('clicks')->default(0);
    }
};
