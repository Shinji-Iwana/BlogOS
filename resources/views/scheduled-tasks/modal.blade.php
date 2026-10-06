{{--
    定期実行の設定のポップアップ（D-63）。1つの定期実行の「いつ」（毎日・毎週・時刻）と「有効」の設定。

    メニューの「設定 → 定期実行 → WordPressとの同期」などを押した場合に表示する（data-modal-open="scheduled-blogs-sync-modal" など。
    App\Support\ScheduledTasks::MENU の定期実行ごとに、layouts/header から読み込む）。
    テーマ切替・課金の登録のポップアップと同じ形。今すぐ実行のボタンは付けない（メニューの「設定 → 即時実行」にある）。
    保存は scheduled-tasks.update。保存した後は、開いていた画面に戻る。
    有効・無効をブログごとに決める定期実行（教材の定期チェック）は、選択中のブログの設定も保存する（scheduled-tasks.update-blog。D-63-03）。
    入力の誤りで戻ったときは、ポップアップを開いたままにして誤りを出す。
    受け取る値：$taskKey（App\Support\ScheduledTasks のキー。例：blogs:sync）・$modalId・$title
--}}

@php
    $scheduledTask = \App\Support\ScheduledTasks::get($taskKey);
    $scheduleService = app(\App\Services\Schedule\ScheduledTaskService::class);
    $scheduleSetting = $scheduleService->setting($taskKey);
    $scheduleFailed = old('_form') === $modalId && $errors->any();
    $scheduleValues = $scheduleFailed
        ? ['frequency' => old('frequency'), 'weekday' => old('weekday') !== null ? (int) old('weekday') : null, 'time' => old('time'), 'enabled' => (bool) old('enabled')]
        : $scheduleSetting;

    // 有効・無効をブログごとに決める定期実行（教材の定期チェック）：有効は、選択中のブログの AI の設定（D-63-03）
    $blogColumn = $scheduledTask['blog_setting'] ?? null;
    if ($blogColumn !== null && ! $scheduleFailed) {
        $scheduleValues['enabled'] = $selectedBlog !== null && (bool) app(\App\Repositories\BlogAiSettingRepository::class)->forBlog($selectedBlog)->{$blogColumn};
    }
    $blogTip = match ($taskKey) {
        'materials:check' => '選択中のブログ（' . ($selectedBlog?->display_name ?? 'なし') . '）で有効にすると、前回の調査から' . config('blogos.materials.check.interval_months')
            . 'か月が過ぎた教材を、1日に' . config('blogos.materials.check.daily_limit') . "件まで、AI で調べ直します（API実行・Web検索を使う・料金がかかります。モデルは「教材の調査」の標準）。\n"
            . '新しい版・後継の講座などは「新しい教材の候補」、情報の変化は「教材の情報の案」になり、「教材の案の確認」で人が確認して登録します。有効・無効は、ブログごとに決めます。',
        default => '選択中のブログ（' . ($selectedBlog?->display_name ?? 'なし') . '）で有効にするかを決めます。有効・無効は、ブログごとに決めます。',
    };
@endphp

<div
    id="{{ $modalId }}"
    class="blog-switch-modal site-modal schedule-modal"
    @if ($scheduleFailed) data-modal-autoopen @endif
>

    <div
        class="blog-switch-dialog"
    >

        <h2>
            {{ $title }}
            @include('partials.tip', ['tip' => "定期実行：{$scheduledTask['description']}。\n時刻は日本時間です。変えると、次の定期実行から反映されます（サーバーでの作業は要りません）。\n今すぐ実行はメニューの「設定 → 即時実行」、実行の記録はメニューの「履歴 → 定期実行履歴」にあります。"])
        </h2>

        @if ($scheduleFailed)
            <ul class="text-error">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        @foreach ($scheduleService->orderWarnings()[$taskKey] ?? [] as $warning)
            <p class="text-error">順番の注意：{{ $warning }}</p>
        @endforeach

        <form
            method="POST"
            action="{{ $blogColumn !== null ? route('scheduled-tasks.update-blog', ['key' => $taskKey]) : route('scheduled-tasks.update', ['key' => $taskKey]) }}"
        >

            @csrf
            @method('PUT')
            <input type="hidden" name="_form" value="{{ $modalId }}">
            @if ($blogColumn !== null)
                @include('partials.selected-blog-field')
            @endif

            <p>
                いつ：
                <select name="frequency" onchange="this.form.querySelector('.weekday').style.display = this.value === 'weekly' ? '' : 'none'">
                    <option value="daily" @selected($scheduleValues['frequency'] === 'daily')>毎日</option>
                    <option value="weekly" @selected($scheduleValues['frequency'] === 'weekly')>毎週</option>
                </select>
                <select name="weekday" class="weekday" @if ($scheduleValues['frequency'] !== 'weekly') style="display:none" @endif>
                    @foreach (\App\Support\ScheduledTasks::WEEKDAYS as $index => $weekday)
                        <option value="{{ $index }}" @selected($scheduleValues['weekday'] === $index)>{{ $weekday }}曜</option>
                    @endforeach
                </select>
                <input type="time" name="time" value="{{ $scheduleValues['time'] }}" step="60" required>
                @include('partials.tip', ['tip' => '毎日、または毎週の決まった曜日の、この時刻（日本時間）に実行します。'])
            </p>

            @if ($blogColumn !== null)
                <p>
                    <label><input type="checkbox" name="enabled" value="1" @checked($scheduleValues['enabled'])> 有効（{{ $selectedBlog?->display_name ?? 'ブログが選ばれていません' }}）</label>
                    @include('partials.tip', ['tip' => $blogTip])
                </p>
            @elseif ($scheduledTask['can_disable'])
                <p>
                    <label><input type="checkbox" name="enabled" value="1" @checked($scheduleValues['enabled'])> 有効</label>
                    @include('partials.tip', ['tip' => '外すと、定期実行をしません（メニューの「設定 → 即時実行」は使えます）。'])
                </p>
            @else
                {{-- 止められない定期実行（古い記録の削除）：有効のまま変えられない。送らない（保存の処理が、常に有効にする） --}}
                <p>
                    <label><input type="checkbox" checked disabled> 有効</label>
                    @include('partials.tip', ['tip' => $scheduledTask['manual']
                        ? 'この定期実行は止められません（止めると、保存期間を過ぎた記録が削除されず、データベースが増え続けるため）。時刻は変えられます。'
                        : '有効・無効は、画面「AIの設定」で決めます。時刻は変えられます。'])
                </p>
            @endif

            {{-- 次の実行と前回（D-63-06。ボタンの上） --}}
            @include('scheduled-tasks.run-info')

            <div
                class="blog-switch-actions"
            >

                {{-- 保存 --}}
                <button
                    type="submit"
                >
                    保存
                </button>

                {{-- キャンセル --}}
                <button
                    type="button"
                    class="btn-secondary"
                    data-modal-close
                >
                    キャンセル
                </button>

            </div>

        </form>

    </div>

</div>
