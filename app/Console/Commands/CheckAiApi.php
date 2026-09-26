<?php

namespace App\Console\Commands;

use App\Clients\OpenAi\OpenAiClient;
use App\Clients\OpenAi\OpenAiException;
use App\Services\Ai\AiApiPolicy;
use Illuminate\Console\Command;

/**
 * OpenAI APIにつながるか確かめる（APIキーの設定の確認用。D-24）。
 *
 * 短い指示を1回だけ送る（費用は $0.001 未満）。実行記録（ai_generations）には残さない。
 */
class CheckAiApi extends Command
{
    protected $signature = 'ai:check
        {--model=gpt-6-luna : 確認に使うモデル}';

    protected $description = 'OpenAI APIにつながるか（APIキーが使えるか）確かめる';

    public function handle(AiApiPolicy $policy): int
    {
        if (! $policy->isConfigured()) {
            $this->error('APIキーが設定されていません（.env の OPENAI_API_KEY）。設定したら php artisan config:clear を実行してください。');

            return self::FAILURE;
        }

        $model = (string) $this->option('model');
        $client = new OpenAiClient((string) config('services.openai.key'), (string) config('services.openai.base_url'), 60);

        try {
            $result = $client->respond($model, '「接続できました」とだけ返してください。', in_array('none', $policy->models()[$model]['efforts'] ?? [], true) ? 'none' : 'low', 200);
        } catch (OpenAiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $cost = $policy->cost($model, $result['input_tokens'], $result['cached_input_tokens'], $result['output_tokens']);

        $this->info("回答：{$result['text']}");
        $this->line("モデル：{$result['model']}・入力 {$result['input_tokens']}・出力 {$result['output_tokens']} トークン・費用の目安 $" . number_format((float) $cost, 6));

        return self::SUCCESS;
    }
}
