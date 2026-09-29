<?php

namespace App\Http\Controllers;

use App\Jobs\RunScheduledTaskJob;
use App\Models\ScheduledTaskRun;
use App\Models\ScheduledTaskSetting;
use App\Services\Schedule\ScheduledTaskService;
use App\Support\ScheduledTasks;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 定期実行の確認・時刻の変更・今すぐ実行（D-44）。定期実行はブログ全体の処理のため、選択中のブログに関係しない。
 */
class ScheduledTaskController extends Controller
{
    public function __construct(
        protected ScheduledTaskService $service,
    ) {
    }

    public function index(Request $request)
    {
        $settings = $this->service->settings();
        $key = array_key_exists((string) $request->query('task'), ScheduledTasks::TASKS) ? (string) $request->query('task') : null;

        $tasks = [];
        foreach (ScheduledTasks::TASKS as $taskKey => $task) {
            $tasks[$taskKey] = $task + [
                'setting' => $this->service->setting($taskKey, $settings),
                'next'    => $this->service->nextRunAt($taskKey, $settings),
                'last'    => ScheduledTaskRun::where('task_key', $taskKey)->latest('started_at')->latest('id')->first(),
            ];
        }

        return view('scheduled-tasks.index', [
            'tasks'    => $tasks,
            'warnings' => $this->service->orderWarnings($settings),
            'runs'     => ScheduledTaskRun::with('requester:id,name')->when($key !== null, fn ($q) => $q->where('task_key', $key))
                ->latest('started_at')->latest('id')->paginate(50)->withQueryString(),
            'filter'   => $key,
        ]);
    }

    public function update(Request $request, string $key)
    {
        $task = ScheduledTasks::get($key);
        abort_if($task === null, 404);

        $validated = $request->validate([
            'frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'weekday'   => ['nullable', 'required_if:frequency,weekly', 'integer', 'between:0,6'],
            'time'      => ['required', 'date_format:H:i'],
            'enabled'   => ['nullable', 'boolean'],
        ]);

        ScheduledTaskSetting::updateOrCreate(['task_key' => $key], [
            'frequency'  => $validated['frequency'],
            'weekday'    => $validated['frequency'] === 'weekly' ? (int) $validated['weekday'] : null,
            'time'       => $validated['time'],
            // 画面で無効にできない定期実行は、常に有効にしておく
            'enabled'    => ! $task['can_disable'] || (bool) ($validated['enabled'] ?? false),
            'updated_by' => $request->user()?->id,
        ]);

        $warnings = $this->service->orderWarnings()[$key] ?? [];
        $redirect = redirect()->route('scheduled-tasks.index')->with('status', "「{$task['label']}」の設定を保存しました（次の定期実行から反映されます）。");

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

        return back()->with('status', "「{$task['label']}」を Queue に登録しました（しばらくしてから、この画面を開き直してください）。");
    }
}
