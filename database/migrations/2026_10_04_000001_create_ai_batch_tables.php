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
     * BlogOSのAI機能のまとめて実行・条件による自動の再評価（D-25）。
     */
    public function up(): void
    {
        // まとめて実行の記録（人が画面から実行したもの・自動の再評価）
        Schema::create('ai_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

            // 実行モード（App\Enums\AiMode。品質診断・記事改修）と、きっかけ（App\Enums\AiBatchTrigger）
            $table->string('purpose', 30);
            $table->string('trigger', 10);

            // 使ったモデルと推論の深さ（全ての記事で同じ）
            $table->string('model', 100);
            $table->string('reasoning_effort', 30);

            // 対象の選び方（App\Enums\AiBatchTarget）と、その条件（点数の基準など）
            $table->string('target', 30);
            $table->json('target_parameters')->nullable();

            $table->unsignedInteger('total_count')->default(0);

            // 状態（App\Enums\AiBatchStatus）。止めた理由（費用の上限など）
            $table->string('status', 20);
            $table->text('stop_reason')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['blog_id', 'created_at']);
        });

        // まとめて実行の対象の記事（1記事につき1件のAI実行記録を作る）
        Schema::create('ai_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_batch_id')->constrained('ai_batches')->cascadeOnDelete();
            BlogosSchema::articleReference($table);

            // 自動の再評価では、対象にした理由（App\Enums\ReevaluationReason）
            $table->string('reason', 20)->nullable();

            // 状態（App\Enums\AiBatchItemStatus）と、作ったAI実行記録
            $table->string('status', 20);
            $table->foreignId('ai_generation_id')->nullable()->constrained('ai_generations')->nullOnDelete();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['ai_batch_id', 'status']);
        });
        BlogosSchema::addArticleCheck('ai_batch_items', exactlyOne: true);

        // ブログごとのAIの設定（条件による自動の再評価）
        Schema::create('blog_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->unique()->constrained('blogs')->cascadeOnDelete();
            $table->boolean('auto_reevaluation_enabled')->default(false);
            $table->string('auto_model', 100);
            $table->string('auto_reasoning_effort', 30);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 評価した時点の「この記事へのリンク」の数。リンクの数が変わったら再評価する（D-25-04）
        Schema::table('article_evaluations', function (Blueprint $table) {
            $table->unsignedInteger('inbound_link_count')->nullable()->after('evaluated_wordpress_modified_gmt');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('article_evaluations', 'inbound_link_count')) {
            Schema::table('article_evaluations', fn (Blueprint $table) => $table->dropColumn('inbound_link_count'));
        }

        Schema::dropIfExists('blog_ai_settings');
        Schema::dropIfExists('ai_batch_items');
        Schema::dropIfExists('ai_batches');
    }
};
