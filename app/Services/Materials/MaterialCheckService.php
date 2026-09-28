<?php

namespace App\Services\Materials;

use App\Enums\AiExecutionMethod;
use App\Enums\AiMode;
use App\Models\Blog;
use App\Models\Material;
use App\Repositories\BlogAiSettingRepository;
use App\Repositories\MaterialRepository;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiApiUnavailableException;
use App\Services\Ai\AiException;
use App\Services\Ai\AiRunService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 教材の定期チェック（D-30）。
 *
 * 前回の調査から一定の期間（標準は6か月）が過ぎた教材を、AIで調べ直す（Web検索を使うAPI実行）。
 * 新しい版・後継の講座・同じ著者の関連の教材は「新しい教材の候補」になり、情報の変化は「教材の情報の案」になる。
 * どちらも人が確認して登録する。1日に調べる数に上限を付け、残りは翌日以降に回す。
 */
class MaterialCheckService
{
    public function __construct(
        protected MaterialRepository $materials,
        protected BlogAiSettingRepository $settings,
        protected AiRunService $runService,
        protected AiApiPolicy $apiPolicy,
    ) {
    }

    /**
     * @return Collection<int, Material> 今日調べる教材
     */
    public function due(Blog $blog): Collection
    {
        $before = Carbon::now()->subMonths((int) config('blogos.materials.check.interval_months'));

        return $this->materials->dueForCheck($blog->id, $before, (int) config('blogos.materials.check.daily_limit'));
    }

    /**
     * @return array{started: int, errors: list<string>}
     */
    public function run(Blog $blog): array
    {
        if (! $this->settings->forBlog($blog)->material_check_enabled || $blog->isArchived()) {
            return ['started' => 0, 'errors' => []];
        }

        $defaults = $this->apiPolicy->defaults(AiMode::MaterialResearch);
        $started = 0;
        $errors = [];

        foreach ($this->due($blog) as $material) {
            try {
                $this->runService->start(AiMode::MaterialResearch, $blog, null, null, ['調べる理由' => '定期チェック（新しい版・販売の状況・情報の変化の確認）'], null, null,
                    AiExecutionMethod::Api, $defaults['model'], $defaults['effort'], material: $material, webSearch: true);
                $started++;
            } catch (AiApiUnavailableException $e) {
                // APIキーがない・費用の上限：残りも実行しない
                $errors[] = $e->getMessage();
                break;
            } catch (AiException $e) {
                $errors[] = "{$material->name}：{$e->getMessage()}";
            }
        }

        return ['started' => $started, 'errors' => $errors];
    }
}
