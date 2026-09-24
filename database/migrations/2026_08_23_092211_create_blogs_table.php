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
     * また、WordPress REST APIへ接続するためのAPI基準URLや、
     * BlogOS側で最後に同期を確認した日時など、
     * BlogOSによるブログ管理情報も保持する。
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
            // 例：SI Note
            $table->string('name', 255);

            // WordPress REST API（/wp-json）から取得したブログの説明
            // ブログの概要・説明文などを保存する
            $table->text('description');

            // WordPressが認識しているサイトURL
            // WordPress REST API（/wp-json）のurlから取得した値を保存する
            // 例：https://si-note.com
            $table->string('url', 255);

            // WordPressが返すブログのホームURL
            //
            // WordPressサイトそのものの基準URLとして使用する。
            //
            // BlogOSでは、登録対象となるブログを一意に識別するための
            // 固有の値として使用する。
            //
            // homeは新規登録時にはblogsテーブルへ保存するが、
            // 登録後のブログ更新処理では変更対象としない。
            //
            // 同一URLのブログが複数登録されないよう、
            // 一意制約を設定する。
            //
            // 例：https://si-note.com
            $table->string('home', 255)->unique();

            // WordPress REST API（/wp-json）から取得したGMTからの時差
            // 例：日本の場合は9.00
            // 小数点以下2桁まで保持する
            $table->decimal('gmt_offset', 4, 2);

            // WordPress REST API（/wp-json）のtimezone_stringから取得した
            // ブログで使用されているタイムゾーン
            //
            // 例：Asia/Tokyo
            //
            // DB上ではtimezoneという名前で保持する。
            $table->string('timezone', 255);

            // 現在BlogOSで操作対象として選択されているかどうか。
            //
            // true：
            //     現在選択中のブログ。
            //
            // false：
            //     現在選択されていないブログ。
            $table->boolean('is_selected')->default(false);

            // BlogOSがWordPressとの同期処理を最後に実行した日時。
            //
            // この値はWordPress APIから取得する情報ではなく、
            // BlogOS側で同期状態を管理するための情報。
            //
            // ブログ登録直後など、まだ同期処理を実行していない場合は
            // NULLとする。
            $table->timestamp('last_synced_at')->nullable();

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
