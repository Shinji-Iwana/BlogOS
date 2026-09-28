<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D-33-08：アフィリエイトのプログラム（ASPで提携する広告）と、その状態（提携中・申請中・否認など）。
     * 提携中でないプログラムの教材は、記事で紹介に使わない。
     */
    public function up(): void
    {
        Schema::create('affiliate_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

            // リンクから読み取るプログラムの識別子（App\Support\AffiliateLink::programKey。例：moshimo:5256、udemy）
            $table->string('program_key', 100);
            // ASP（moshimo / udemy / rakuten / amazon / a8 / other）
            $table->string('asp', 20);
            $table->string('name', 255);
            // このプログラムの教材の種類（App\Enums\MaterialKind。リンクから教材を登録するときに使う。Amazon・楽天のように種類が決まらないものは null）
            $table->string('material_kind', 20)->nullable();

            // 状態（App\Enums\AffiliateProgramStatus）と、変えた日
            $table->string('status', 20);
            $table->date('status_changed_on')->nullable();
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->unique(['blog_id', 'program_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_programs');
    }
};
