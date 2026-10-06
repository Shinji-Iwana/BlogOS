{{-- ==============================================================
     BlogOS共通ヘッダー
     --------------------------------------------------------------
     BlogOSのトップページ・設定ページ・各種一覧・詳細ページなど、
     layouts.appを利用するすべてのページで共通表示する。

     ヘッダーには、

     ・左端：BlogOS（設定は、トップページの「管理」の入口にある。D-60）
     ・右端：マイク（音声の操作）・現在選択中のブログ名ボタン・ログアウト

     を表示する。

     現在選択中のブログ名ボタンを押すと、
     ブログ切替ポップアップを表示する。
     ============================================================== --}}

{{-- ヘッダーとメニューバーをまとめる枠（テーマで、スクロールしても上に残すときに使う。D-56） --}}
<div class="site-top">

<header
    class="site-header"
>

    {{-- ==========================================================
         ヘッダー左側
         ----------------------------------------------------------
         BlogOSのタイトルを表示する（設定は、トップページの「管理」の入口に移した。D-60）。
         ========================================================== --}}

    <div
        class="site-header-left"
    >

        {{-- BlogOSタイトル --}}
        <a
            href="{{ route('home') }}"
            class="site-title"
        >
            BlogOS
        </a>


    </div>


    {{-- ==========================================================
         ヘッダー右側
         ----------------------------------------------------------
         現在選択中のブログ名をボタンとして表示し、その右隣にログアウトのアイコンを置く（D-54）。
         ========================================================== --}}

    <div class="site-header-right">

        {{-- 音声の操作（マイク。押して話し、もう一度押すか少し黙ると送る。D-58）。有効にしているときだけ --}}
        @if (app(\App\Services\Voice\VoiceSettings::class)->available())
            <button
                type="button"
                id="voice-button"
                class="voice-button"
                title="話しかける（音声の操作）"
                aria-label="話しかける（音声の操作）"
                data-turn-url="{{ route('voice.turn') }}"
                data-reset-url="{{ route('voice.reset') }}"
                data-max-seconds="{{ (int) config('blogos.voice.max_seconds') }}"
                data-mode="{{ app(\App\Services\Voice\VoiceSettings::class)->mode() }}"
                data-session-url="{{ route('voice.realtime.session') }}"
                data-tool-url="{{ route('voice.realtime.tool') }}"
                data-usage-url="{{ route('voice.realtime.usage') }}"
                data-home-url="{{ route('home') }}"
            >
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="9" y="3" width="6" height="11" rx="3"/>
                    <path d="M5 11a7 7 0 0 0 14 0"/>
                    <path d="M12 18v3"/>
                </svg>
            </button>
        @endif

        {{-- 選択中のブログがない場合も、切り替えられるようボタンを表示する --}}
        @if ($blogs->isNotEmpty())

            <button
                type="button"
                id="blog-switch-open"
                class="blog-switch-button"
            >
                {{ $selectedBlog?->display_name ?? '（ブログを選択）' }}
            </button>

        @endif

        {{-- ログアウト（電源のアイコン。押すとログアウトし、ログイン画面に戻る） --}}
        <form method="POST" action="{{ route('logout') }}" class="logout-form">
            @csrf
            <button type="submit" class="logout-button" title="ログアウト" aria-label="ログアウト">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3v9"/>
                    <path d="M6.3 6.8a8 8 0 1 0 11.4 0"/>
                </svg>
            </button>
        </form>

    </div>

</header>


{{-- ==============================================================
     メニューバー（D-56）
     --------------------------------------------------------------
     どの画面からでも開けるよう、ヘッダーの下に出す。項目は App\Support\MenuItems。
     種類（英字の札と名前）を押すと、項目が開く（details。public/js/blogos.js が、外を押したら閉じる）。
     ============================================================== --}}

<nav class="site-menu" aria-label="メニュー" @if (request()->routeIs('home')) data-drawer-links @endif>
    @foreach (\App\Support\MenuItems::groups() as $group)
        <details class="site-menu-group">
            <summary>
                <span class="site-menu-code">{{ $group['code'] }}</span>
                <span class="site-menu-label">{{ $group['label'] }}</span>
            </summary>
            <ul class="site-menu-links">
                @foreach ($group['links'] as $link)
                    @if ($link['children'] !== [])
                        {{-- 下の階層：押すと横に開く（スマホは下に開く） --}}
                        <li class="site-menu-sub">
                            <details>
                                <summary>{{ $link['label'] }}</summary>
                                <ul class="site-menu-links site-menu-sublinks">
                                    @foreach ($link['children'] as $child)
                                        @if ($child['post'])
                                            {{-- 押すと確認してから、その処理を送る（例：即時実行。D-63-09） --}}
                                            <li><a href="#" data-menu-post="{{ $child['post'] }}" data-menu-confirm="{{ $child['confirm'] }}">{{ $child['label'] }}</a></li>
                                        @else
                                            <li><a href="{{ $child['url'] }}" @if ($child['modal']) data-modal-open="{{ $child['modal'] }}" @endif>{{ $child['label'] }}</a></li>
                                        @endif
                                    @endforeach
                                </ul>
                            </details>
                        </li>
                    @else
                        <li><a href="{{ $link['url'] }}" @if ($link['modal']) data-modal-open="{{ $link['modal'] }}" @endif>{{ $link['label'] }}</a></li>
                    @endif
                @endforeach
            </ul>
        </details>
    @endforeach
