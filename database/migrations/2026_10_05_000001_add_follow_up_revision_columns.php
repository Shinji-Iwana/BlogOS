<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 品質診断の後に、基準に満たない記事の編集案を自動で作る（D-26）。
     */
    public function up(): void
    {
        Schema::table('ai_batches', function (Blueprint $table) {
            // 品質診断の後に続けて行う記事改修の設定（点数の基準・モデル・推論の深さ・改修範囲。JSON）
            $table->json('follow_up')->nullable()->after('target_parameters');
            // 続けて行った記事改修のまとめて実行の、元の品質診断のまとめて実行
            $table->foreignId('parent_batch_id')->nullable()->after('blog_id')->constrained('ai_batches')->nullOnDelete();
        });

        Schema::table('blog_ai_settings', function (Blueprint $table) {
            // 自動の再評価の後に、基準に満たない記事の編集案を自動で作る
            $table->boolean('auto_revision_enabled')->default(true)->after('auto_reasoning_effort');
            $table->string('auto_revision_model', 100)->nullable()->after('auto_revision_enabled');
            $table->string('auto_revision_reasoning_effort', 30)->nullable()->after('auto_revision_model');
        });
    }

    public function down(): void
    {
        Schema::table('blog_ai_settings', function (Blueprint $table) {
            foreach (['auto_revision_enabled', 'auto_revision_model', 'auto_revision_reasoning_effort'] as $column) {
                if (Schema::hasColumn('blog_ai_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('ai_batches', 'parent_batch_id')) {
            Schema::table('ai_batches', function (Blueprint $table) {
                $table->dropForeign(['parent_batch_id']);
                $table->dropColumn(['parent_batch_id', 'follow_up']);
            });
        }
    }
};
