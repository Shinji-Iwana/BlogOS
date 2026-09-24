<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * blog_historiesテーブルを作成する。
     *
     * このテーブルには、blogsテーブルの各項目が変更された際に、
     * 変更前の値・変更後の値・変更された項目・変更経路を
     * 履歴として保存する。
     *
     * ただし、BlogOS内部の同期状態を表すlast_synced_atなど、
     * ブログ自体の情報変更とは異なる管理情報については、
     * 通常のブログ情報変更履歴としては記録しない。
     */
    public function up(): void
    {
        Schema::create('blog_histories', function (Blueprint $table) {
            // 履歴レコード自身を一意に識別するID
            $table->id();

            // 変更対象となったブログのID
            // blogs.idを参照する
            $table->foreignId('blog_id')->constrained('blogs');

            // 変更されたblogsテーブルの項目名
            //
            // 例：
            // name
            // description
            // url
            // gmt_offset
            // timezone
            //
            // homeはブログ識別用の固定値として扱うため、
            // 登録後の変更履歴対象には含めない。
            //
            // is_selectedはBlogOS内部の選択状態、
            // last_synced_atは同期状態を表す項目であるため、
            // 通常のブログ情報変更履歴とは分けて扱う。
            $table->string('field', 255);

            // 変更前の値
            // 新規設定など、変更前の値が存在しない場合はNULL
            $table->text('old_value')->nullable();

            // 変更後の値
            // 値が削除された場合はNULL
            $table->text('new_value')->nullable();

            // 変更が発生した経路
            //
            // 例：
            // api
            // manual
            // system
            $table->string('source', 255);

            // 変更が発生した日時
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     *
     * blog_historiesテーブルを削除する。
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_histories');
    }
};
