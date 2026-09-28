<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * D-32：記事で使う画像（図解・イラスト・アイキャッチ・スクリーンショット）と、カテゴリごとのアイキャッチ。
     * 画像モデル（gpt-image 系）の料金も料金表に持つ。
     */
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

            // 種類（App\Enums\ImageKind：diagram / illustration / eyecatch / screenshot）
            $table->string('kind', 20);
            // 状態（App\Enums\ImageStatus：draft：案 / ready：確認済み）
            $table->string('status', 20);
            // 作り方（App\Enums\ImageSource：ai_svg / ai_image / upload）
            $table->string('source', 20)->nullable();

            $table->string('title', 255);
            // どんな画像か（人の依頼。AIに作らせるときの元）
            $table->text('description')->nullable();
            $table->text('alt')->nullable();
            $table->text('caption')->nullable();
            // WordPress に登録するときのファイル名（拡張子なし。英数字とハイフン）
            $table->string('filename', 100)->nullable();

            // 図解の元の SVG（人が直せる）と、イラストを作る指示文（画像モデル用）
            $table->longText('svg_source')->nullable();
            $table->text('image_prompt')->nullable();
            // AI が SVG の図とイラストのどちらを選んだかの理由
            $table->text('ai_note')->nullable();

            // 保存したファイル（storage の中のパス）
            $table->string('path', 500)->nullable();
            $table->string('mime_type', 50)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('file_size')->nullable();

            // 同じ内容を別の形式（SVG の図／イラスト）で作った画像（比べて選ぶため）
            $table->foreignId('variant_of_image_id')->nullable()->constrained('images')->nullOnDelete();

            // WordPress のメディアに登録した後（または既存のメディア）
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['blog_id', 'kind', 'status']);
        });

        // カテゴリごとのアイキャッチ（WordPress のメディア）。子のカテゴリに設定がなければ、親のカテゴリの設定を使う
        Schema::create('category_eyecatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            $table->foreignId('category_id')->unique()->constrained('categories')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('ai_generations', function (Blueprint $table) {
            // 図の作成・画像の生成の対象の画像
            $table->foreignId('image_id')->nullable()->after('material_id')->constrained('images')->nullOnDelete();
        });

        // 画像モデルの料金（1Mトークンあたり）：input・cached_input は文章の入力、image_input・image_cached_input は画像の入力、output は画像の出力
        Schema::table('ai_prices', function (Blueprint $table) {
            $table->decimal('image_input', 12, 6)->nullable()->after('output');
            $table->decimal('image_cached_input', 12, 6)->nullable()->after('image_input');
        });

        $now = now();
        foreach ((array) config('blogos.ai.image.models') as $model => $price) {
            DB::table('ai_prices')->insert([
                'price_key'          => $model,
                'input'              => $price['text_input'],
                'cached_input'       => $price['text_cached_input'],
                'output'             => $price['image_output'],
                'image_input'        => $price['image_input'],
                'image_cached_input' => $price['image_cached_input'],
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('ai_prices')->whereIn('price_key', array_keys((array) config('blogos.ai.image.models')))->delete();
        Schema::table('ai_prices', fn (Blueprint $table) => $table->dropColumn(['image_input', 'image_cached_input']));

        Schema::table('ai_generations', function (Blueprint $table) {
            $table->dropForeign(['image_id']);
            $table->dropColumn('image_id');
        });

        Schema::dropIfExists('category_eyecatches');
        Schema::dropIfExists('images');
    }
};