</nav>

</div>


{{-- ==============================================================
     ブログ切替ポップアップ
     --------------------------------------------------------------
     現在のブログ名ボタンを押した場合に表示する。

     表示内容：

     ・ブログ名
     ・home
     ・選択用radio
     ・切替ボタン
     ・キャンセルボタン

     現在選択中のブログは初期状態で選択済みにする。
     ============================================================== --}}

@if ($blogs->isNotEmpty())

    <div
        id="blog-switch-modal"
        class="blog-switch-modal"
    >

        {{-- ======================================================
             ポップアップ本体
             ====================================================== --}}

        <div
            class="blog-switch-dialog"
        >

            <h2>
                ブログ切替
            </h2>


            {{-- ==================================================
                 ブログ一覧
                 ================================================== --}}

            <form
                method="POST"
                action="{{ route('blog-switch') }}"
            >

                @csrf

                @foreach ($blogs as $blog)

                    <label
                        class="blog-switch-option"
                    >

                        <input
                            type="radio"
                            name="blog_id"
                            value="{{ $blog->id }}"
                            @if ($selectedBlog && $selectedBlog->id === $blog->id)
                                checked
                            @endif
                        >

                        <strong>
                            {{ $blog->display_name }}
                        </strong>

                        <br>

                        <span>
                            {{ $blog->home }}
                        </span>

                    </label>

                @endforeach


                {{-- ==================================================
                     ポップアップ操作ボタン
                     ================================================== --}}

                <div
                    class="blog-switch-actions"
                >

                    {{-- ブログ切替 --}}
                    <button
                        type="submit"
                    >
                        切替
                    </button>

                    {{-- キャンセル --}}
                    <button
                        type="button"
                        id="blog-switch-cancel"
                        class="btn-secondary"
                    >
                        キャンセル
                    </button>

                </div>

            </form>

        </div>

    </div>

@endif


{{-- ==============================================================
     テーマ切替ポップアップ（D-57）
     --------------------------------------------------------------
     メニューの「画面のテーマ」を押した場合に表示する。
     data-modal-open="theme-switch-modal" を付けた要素で開く。ブログ切替ポップアップと同じ形。

     表示内容：テーマの名前・説明、選択用radio（使用中のテーマを選択済み）、切替ボタン、キャンセルボタン。
     切り替えた後は、開いていた画面に戻る。
     ============================================================== --}}

{{-- 1行の @php(...) と @php … @endphp を同じ画面に混ぜると、Blade が読み違えるため、ブロックで書く --}}
@php
    $headerThemes = app(\App\Services\ThemeService::class);
@endphp

<div
    id="theme-switch-modal"
    class="blog-switch-modal site-modal theme-switch-modal"
>

    <div
        class="blog-switch-dialog"
    >

        <h2>
            画面のテーマ
        </h2>

        <form
            method="POST"
            action="{{ route('settings.theme.update') }}"
        >

            @csrf
            @method('PUT')

            @foreach ($headerThemes->available() as $key => $theme)

                <label
                    class="blog-switch-option"
                >

                    <input
                        type="radio"
                        name="theme"
                        value="{{ $key }}"
                        @checked($key === $headerThemes->current())
                    >

                    <strong>
                        {{ $theme['label'] }}
                    </strong>
                    @if (($theme['status'] ?? '') === 'wip')（制作中）@endif

                    <br>

                    <span>
                        {{ $theme['description'] }}
                    </span>

                </label>

            @endforeach

            <div
                class="blog-switch-actions"
            >

                {{-- テーマ切替 --}}
                <button
                    type="submit"
                >
                    切替
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


{{-- ==============================================================
     音声操作ポップアップ（D-61）
     --------------------------------------------------------------
     メニューの「AI → 音声操作」を押した場合に表示する。中身は voice/settings-modal。
     ============================================================== --}}

