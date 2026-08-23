<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * blogsテーブルを作成する。
     *
     * このテーブルには、BlogOSが管理するブログの基本情報を保存する。
     *
     * WordPress REST API（/wp-json）から取得したブログ情報を基に、
     * ブログ名、説明、URL、タイムゾーンなどの現在の状態を保持する。
     *
     * BlogOSでは将来的に複数のブログを管理することを想定しているため、
     * 各ブログを一意に識別するIDを持たせ、
     * 他のブログ関連テーブルからblog_idで参照できる構成とする。
     */
    public function up(): void
    {
        Schema::create('blogs', function (Blueprint $table) {
            // ブログレコード自身を一意に識別するID
            // BlogOS内部で各ブログを識別するために使用する
            $table->id();

            // WordPress REST API（/wp-json）から取得したブログ名
            // 例：SIer's Note
            $table->string('name', 255);

            // WordPress REST API（/wp-json）から取得したブログの説明
            // ブログの概要・説明文などを保存する
            $table->text('description');

            // WordPressが認識しているサイトURL
            // 例：http://si-note.com
            $table->string('url', 255);

            // WordPressが返すブログのホームURL
            // WordPress REST APIへアクセスするためのサイトURL
            // /wp-jsonなどのREST APIの基準URLとして使用する
            // BlogOS上でブログを一意に識別するためのURLとして使用する
            // 例：https://si-note.com
            // 同一URLのブログが複数登録されないよう一意制約を設定する
            $table->string('home', 255)->unique();

            // WordPress REST API（/wp-json）から取得したGMTからの時差
            // 例：日本の場合は9.00
            // 小数点以下2桁まで保持する
            $table->decimal('gmt_offset', 4, 2);

            // WordPress REST API（/wp-json）のtimezone_stringから取得した
            // ブログで使用されているタイムゾーン
            // 例：Asia/Tokyo
            $table->string('timezone', 255);

            // レコードの作成日時・更新日時
            //
            // created_at：
            //   blogsテーブルにブログ情報が新規登録された日時
            //
            // updated_at：
            //   blogsテーブルのブログ情報が更新された日時
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * blogsテーブルを削除する。
     *
     * マイグレーションをロールバックした場合に、
     * blogsテーブルを削除するために使用する。
     */
    public function down(): void
    {
        Schema::dropIfExists('blogs');
    }
};
