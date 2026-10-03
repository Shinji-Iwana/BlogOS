<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BlogOS 全体の設定（ブログごとではない設定。D-49）。
 *
 * 最初は画面のテーマ（theme）だけ。行がない設定は、config の既定で動く（行は、画面で変えたときに作る）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            // 設定のキー（例：theme）
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