@include('voice.settings-modal')


{{-- ==============================================================
     残高・課金の登録ポップアップ（D-62）
     --------------------------------------------------------------
     メニューの「AI → OpenAIの画面で見た残高を登録」「課金した額を登録」を押した場合に表示する。中身は ai/credits/modals。
     ============================================================== --}}

@include('ai.credits.modals')


{{-- ==============================================================
     Google連携のポップアップ（D-63-19）
     --------------------------------------------------------------
     メニューの「設定 → Google → アカウント」「Google Analytics 4」「Search Console」「AdSense」を押した場合に表示する。
     中身は google/modals。
     ============================================================== --}}

@include('google.modals')


{{-- ==============================================================
     定期実行の設定のポップアップ（D-63）
     --------------------------------------------------------------
     メニューの「定期実行 → WordPressとの同期」などを押した場合に表示する（App\Support\ScheduledTasks::MENU の定期実行ごとに1つ）。
     中身は scheduled-tasks/modal。
     ============================================================== --}}

@php
    // 各ポップアップの「次の実行」「前回」のため、設定と、定期実行ごとの最後の実行の記録をまとめて読む（D-63-06）
    $scheduleSettingsAll = app(\App\Services\Schedule\ScheduledTaskService::class)->settings();
    $scheduleLastRuns = \App\Models\ScheduledTaskRun::whereIn('id', \App\Models\ScheduledTaskRun::whereIn('task_key', \App\Support\ScheduledTasks::MENU)->groupBy('task_key')->selectRaw('MAX(id)'))
        ->get()->keyBy('task_key');
@endphp
@foreach (\App\Support\ScheduledTasks::MENU as $scheduledKey)
    {{-- 記事の再評価は、専用のポップアップ（ScheduledTasks の modal_view。D-64） --}}
    @include(\App\Support\ScheduledTasks::get($scheduledKey)['modal_view'] ?? 'scheduled-tasks.modal', [
        'taskKey'          => $scheduledKey,
        'modalId'          => \App\Support\ScheduledTasks::modalId($scheduledKey),
        'title'            => \App\Support\ScheduledTasks::menuLabel($scheduledKey) . '（定期実行）',
        'scheduleSettings' => $scheduleSettingsAll,
        'lastRun'          => $scheduleLastRuns->get($scheduledKey),
    ])
@endforeach


{{-- ==============================================================
     ブログ切替ポップアップ制御JavaScript
     --------------------------------------------------------------
     ・ブログ名ボタン押下 → ポップアップ表示
     ・キャンセル押下 → ポップアップ非表示
     ・背景クリック → ポップアップ非表示

     「切替」ボタンについてはformを通常POSTするため、
     JavaScriptによるDB更新は行わない。
     ============================================================== --}}

<script>
    document.addEventListener('DOMContentLoaded', function () {

        // ブログ切替ポップアップを開くためのボタン。
        const openButton = document.getElementById('blog-switch-open');

        // ブログ切替ポップアップ本体。
        const modal = document.getElementById('blog-switch-modal');

        // キャンセルボタン。
        const cancelButton = document.getElementById('blog-switch-cancel');


        // 必要な要素が存在しない場合は何もしない。
        if (!openButton || !modal || !cancelButton) {
            return;
        }


        // ==========================================================
        // ブログ名ボタン押下
        // ==========================================================

        openButton.addEventListener('click', function () {
            modal.style.display = 'block';
        });


        // ==========================================================
        // キャンセルボタン押下
        // ==========================================================

        cancelButton.addEventListener('click', function () {
            modal.style.display = 'none';
        });


        // ==========================================================
        // ポップアップ背景部分のクリック
        // ----------------------------------------------------------
        // ポップアップ本体以外をクリックした場合も閉じる。
        // DBの選択状態は一切変更しない。
        // ==========================================================

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });

    });
</script>


{{-- ==============================================================
     音声の操作の会話の欄（D-58）
     --------------------------------------------------------------
     聞き取った文字と返事を出す（聞き違いをすぐ確かめられるように）。public/js/voice.js が表示・更新する。
     ============================================================== --}}

@if (app(\App\Services\Voice\VoiceSettings::class)->available())
    <div id="voice-panel" class="voice-panel" hidden>
        <div class="voice-panel-head">
            <span class="voice-panel-title">JARVIS</span>
            <span class="voice-panel-state" id="voice-state"></span>
            <button type="button" class="voice-panel-close btn-secondary" id="voice-close" aria-label="閉じる">×</button>
        </div>
        <p class="voice-line voice-line-user" id="voice-transcript" hidden></p>
        <p class="voice-line voice-line-reply" id="voice-reply" hidden></p>
        <p class="voice-panel-note text-muted">返事は AI が作った音声です</p>
    </div>
