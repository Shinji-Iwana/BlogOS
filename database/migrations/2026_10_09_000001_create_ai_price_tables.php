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
     * D-31-03：API実行の料金表をDBに持ち、毎日、OpenAIの公式のページと比べる。
     * 初期値は config/blogos.php の ai.api.models・long_context・web_search。
     */
    public function up(): void
    {
        // 料金表（1Mトークンあたりの米ドル）。price_key はモデル名、または web_search
        Schema::create('ai_prices', function (Blueprint $table) {
            $table->id();
            $table->string('price_key', 100)->unique();
            $table->decimal('input', 12, 6)->nullable();
            $table->decimal('cached_input', 12, 6)->nullable();
            $table->decimal('cache_write', 12, 6)->nullable();
            $table->decimal('output', 12, 6)->nullable();
            // Web検索：1回あたりの料金
            $table->decimal('per_call', 12, 6)->nullable();
            // 長い入力：この入力のトークン数を超えたら、その1回すべてを 入力・キャッシュ×input、出力×output の料金にする
            $table->unsignedInteger('long_context_threshold')->nullable();
            $table->decimal('long_input_multiplier', 6, 3)->nullable();
            $table->decimal('long_output_multiplier', 6, 3)->nullable();
            // 最後に公式のページと照合できた日時
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        // 料金の変更（公式のページとの差）。値上がりは自動で反映し、値下がりは人が確認して反映する
        Schema::create('ai_price_changes', function (Blueprint $table) {
            $table->id();
            $table->string('price_key', 100);
            $table->string('field', 50);
            $table->decimal('old_value', 12, 6)->nullable();
            $table->decimal('new_value', 12, 6);
            // 状態（App\Enums\AiPriceChangeStatus：applied / pending / rejected / superseded）
            $table->string('status', 20);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'price_key']);
        });

        // 公式のページとの照合の記録
        Schema::create('ai_price_checks', function (Blueprint $table) {
            $table->id();
            // succeeded：すべて照合できた / failed：一部または全部を読み取れなかった
            $table->string('status', 20);
            $table->json('messages')->nullable();
            $table->unsignedInteger('applied_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->timestamps();
        });

        $now = now();
        $long = (array) config('blogos.ai.api.long_context');
        foreach ((array) config('blogos.ai.api.models') as $model => $price) {
            DB::table('ai_prices')->insert([
                'price_key'              => $model,
                'input'                  => $price['input'],
                'cached_input'           => $price['cached_input'],
                'cache_write'            => $price['cache_write'] ?? $price['input'],
                'output'                 => $price['output'],
                'long_context_threshold' => $long['threshold_tokens'] ?? null,
                'long_input_multiplier'  => $long['input_multiplier'] ?? null,
                'long_output_multiplier' => $long['output_multiplier'] ?? null,
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);
        }
        DB::table('ai_prices')->insert([
            'price_key'  => 'web_search',
            'per_call'   => (float) config('blogos.ai.api.web_search.cost_per_call'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_price_checks');
        Schema::dropIfExists('ai_price_changes');
        Schema::dropIfExists('ai_prices');
    }
};
