{{--
    記事の再評価のポップアップ（D-64。以前は画面「AIの設定」の「条件による自動の再評価」の欄。D-25）

    メニューの「設定 → 定期実行 → 記事の再評価」を押した場合に表示する（data-modal-open="scheduled-ai-auto-reevaluate-modal"。
    layouts/header が、App\Support\ScheduledTasks の modal_view で読み込む）。テーマ切替・定期実行のポップアップと同じ形。
    いつ（全ブログ共通の定期実行の時刻）と、選択中のブログの設定（有効・モデル・診断の後の編集案の作成）を、まとめて保存する（ai.settings.update）。
    説明は、各項目の「?」のツールチップに出す。保存した後は、開いていた画面に戻る。入力の誤りで戻ったときは、ポップアップを開いたままにする。
    受け取る値：$taskKey（ai:auto-reevaluate）・$modalId・$title
--}}

@php
    $scheduleService = app(\App\Services\Schedule\ScheduledTaskService::class);
    $apiPolicy = app(\App\Services\Ai\AiApiPolicy::class);
    $autoService = app(\App\Services\Ai\AutoReevaluationService::class);
    $reevaluationConfig = config('blogos.ai.auto_reevaluation');
    $reevaluationRevision = $autoService->followUpRevision(null, null);
    $reevaluationModels = $apiPolicy->models();
    $reevaluationSetting = $selectedBlog !== null ? app(\App\Repositories\BlogAiSettingRepository::class)->forBlog($selectedBlog) : null;
    $reevaluationFailed = old('_form') === $modalId && $errors->any();
    // 入力の誤りで戻ったときは、入力した値。そうでなければ、今の設定
    $value = fn (string $name, $current) => $reevaluationFailed ? old($name) : $current;
    $schedule = $scheduleService->setting($taskKey);
    $traffic = $reevaluationConfig['traffic'];
    $scopeThresholds = (array) config('blogos.ai.revision_scope_by_score');

    $tips = [
        'title' => "有効にしたブログで、次の条件に当てはまる公開中の記事を、自動で品質診断します（API実行・料金がかかります）。\n"
            . "・まだ評価していない記事、または評価した後に記事が更新された\n"
            . "・品質基準・品質診断のテンプレートのバージョンが変わった\n"
            . "・この記事へのリンクの数が、評価したときから変わった\n"
            . "・アクセスが落ちた：直近{$traffic['window_days']}日（Googleの数値が確定しない直近{$traffic['lag_days']}日を除く）のクリック数・表示回数が、その前の{$traffic['window_days']}日より" . ($traffic['drop_ratio'] * 100) . "%以上減った（前回の評価から{$traffic['cooldown_days']}日以上たった記事だけ）\n"
            . "・前回の評価から{$reevaluationConfig['periodic_days']}日が過ぎた\n"
            . "1日に自動で再評価するのは{$reevaluationConfig['daily_limit']}件まで（超えた分は翌日以降）。OpenAI の残高が足りなくなる見込みになったら止めます。今日の対象の記事は、画面「AIの設定」で確かめられます。",
        'time' => '毎日、または毎週の決まった曜日の、この時刻（日本時間）に実行します。全ブログ共通です。WordPress との同期・Google との同期の後の時刻にしてください。',
        'enabled' => '選択中のブログ（' . ($selectedBlog?->display_name ?? 'なし') . '）で、自動の再評価をするかを決めます。有効・無効とモデルは、ブログごとに決めます。',
        'model' => '品質診断に使うモデルと推論の深さ。モデルによって、選べる推論の深さが変わります（料金は1Mトークンあたりの米ドル）。',
        'revision' => "自動の再評価の後、基準（{$reevaluationRevision['below_score']}点）未満、または必須条件を満たさない記事の編集案を、自動で作ります。\n"
            . "記事に作業中の編集案があれば、記事ではなく編集案を診断します。AI が作ったままの編集案は、その編集案を改修します（人が手を入れた編集案は、診断だけ）。\n"
            . "改修の後に、できた編集案も品質診断し、改修前後の点数を記録します（まとめて実行の画面・編集案の画面で確認できます）。\n"
            . '作業中の編集案がある記事は、人の作業を上書きしないため改修しません。作った編集案は、人が確認してから反映します（WordPressへの反映は自動では行いません）。',
        'rounds' => "改修の後の診断で、まだ基準に満たない場合に、改修と診断を繰り返す上限の回数（1回なら、繰り返さない）。\n"
            . "改修しても点数が上がらなかった記事は、上限の前でも止めます。上限まで改修しても基準に届かなかった記事は、人が確認します。\n"
            . "1回の繰り返しごとに、改修と診断の費用がかかります。1日の件数（{$reevaluationConfig['daily_limit']}件）には、最初の診断だけを数えます。",
        'scope' => "編集案で、記事をどこまで直すか。\n点数で自動判別：{$scopeThresholds['minor']}点以上は軽微な改善、{$scopeThresholds['restructure']}点以上は構成の見直し、それ未満は全面改修。",
    ];