@endif


{{-- ==============================================================
     画面のパネル（D-59）
     --------------------------------------------------------------
     トップページのリンク・メニューと、声で開く画面を、画面を移らずに、横から出るパネルの中に表示する
     （画面を移ると、声の会話が切れるため。アークリアクターも見えたままにする）。
     パネルの中の画面は、ヘッダーとメニューを出さない（layouts/head の is-embedded）。
     「全画面で開く」で、パネルの中の画面を、ふつうの画面として開く。public/js/blogos.js が開く・閉じる。
     ============================================================== --}}

<aside id="screen-drawer" class="screen-drawer" aria-label="画面のパネル" aria-hidden="true">
    <div class="screen-drawer-head">
        {{-- 声の操作の状態（聞いている・考えている・話している）。狭い画面で、アークリアクターの代わりに光る --}}
        <span class="screen-drawer-signal" aria-hidden="true"></span>
        <span class="screen-drawer-code">SCREEN</span>
        <span class="screen-drawer-title" id="screen-drawer-title"></span>
        <a href="#" class="screen-drawer-full" id="screen-drawer-full">全画面で開く</a>
        <button type="button" class="btn-secondary screen-drawer-close" id="screen-drawer-close" aria-label="閉じる">×</button>
    </div>
    <iframe class="screen-drawer-frame" id="screen-drawer-frame" title="画面"></iframe>
</aside>


{{-- ==============================================================
     ポップアップを開く・閉じる JavaScript（テーマ切替・音声操作など。D-57・D-61）
     --------------------------------------------------------------
     ・data-modal-open="ポップアップのid" を付けた要素を押す → そのポップアップを表示（リンクの移動はしない）
     ・data-modal-close を付けたボタン、背景、Esc → 閉じる（class="site-modal" のポップアップ）
     ・data-modal-autoopen を付けたポップアップ → 画面を開いたときに表示（入力の誤りで戻ったとき）
     ============================================================== --}}

<script>
    document.addEventListener('DOMContentLoaded', function () {

        const close = (modal) => { modal.style.display = 'none'; };

        document.querySelectorAll('[data-modal-open]').forEach(function (opener) {
            opener.addEventListener('click', function (event) {
                const modal = document.getElementById(opener.dataset.modalOpen);
                if (!modal) {
                    return;
                }
                event.preventDefault();
                // メニューから開いた場合は、メニューを閉じる（下の階層も）
                const menu = opener.closest('.site-menu-group');
                if (menu) {
                    menu.querySelectorAll('details').forEach((details) => { details.open = false; });
                    menu.open = false;
                }
                // 日時の欄に、開いた時刻を入れる（data-default-now="時間帯"。例：残高の登録。D-62）
                modal.querySelectorAll('input[data-default-now]').forEach(function (input) {
                    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
                        timeZone: input.dataset.defaultNow, hourCycle: 'h23',
                        year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
                    }).formatToParts(new Date()).map((part) => [part.type, part.value]));
                    input.value = parts.year + '-' + parts.month + '-' + parts.day + 'T' + parts.hour + ':' + parts.minute;
                });
                modal.style.display = 'block';
            });
        });

        // メニューの、処理を送る項目（例：即時実行。D-63-09）：確認してから送り、開いていた画面に戻る
        document.querySelectorAll('[data-menu-post]').forEach(function (item) {
            item.addEventListener('click', function (event) {
                event.preventDefault();
                const menu = item.closest('.site-menu-group');
                if (menu) {
                    menu.querySelectorAll('details').forEach((details) => { details.open = false; });
                    menu.open = false;
                }
                if (item.dataset.menuConfirm && ! window.confirm(item.dataset.menuConfirm)) {
                    return;
                }
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = item.dataset.menuPost;
                const token = document.createElement('input');
                token.type = 'hidden';
                token.name = '_token';
                token.value = document.querySelector('meta[name="csrf-token"]').content;
                form.appendChild(token);
                document.body.appendChild(form);
                form.submit();
            });
        });

        document.querySelectorAll('.site-modal[data-modal-autoopen]').forEach(function (modal) {
            modal.style.display = 'block';
        });

        document.querySelectorAll('.site-modal').forEach(function (modal) {
            modal.querySelectorAll('[data-modal-close]').forEach((button) => button.addEventListener('click', () => close(modal)));
            modal.addEventListener('click', (event) => { if (event.target === modal) { close(modal); } });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                document.querySelectorAll('.site-modal').forEach(close);
            }
        });

    });
</script>
