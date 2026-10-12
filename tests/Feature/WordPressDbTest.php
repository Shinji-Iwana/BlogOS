<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Post;
use App\Models\User;
use App\Support\WordPressDatabaseTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WordPress の DB のテーブルの一覧・詳細（D-81）：読むだけで、WordPress の DB を変えない
 */
class WordPressDbTest extends TestCase
{
    use RefreshDatabase;

    protected string $schema = 'blogos_testing_wpdb';

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::statement("create database if not exists `{$this->schema}`");
        } catch (\Throwable) {
            $this->markTestSkipped('テスト用の DB を作れない');
        }
        $s = $this->schema;
        DB::statement("create table `{$s}`.`wp_options` (option_id bigint unsigned primary key auto_increment, option_name varchar(191), option_value longtext, autoload varchar(20))");
        DB::statement("create table `{$s}`.`wp_users` (ID bigint unsigned primary key auto_increment, user_login varchar(60), user_pass varchar(255), display_name varchar(250))");
        DB::statement("create table `{$s}`.`wp_usermeta` (umeta_id bigint unsigned primary key auto_increment, user_id bigint unsigned, meta_key varchar(255), meta_value longtext)");
        DB::statement("create table `{$s}`.`wp_posts` (ID bigint unsigned primary key auto_increment, post_author bigint unsigned, post_title text, post_content longtext, post_password varchar(255), post_type varchar(20), post_parent bigint unsigned, post_date_gmt varchar(19))");
        DB::statement("create table `{$s}`.`wp_postmeta` (meta_id bigint unsigned primary key auto_increment, post_id bigint unsigned, meta_key varchar(255), meta_value longtext)");
        DB::statement("create table `{$s}`.`wp_term_relationships` (object_id bigint unsigned, term_taxonomy_id bigint unsigned, primary key (object_id, term_taxonomy_id))");
        DB::table("{$s}.wp_options")->insert([
            ['option_name' => 'home', 'option_value' => 'https://blog.example.test', 'autoload' => 'yes'],
            ['option_name' => 'some_plugin_api_key', 'option_value' => 'secret-api-key', 'autoload' => 'yes'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::statement("drop database if exists `{$this->schema}`");

        parent::tearDown();
    }

    /**
     * 本体の12テーブルと、本番で使っているプラグインの23テーブルの全ての列に、説明がある（D-81-05・06。本体の列は WordPress の標準の構造（wp-admin/includes/schema.php）、プラグインの列は本番の DB のとおり）
     */
    public function test_every_core_column_has_a_description(): void
    {
        $expected = [
            'posts'              => ['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count'],
            'postmeta'           => ['meta_id', 'post_id', 'meta_key', 'meta_value'],
            'options'            => ['option_id', 'option_name', 'option_value', 'autoload'],
            'users'              => ['ID', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'user_activation_key', 'user_status', 'display_name'],
            'usermeta'           => ['umeta_id', 'user_id', 'meta_key', 'meta_value'],
            'terms'              => ['term_id', 'name', 'slug', 'term_group'],
            'term_taxonomy'      => ['term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count'],
            'term_relationships' => ['object_id', 'term_taxonomy_id', 'term_order'],
            'termmeta'           => ['meta_id', 'term_id', 'meta_key', 'meta_value'],
            'comments'           => ['comment_ID', 'comment_post_ID', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_author_IP', 'comment_date', 'comment_date_gmt', 'comment_content', 'comment_karma', 'comment_approved', 'comment_agent', 'comment_type', 'comment_parent', 'user_id'],
            'commentmeta'        => ['meta_id', 'comment_id', 'meta_key', 'meta_value'],
            'links'              => ['link_id', 'link_url', 'link_name', 'link_image', 'link_target', 'link_description', 'link_visible', 'link_owner', 'link_rating', 'link_updated', 'link_rel', 'link_notes', 'link_rss'],

            // プラグイン（本番の si-note の列。information_schema から読んだもの。D-81-06）
            'actionscheduler_actions'            => ['action_id', 'hook', 'status', 'scheduled_date_gmt', 'scheduled_date_local', 'priority', 'args', 'schedule', 'group_id', 'attempts', 'last_attempt_gmt', 'last_attempt_local', 'claim_id', 'extended_args'],
            'actionscheduler_claims'             => ['claim_id', 'date_created_gmt'],
            'actionscheduler_groups'             => ['group_id', 'slug'],
            'actionscheduler_logs'               => ['log_id', 'action_id', 'message', 'log_date_gmt', 'log_date_local'],
            'aioseo_ai_insights_keyword_reports' => ['id', 'uuid', 'keyword', 'status', 'brands', 'brands_mentioned', 'results', 'created', 'updated'],
            'aioseo_cache'                       => ['id', 'key', 'value', 'is_object', 'expiration', 'created', 'updated', 'name'],
            'aioseo_crawl_cleanup_blocked_args'  => ['id', 'param', 'key', 'value', 'key_value_hash', 'regex', 'hits', 'created', 'updated', 'param_value_hash'],
            'aioseo_crawl_cleanup_logs'          => ['id', 'slug', 'param', 'key', 'value', 'hash', 'hits', 'created', 'updated'],
            'aioseo_notifications'               => ['id', 'slug', 'addon', 'title', 'content', 'type', 'level', 'notification_id', 'notification_name', 'start', 'end', 'button1_label', 'button1_action', 'button2_label', 'button2_action', 'dismissed', 'new', 'created', 'updated'],
            'aioseo_posts'                       => ['id', 'post_id', 'title', 'description', 'keywords', 'keyphrases', 'page_analysis', 'primary_term', 'canonical_url', 'og_title', 'og_description', 'og_object_type', 'og_image_type', 'og_image_url', 'og_image_width', 'og_image_height', 'og_image_custom_url', 'og_image_custom_fields', 'og_video', 'og_custom_url', 'og_article_section', 'og_article_tags', 'twitter_use_og', 'twitter_card', 'twitter_image_type', 'twitter_image_url', 'twitter_image_custom_url', 'twitter_image_custom_fields', 'twitter_title', 'twitter_description', 'seo_score', 'schema', 'schema_type', 'schema_type_options', 'pillar_content', 'robots_default', 'robots_noindex', 'robots_noarchive', 'robots_nosnippet', 'robots_nofollow', 'robots_noimageindex', 'robots_noodp', 'robots_notranslate', 'robots_max_snippet', 'robots_max_videopreview', 'robots_max_imagepreview', 'images', 'image_scan_date', 'priority', 'frequency', 'videos', 'video_thumbnail', 'video_scan_date', 'local_seo', 'breadcrumb_settings', 'limit_modified_date', 'options', 'ai', 'created', 'updated', 'seo_analyzer_scan_date', 'focus_keyword', 'truseo', 'additional_keywords', 'truseo_locale'],
            'aioseo_seo_analyzer_results'        => ['id', 'data', 'score', 'competitor_url', 'created', 'updated'],
            'aioseo_writing_assistant_keywords'  => ['id', 'uuid', 'keyword', 'country', 'language', 'progress', 'keywords', 'competitors', 'created', 'updated'],
            'aioseo_writing_assistant_posts'     => ['id', 'post_id', 'keyword_id', 'content_analysis_hash', 'content_analysis', 'created', 'updated'],
            'ewwwio_images'                      => ['id', 'attachment_id', 'gallery', 'resize', 'path', 'converted', 'image_size', 'orig_size', 'backup', 'level', 'pending', 'updates', 'updated', 'retrieve', 'resized_width', 'resized_height', 'resize_error', 'webp_size', 'webp_error'],
            'ewwwio_pages'                       => ['id', 'page', 'data'],
            'ewwwio_queue'                       => ['id', 'attachment_id', 'gallery', 'scanned', 'new', 'convert_once', 'force_reopt', 'force_smart', 'webp_only'],
            'quads_adbuy_data'                   => ['id', 'user_id', 'ad_id', 'ad_content', 'ad_link', 'ad_image', 'start_date', 'end_date', 'payment_response', 'payment_status', 'ad_status'],
            'quads_clicks_desktop'               => ['id', 'ad_id', 'stats_date', 'stats_clicks', 'stats_year'],
            'quads_clicks_mobile'                => ['id', 'ad_id', 'stats_date', 'stats_clicks', 'stats_year'],
            'quads_disabledad_data'              => ['disable_ad_id', 'user_id', 'payment_response', 'disable_cost', 'payment_status', 'disable_status', 'disable_duration', 'username', 'user_email', 'disable_date'],
            'quads_impressions_desktop'          => ['id', 'ad_id', 'stats_date', 'stats_impressions', 'stats_year'],
            'quads_impressions_mobile'           => ['id', 'ad_id', 'stats_date', 'stats_impressions', 'stats_year'],
            'quads_logs'                         => ['id', 'ad_id', 'log_date', 'log_clicks', 'ip_address', 'log_url', 'browser', 'referrer'],
        ];

        $this->assertSame($expected, WordPressDatabaseTables::describedColumns());
    }

    public function test_list_and_detail_of_wordpress_tables(): void
    {
        $s = $this->schema;
        $this->actingAs(User::factory()->create());
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        $userId = DB::table("{$s}.wp_users")->insertGetId(['user_login' => 'admin', 'user_pass' => '$P$hashed-password', 'display_name' => '管理人']);
        DB::table("{$s}.wp_usermeta")->insert([
            ['user_id' => $userId, 'meta_key' => 'session_tokens', 'meta_value' => 'session-secret'],
            ['user_id' => $userId, 'meta_key' => 'nickname', 'meta_value' => 'かんりにん'],
        ]);
        $postId = DB::table("{$s}.wp_posts")->insertGetId(['post_author' => $userId, 'post_title' => 'はじめての記事', 'post_content' => '<p>本文</p>', 'post_password' => 'yomu-pass', 'post_type' => 'post', 'post_parent' => 0, 'post_date_gmt' => '0000-00-00 00:00:00']);
        DB::table("{$s}.wp_posts")->insert(['post_author' => $userId, 'post_title' => 'はじめての記事', 'post_content' => '<p>版</p>', 'post_type' => 'revision', 'post_parent' => $postId]);
        DB::table("{$s}.wp_postmeta")->insert(['post_id' => $postId, 'meta_key' => '_edit_lock', 'meta_value' => '1']);
        $blogosPost = Post::create(['blog_id' => $blog->id, 'wordpress_id' => $postId, 'title_raw' => 'はじめての記事', 'status' => 'publish', 'link' => 'https://blog.example.test/1.html']);

        // 一覧：日本語名（テーブル名）・新しい順・タイトル（text）は出し、本文（longtext）は出さない。タイトルで絞り込める
        $this->get(route('database.wordpress-db.index', ['schema' => $s, 'table' => 'wp_posts']))->assertOk()
            ->assertSee('<h1>投稿・固定ページ・メディアなど（wp_posts）</h1>', false)
            ->assertSee('<th>post_title</th>', false)
            ->assertDontSee('<th>post_content</th>', false)
            ->assertSeeInOrder(['>' . ($postId + 1) . '</a>', '>' . $postId . '</a>'], false);
        $this->assertSame(2, $this->get(route('database.wordpress-db.index', ['schema' => $s, 'table' => 'wp_posts', 'q' => 'はじめて']))->viewData('records')->total());

        // 詳細：つながる先の「（リンク）」・BlogOS に取り込んだデータ・関連（メタ情報・リビジョン）
        $this->get(route('database.wordpress-db.show', ['schema' => $s, 'table' => 'wp_posts', 'id' => $postId]))->assertOk()
            ->assertSee('<p>本文</p>')
            ->assertSee('0000-00-00 00:00:00')
            // 列の説明（D-81-05）と、記事を読むためのパスワードは出さない
            ->assertSee('data-tip="タイトル。"', false)
            ->assertSee('****（記事を読むためのパスワードを保存）')->assertDontSee('yomu-pass')
            ->assertSee('（<a href="' . route('database.wordpress-db.show', ['schema' => $s, 'table' => 'wp_users', 'id' => $userId]) . '">管理人 #' . $userId . '</a>）', false)
            ->assertSee('<a href="' . route('database.tables.show', ['table' => 'posts', 'id' => $blogosPost->id]) . '">投稿 #' . $blogosPost->id . '</a>', false)
            ->assertSeeInOrder(['<h2 data-code="RELATION">', '投稿・固定ページ・メディアなど（wp_posts）', 'post_parent', '投稿のメタ情報（wp_postmeta）', 'post_id'], false)
            ->assertDontSee('<h2 data-code="HISTORY">', false);

        // 関連の件数から開く一覧は、その行に絞る
        $this->get(route('database.wordpress-db.index', ['schema' => $s, 'table' => 'wp_posts', 'column' => 'post_parent', 'value' => $postId]))->assertOk()
            ->assertSee('post_parent が ' . $postId . ' の行');

        // 秘密の値は出さない（パスワードのハッシュ・セッション・API キーらしい設定）
        $this->get(route('database.wordpress-db.show', ['schema' => $s, 'table' => 'wp_users', 'id' => $userId]))->assertOk()
            ->assertSee('****（パスワードのハッシュを保存）')->assertDontSee('hashed-password');
        $meta = fn (string $key) => DB::table("{$s}.wp_usermeta")->where('meta_key', $key)->value('umeta_id');
        $this->get(route('database.wordpress-db.show', ['schema' => $s, 'table' => 'wp_usermeta', 'id' => $meta('nickname')]))->assertOk()->assertSee('かんりにん');
        $this->get(route('database.wordpress-db.show', ['schema' => $s, 'table' => 'wp_usermeta', 'id' => $meta('session_tokens')]))->assertOk()
            ->assertSee('****（ログイン中のセッションを保存）')->assertDontSee('session-secret');
        $this->get(route('database.wordpress-db.index', ['schema' => $s, 'table' => 'wp_options', 'q' => 'api_key']))->assertOk()
            ->assertSee('some_plugin_api_key');
        $this->get(route('database.wordpress-db.show', ['schema' => $s, 'table' => 'wp_options', 'id' => DB::table("{$s}.wp_options")->where('option_name', 'some_plugin_api_key')->value('option_id')]))->assertOk()
            ->assertSee('****（API キーなどの秘密の値かもしれないため、表示しません）')->assertDontSee('secret-api-key');

        // 主キーが複数の列のテーブルは、一覧だけ（詳細へのリンクなし）
        $this->get(route('database.wordpress-db.index', ['schema' => $s, 'table' => 'wp_term_relationships']))->assertOk()
            ->assertSee('投稿とタームのつながり（wp_term_relationships）');

        // 見えない DB・ない テーブル・BlogOS の DB は開けない
        $this->get(route('database.wordpress-db.index', ['schema' => $s, 'table' => 'wp_nothing']))->assertNotFound();
        $this->get(route('database.wordpress-db.index', ['schema' => 'mysql', 'table' => 'user']))->assertNotFound();
        $this->get(route('database.wordpress-db.index', ['schema' => DB::getDatabaseName(), 'table' => 'users']))->assertNotFound();

        // 読むだけで、WordPress の DB を変えない
        $this->assertSame(2, DB::table("{$s}.wp_posts")->count());
        $this->assertSame('$P$hashed-password', DB::table("{$s}.wp_users")->value('user_pass'));
    }
}
