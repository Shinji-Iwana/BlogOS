<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 月1回のバックアップ（D-89）。作った回ごとに1行。ファイルはサーバーの storage/app/backups/{name} にあり、
 * 残す回数を超えた古い回は、ファイルと一緒に行も消す。
 * 定期実行を毎月の日にちで動かせるよう、定期実行の設定に「毎月の日」を加える。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            // フォルダの名前（作り始めた日本時間。例：20261101-051000）
            $table->string('name', 32)->unique();
            // running：作成中、succeeded：成功、failed：失敗
            $table->string('status', 16);
            // ファイルの一覧（種類・ファイル名・大きさ・ダウンロードした日時）
            $table->json('files')->nullable();
            $table->unsignedBigInteger('total_bytes')->nullable();
            // 失敗の理由・注意
            $table->text('message')->nullable();
            // XServer の MySQL は初期値のない NOT NULL の timestamp を作れないため、null を許す（ほかの記録のテーブルと同じ）
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // トップページで「作成しました」を出した日時（1回だけ出す）
            $table->timestamp('announced_at')->nullable();
            // 全てのファイルをダウンロードした日時
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();
        });

        Schema::table('scheduled_task_settings', function (Blueprint $table) {
            // 毎月の場合の日（1〜28）
            $table->unsignedTinyInteger('monthday')->nullable()->after('weekday');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_task_settings', function (Blueprint $table) {
            $table->dropColumn('monthday');
        });
        Schema::dropIfExists('backups');
    }
};