@endphp

<div
    id="{{ $modalId }}"
    class="blog-switch-modal site-modal reevaluation-modal"
    @if ($reevaluationFailed) data-modal-autoopen @endif
>

    <div
        class="blog-switch-dialog reevaluation-dialog"
    >

        <h2>
            {{ $title }}
            @include('partials.tip', ['tip' => $tips['title']])
        </h2>

        @unless ($apiPolicy->isConfigured())
            <p class="text-error">APIキーが設定されていないため、有効にしても実行されません（.env の OPENAI_API_KEY）。</p>
        @endunless

        @if ($reevaluationFailed)
            <ul class="text-error">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        @foreach ($scheduleService->orderWarnings()[$taskKey] ?? [] as $warning)
            <p class="text-error">順番の注意：{{ $warning }}</p>
        @endforeach

        @if ($reevaluationSetting === null)
            <p>ブログが選ばれていません。ヘッダーのブログ名から、ブログを選んでください。</p>
        @else

        <form
            method="POST"
            action="{{ route('ai.settings.update') }}"
            data-reevaluation-form
        >

            @csrf
            @method('PUT')
            @include('partials.selected-blog-field')
            <input type="hidden" name="_form" value="{{ $modalId }}">

            <p>
                いつ：
                <select name="frequency" onchange="this.form.querySelector('.weekday').style.display = this.value === 'weekly' ? '' : 'none'">
                    <option value="daily" @selected($value('frequency', $schedule['frequency']) === 'daily')>毎日</option>
                    <option value="weekly" @selected($value('frequency', $schedule['frequency']) === 'weekly')>毎週</option>
                </select>
                <select name="weekday" class="weekday" @if ($value('frequency', $schedule['frequency']) !== 'weekly') style="display:none" @endif>
                    @foreach (\App\Support\ScheduledTasks::WEEKDAYS as $index => $weekday)
                        <option value="{{ $index }}" @selected((string) $value('weekday', $schedule['weekday']) === (string) $index)>{{ $weekday }}曜</option>
                    @endforeach
                </select>
                <input type="time" name="time" value="{{ $value('time', $schedule['time']) }}" step="60" required>
                @include('partials.tip', ['tip' => $tips['time']])
            </p>

            <p>
                <input type="hidden" name="auto_reevaluation_enabled" value="0">
                <label><input type="checkbox" name="auto_reevaluation_enabled" value="1" @checked($value('auto_reevaluation_enabled', $reevaluationSetting->auto_reevaluation_enabled))> 有効（{{ $selectedBlog->display_name }}）</label>
                @include('partials.tip', ['tip' => $tips['enabled']])
            </p>

            <p>
                <label>モデル
                    <select name="auto_model" data-model>
                        @foreach ($reevaluationModels as $name => $price)
                            <option value="{{ $name }}" data-efforts="{{ implode(',', $price['efforts']) }}" @selected($value('auto_model', $reevaluationSetting->auto_model) === $name)>{{ $name }}（入力 ${{ $price['input'] }}・出力 ${{ $price['output'] }}）</option>
                        @endforeach
                    </select>
                </label>
                <label>推論の深さ
                    <select name="auto_reasoning_effort" data-effort>
                        @foreach (['none' => 'none（推論なし）', 'low' => 'low', 'medium' => 'medium', 'high' => 'high'] as $effortValue => $effortLabel)
                            <option value="{{ $effortValue }}" @selected($value('auto_reasoning_effort', $reevaluationSetting->auto_reasoning_effort) === $effortValue)>{{ $effortLabel }}</option>
                        @endforeach
                    </select>
                </label>
                @include('partials.tip', ['tip' => $tips['model']])
            </p>

            <p>
                <strong>診断の後の編集案の作成</strong>
                @include('partials.tip', ['tip' => $tips['revision']])
            </p>
            <p>
                <input type="hidden" name="auto_revision_enabled" value="0">
                <label><input type="checkbox" name="auto_revision_enabled" value="1" @checked($value('auto_revision_enabled', $reevaluationSetting->auto_revision_enabled))> 基準（{{ $reevaluationRevision['below_score'] }}点）未満の記事の編集案を作る</label>
            </p>
            <p>
                <label>モデル
                    <select name="auto_revision_model">
                        @foreach ($reevaluationModels as $name => $price)
                            <option value="{{ $name }}" @selected($value('auto_revision_model', $reevaluationSetting->auto_revision_model ?? $reevaluationRevision['model']) === $name)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>推論の深さ
                    <select name="auto_revision_reasoning_effort">
                        @foreach (['none', 'low', 'medium', 'high'] as $effortValue)
                            <option value="{{ $effortValue }}" @selected($value('auto_revision_reasoning_effort', $reevaluationSetting->auto_revision_reasoning_effort ?? $reevaluationRevision['effort']) === $effortValue)>{{ $effortValue }}</option>
                        @endforeach
                    </select>
                </label>
                <label>改修範囲
                    <select name="auto_revision_scope">
                        @foreach (\App\Services\Ai\AutoReevaluationService::scopeOptions() as $scopeValue => $scopeLabel)
                            {{-- 点数で自動判別の、点数ごとの改修範囲は、ツールチップに出す（選択欄が長くなり、ずれるため） --}}
                            <option value="{{ $scopeValue }}" @selected($value('auto_revision_scope', $reevaluationSetting->auto_revision_scope ?? 'auto') === $scopeValue)>{{ $scopeValue === \App\Services\Ai\AiBatchService::SCOPE_BY_SCORE ? '点数で自動判別' : $scopeLabel }}</option>
                        @endforeach
                    </select>
                </label>
                @include('partials.tip', ['tip' => $tips['scope']])
            </p>
            <p>
                <label>改修の繰り返し（最大）
                    <select name="auto_revision_max_rounds">
                        @foreach (range(1, (int) $reevaluationConfig['max_revision_rounds']) as $rounds)
                            <option value="{{ $rounds }}" @selected((int) $value('auto_revision_max_rounds', $reevaluationSetting->auto_revision_max_rounds ?? $reevaluationConfig['default_revision_rounds']) === $rounds)>{{ $rounds }}回</option>
                        @endforeach
                    </select>
                </label>
                @include('partials.tip', ['tip' => $tips['rounds']])
            </p>

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

        {{-- モデルで選べない推論の深さを、選べないようにする（以前は画面「AIの設定」にあった） --}}
        <script>
            (() => {
                const form = document.querySelector('[data-reevaluation-form]');
                const model = form.querySelector('[data-model]');
                const effort = form.querySelector('[data-effort]');
                const sync = () => {
                    const allowed = model.selectedOptions[0].dataset.efforts.split(',');
                    [...effort.options].forEach((option) => { option.disabled = ! allowed.includes(option.value); });
                    if (effort.selectedOptions[0].disabled) { effort.value = allowed.includes('medium') ? 'medium' : allowed[0]; }
                };
                model.addEventListener('change', sync);
                sync();
            })();
        </script>

        @endif

    </div>

</div>
