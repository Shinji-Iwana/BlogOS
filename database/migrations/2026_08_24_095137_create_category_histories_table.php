<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * category_historiesテーブルを作成する。
     *
     * このテーブルには、categoriesテーブルの各項目が変更された際に、
     * 変更前の値・変更後の値・変更された項目・変更経路を履歴として保存する。
     */
    public function up(): void
    {
        Schema::create('category_histories', function (Blueprint $table) {
            // 履歴レコード自身を一意に識別するID
            $table->id();

            // 変更対象となったブログのID
            // blogs.idを参照する
            $table->foreignId('blog_id')->constrained('blogs');

            // 変更対象となったカテゴリのID
            // categories.idを参照する
            $table->foreignId('category_id')->constrained('categories');

            // 変更されたcategoriesテーブルの項目名
            // 例：name、slug、parent、link、description など
            $table->string('field', 255);

            // 変更前の値
            // 新規設定など、変更前の値が存在しない場合はNULL
            $table->text('old_value')->nullable();

            // 変更後の値
            // 値が削除された場合はNULL
            $table->text('new_value')->nullable();

            // 変更が発生した経路
            // 例：api、manual、system など
            $table->string('source', 255);

            // 変更が発生した日時
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     *
     * category_historiesテーブルを削除する。
     */
    public function down(): void
    {
        Schema::dropIfExists('category_histories');
    }
};
