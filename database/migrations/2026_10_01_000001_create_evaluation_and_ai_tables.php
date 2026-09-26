<?php

use App\Support\Database\BlogosSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * BlogOSのAI機能の実行記録（BLOGOS_DATABASE.md 9-6）と、記事の品質評価（9-5）。
     */
    public function up(): void
    {
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

            // 対象（新規記事の作成では、記事を持たない）
            BlogosSchema::articleReference($table);
            $table->foreignId('article_draft_id')->nullable()->constrained('article_drafts')->nullOnDelete();

            // 実行の内容（App\Enums\AiMode・RevisionScope）
            $table->string('purpose', 30);
            $table->string('revision_scope', 20)->nullable();

            // 人が渡した情報（キーワード・補足など。D-14-10：実体験・検証の内容は人が提供する）
            $table->json('parameters')->nullable();

            // 生成方法（D-07-07）
            $table->string('execution_method', 10); // manual / api
            $table->string('provider', 50)->nullable();
            $table->string('service_plan', 100)->nullable(); // 手動実行の利用プラン（例：ChatGPT Plus）
            $table->string('model', 100)->nullable();
            $table->string('reasoning_effort', 30)->nullable();

            // バージョン（D-06-09）
            $table->string('template_key', 50);
            $table->string('template_version', 20);
            $table->string('quality_common_version', 20)->nullable();
            $table->string('quality_profile', 50)->nullable();
            $table->string('quality_profile_version', 20)->nullable();

            // 入出力（全文）
            $table->longText('input');
            $table->longText('output')->nullable();

            // 費用
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('estimated_cost', 10, 4)->nullable();

            // 状態（App\Enums\AiGenerationStatus）
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['blog_id', 'purpose', 'created_at']);
        });
        BlogosSchema::addArticleCheck('ai_generations', exactlyOne: false);

        // 編集案の元になったAI実行記録（段階4で作った列に外部キーを付ける）
        Schema::table('article_drafts', function (Blueprint $table) {
            $table->foreign('ai_generation_id')->references('id')->on('ai_generations')->nullOnDelete();
        });

        Schema::create('article_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

            // 評価した対象：記事、または編集案（少なくとも一方）。CHECK制約のためCASCADE（D-20-01）
            BlogosSchema::articleReference($table);
            $table->foreignId('article_draft_id')->nullable()->constrained('article_drafts')->cascadeOnDelete();
            $table->dateTime('evaluated_wordpress_modified_gmt')->nullable();

            // 評価した主体（App\Enums\EvaluatorType）
            $table->string('evaluator_type', 10);
            $table->foreignId('ai_generation_id')->nullable()->constrained('ai_generations')->nullOnDelete();

            // 評価に使った記事種類と品質基準のバージョン（点数の再計算と比較のため）
            $table->string('article_type', 50)->nullable();
            $table->string('quality_common_version', 20);
            $table->string('quality_profile', 50)->nullable();
            $table->string('quality_profile_version', 20)->nullable();

            // 結果（点数は対象項目で100点に換算した値。小数第1位まで。D-15-01）
            $table->boolean('required_conditions_passed')->nullable();
            $table->decimal('score', 4, 1)->nullable();
            $table->text('summary')->nullable();

            // 人の確定（公開の可否は、人が確定した評価で判断する。D-07-03）
            $table->boolean('is_confirmed')->default(false);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['blog_id', 'created_at']);
        });
        DB::statement('ALTER TABLE article_evaluations ADD CONSTRAINT chk_article_evaluations_target CHECK ('
            . '(post_id IS NULL OR page_id IS NULL) AND (post_id IS NOT NULL OR page_id IS NOT NULL OR article_draft_id IS NOT NULL))');

        Schema::create('article_evaluation_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_evaluation_id')->constrained('article_evaluations')->cascadeOnDelete();

            // 品質基準で定義したキー（D-06-08。必須条件は req.*）
            $table->string('item_key', 50);

            // 判定（App\Enums\Judgment）と得点（△は配点の50%。D-06-03）
            $table->string('judgment', 20);
            $table->decimal('points', 4, 1)->nullable();
            $table->unsignedTinyInteger('max_points')->nullable();

            $table->text('comment')->nullable();

            $table->unique(['article_evaluation_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_evaluation_details');
        Schema::dropIfExists('article_evaluations');

        Schema::table('article_drafts', function (Blueprint $table) {
            $table->dropForeign(['ai_generation_id']);
        });

        Schema::dropIfExists('ai_generations');
    }
};
