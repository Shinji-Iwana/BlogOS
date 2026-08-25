<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * categoriesテーブルを作成する。
     *
     * このテーブルには、BlogOSが管理するブログのカテゴリ情報を保存する。
     *
     * カテゴリ情報は、WordPress REST API
     * 「/wp-json/wp/v2/categories」から取得した情報を基に管理する。
     *
     * 本テーブルでは、BlogOS内部で使用するID（id）と、
     * WordPress側で管理されているカテゴリID（category_id）を分離する。
     *
     * これにより、BlogOS側のデータ管理とWordPress側のID体系を
     * 独立して管理できるようにする。
     *
     * また、BlogOSでは複数のブログを管理するため、
     * blog_idによってどのブログのカテゴリ情報であるかを識別する。
     *
     * 同一ブログ内で同じWordPressカテゴリが重複して登録されないよう、
     * blog_idとcategory_idの組み合わせを一意とする。
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            // BlogOS内部で使用する一意のID。
            // レコード作成時に自動採番される。
            $table->id();

            // このカテゴリが属するブログを識別するID。
            // blogsテーブルのidを参照する。
            // NULLは許容しない。
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();

            // WordPress側で管理されているカテゴリID。
            // WordPress REST API
            // 「/wp-json/wp/v2/categories」の
            // idから取得した値を保存する。
            // NULLは許容しない。
            // BlogOS内部のidとは別のIDである。
            $table->unsignedBigInteger('category_id');

            // WordPress側で管理されているカテゴリ名。
            // WordPress REST APIのnameから取得した値を保存する。
            // NULLは許容しない。
            $table->string('name', 255);

            // WordPress側で管理されているカテゴリのスラッグ。
            // WordPress REST APIのslugから取得した値を保存する。
            // NULLは許容する。
            $table->string('slug', 255)->nullable();

            // WordPress側で管理されている親カテゴリのID。
            // WordPress REST APIのparentから取得した値を保存する。
            // 親カテゴリが存在しない場合はNULLとする。
            // 値は同一ブログ内のcategories.category_idを
            // 親カテゴリとして識別するために使用する。
            // NULLを許容する。
            // category_idはブログ単位で一意となるため、
            // ここではcategory_idへの外部キー制約は設定しない。
            $table->unsignedBigInteger('parent')->nullable();

            // WordPress側で管理されているカテゴリページのURL。
            // WordPress REST APIのlinkから取得した値を保存する。
            $table->text('link')->nullable();

            // WordPress側で管理されているカテゴリの説明文。
            // WordPress REST APIのdescriptionから取得した値を保存する。
            $table->text('description')->nullable();

            // レコードの作成日時・更新日時。
            // LaravelのEloquentによるデータ管理で使用する。
            $table->timestamps();

            // 同一ブログ内で同じWordPressカテゴリが
            // 重複登録されることを防止する。
            // blog_idとcategory_idの組み合わせを一意とする。
            $table->unique(['blog_id', 'category_id'],'categories_blog_id_category_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * categoriesテーブルを削除する。
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
