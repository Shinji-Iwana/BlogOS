<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * API実行の記録（D-24）：トークン数の内訳（料金の単価が違う）と、OpenAIの応答のID。
     */
    public function up(): void
    {
        Schema::table('ai_generations', function (Blueprint $table) {
            // input_tokens のうち、キャッシュが効いた分（安い単価）
            $table->unsignedInteger('cached_input_tokens')->nullable()->after('input_tokens');
            // output_tokens のうち、推論に使った分（出力の単価で課金される）
            $table->unsignedInteger('reasoning_tokens')->nullable()->after('output_tokens');
            $table->string('provider_response_id', 100)->nullable()->after('provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_generations', function (Blueprint $table) {
            foreach (['cached_input_tokens', 'reasoning_tokens', 'provider_response_id'] as $column) {
                if (Schema::hasColumn('ai_generations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
