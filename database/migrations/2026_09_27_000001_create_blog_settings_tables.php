<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * WordPressのサイト設定を保存する blog_settings と、その履歴 blog_setting_histories を作成する
     * （BLOGOS_DATABASE.md 5-4、BLOGOS_DECISIONS.md D-10-01）。
     *
     * サイト設定はWordPress由来の情報のため、BlogOS側の情報を持つ blogs とは分ける。
     * 項目の増減に列の追加で対応しなくて済むよう、キーと値の形で持つ。
     */
    public function up(): void
    {
        Schema::create('blog_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

            // 設定のキー（保存するキーは BLOGOS_DATABASE.md 5-4 で定めたものだけ）
            $table->string('key', 100);

            // 値（文字列。配列などはJSON文字列）
            $table->longText('value')->nullable();

            // 最後にWordPressと照合した日時
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['blog_id', 'key']);
        });

        // 履歴の共通の構造（BLOGOS_DATABASE.md 8-2）
        Schema::create('blog_setting_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            $table->foreignId('blog_setting_id')->constrained('blog_settings')->cascadeOnDelete();

            // 同時に起きた変更をまとめるID
            $table->uuid('change_set_id');

            // 変更した項目。blog_settings では設定のキー。作成は __created
            $table->string('field', 100);

            $table->longText('old_value')->nullable();
            $table->longText('new_value')->nullable();

            // 変更元（App\Enums\ChangeSource）
            $table->string('source', 50);

            // 変更のきっかけとなった処理（外部キーは、参照先の表を作る段階3・4で付ける）
            $table->unsignedBigInteger('sync_run_id')->nullable()->index();
            $table->unsignedBigInteger('wordpress_push_operation_id')->nullable()->index();

            // 操作した利用者
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('changed_at')->useCurrent();

            $table->index('change_set_id');
            $table->index(['blog_setting_id', 'changed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_setting_histories');
        Schema::dropIfExists('blog_settings');
    }
};
