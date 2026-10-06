<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Jobs\RunScheduledTaskJob;
use App\Models\ScheduledTaskRun;
use App\Repositories\BlogAiSettingRepository;
use App\Services\Schedule\ScheduledTaskService;
use App\Support\ScheduledTasks;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * 定期実行の時刻の変更・今すぐ実行・履歴（D-44）。定期実行はブログ全体の処理のため、選択中のブログに関係しない。
 * 画面「定期実行」はなくし、時刻の変更はメニューの「設定 → 定期実行」、今すぐ実行は「設定 → 即時実行」から送る（D-63-10）。
 * 時刻の変更は、メニューの「設定 → 定期実行」のポップアップ（scheduled-tasks/modal。D-63）からもできる。
 */
class ScheduledTaskController extends Controller
{
    use UsesSelectedBlog;

    public function __construct(
        protected ScheduledTaskService $service,
        protected BlogAiSettingRepository $aiSettings,
    ) {
    }


    /**
     * 定期実行履歴（メニューの「履歴 → 定期実行履歴」。定期実行ごとにしぼり込める。D-63-08）
     */
    public function runs(Request $request)
    {
        $key = array_key_exists((string) $request->query('task'), ScheduledTasks::TASKS) ? (string) $request->query('task') : null;

        return view('scheduled-tasks.runs', [
            'runs'   => ScheduledTaskRun::with('requester:id,name')->when($key !== null, fn ($q) => $q->where('task_key', $key))
                ->latest('started_at')->latest('id')->paginate(50)->withQueryString(),
            'filter' => $key,
        ]);
    }

    public function update(Request $request, string $key)
    {
        $task = ScheduledTasks::get($key);
        abort_if($task === null, 404);

        $warnings = $this->service->save($key, $this->validated($request), $request->user()?->id);

        return $this->saved($task, $warnings);
    }

    /**
     * いつと、選択中のブログの有効・無効を保存する（有効・無効をブログごとに決める定期実行。例：教材の定期チェック。D-63-03）
     */
    public function updateWithBlog(Request $request, string $key)
    {
        $task = ScheduledTasks::get($key);
        abort_if($task === null || ! isset($task['blog_setting']), 404);
        $blog = $this->selectedBlog();
        $validated = $this->validated($request);

        $warnings = $this->service->save($key, $validated, $request->user()?->id);

        // ブログの AI の設定がまだなければ、既定の値で作る（自動の再評価のモデルなど）
        $current = $this->aiSettings->forBlog($blog);
        $enabled = (bool) ($validated['enabled'] ?? false);
        $this->aiSettings->save($blog, [$task['blog_setting'] => $enabled] + ($current->exists ? [] : Arr::except($current->getAttributes(), ['blog_id'])), $request->user()?->id);

        return $this->saved($task, $warnings, "（{$blog->display_name}：" . ($enabled ? '有効' : '無効') . '）');
    }

    /**
     * @return array{frequency: string, weekday?: int|string|null, time: string, enabled?: bool|string|null}
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'weekday'   => ['nullable', 'required_if:frequency,weekly', 'integer', 'between:0,6'],
            'time'      => ['required', 'date_format:H:i'],
            'enabled'   => ['nullable', 'boolean'],
        ]);
    }

    /**
     * 開いていた画面に戻る（メニューのポップアップから保存する。D-63）
     *
     * @param  list<string>  $warnings
     */
    protected function saved(array $task, array $warnings, string $suffix = '')
    {
        $redirect = back()->with('status', "「{$task['label']}」の設定を保存しました{$suffix}（次の定期実行から反映されます）。");

        return $warnings === [] ? $redirect : $redirect->withErrors(['order' => "「{$task['label']}」：" . implode(' ', $warnings)]);
    }

    public function run(Request $request, string $key)
    {
        $task = ScheduledTasks::get($key);
        abort_if($task === null || ! $task['manual'], 404);

        $running = ScheduledTaskRun::where('task_key', $key)->where('status', 'running')
            ->where('started_at', '>=', now()->subHours(ScheduledTaskRun::STALE_HOURS))->exists();
        if ($running) {
            return back()->withErrors(['run' => "「{$task['label']}」は実行中です。終わってから実行してください。"]);
        }

        RunScheduledTaskJob::dispatch($key, $request->user()?->id);

        // メニューの即時実行から送る（D-63-09）。結果は、定期実行履歴で見る
        return back()->with('status', '「' . ScheduledTasks::menuLabel($key) . '」を Queue に登録しました（結果は、しばらくしてから「定期実行履歴」で確認できます）。');
    }
}
