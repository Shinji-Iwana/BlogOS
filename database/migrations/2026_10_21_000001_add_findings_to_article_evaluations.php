<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 評価の作り直し（品質基準 2.0.0。D-47）：記事の型（細分類）・記事の型の必須の項目の ×・観点ごとの適合度と、
 * 項目ごとの指摘（どこが・何が足りないか・どう直すか）を記録する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_evaluations', function (Blueprint $table) {
            // 集客記事の細分類（記事の型の項目を決める）
            $table->string('article_subtype', 50)->nullable()->after('article_type');
            // 記事の型の必須（★）の項目で × のもの（1つでもあれば公開不可）
            $table->json('type_failures')->nullable()->after('required_conditions_passed');
            // 観点ごとの適合度（観点のキー => %）
            $table->json('axis_scores')->nullable()->after('score');
        });

        Schema::table('article_evaluation_details', function (Blueprint $table) {
            $table->text('location')->nullable()->after('comment');
            $table->text('problem')->nullable()->after('location');
            $table->text('fix')->nullable()->after('problem');
        });
    }

    public function down(): void
    {
        Schema::table('article_evaluation_details', function (Blueprint $table) {
            $table->dropColumn(['location', 'problem', 'fix']);
        });
        Schema::table('article_evaluations', function (Blueprint $table) {
            $table->dropColumn(['article_subtype', 'type_failures', 'axis_scores']);
        });
    }
};
