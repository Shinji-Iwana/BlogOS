{{-- ==============================================================
     BlogOS共通ヘッダー
     --------------------------------------------------------------
     BlogOSのトップページ・設定ページ・各種一覧・詳細ページなど、
     layouts.appを利用するすべてのページで共通表示する。

     ヘッダーには、

     ・左端：BlogOS
     ・BlogOS右隣：設定ボタン
     ・右端：現在選択中のブログ名ボタン

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
         BlogOSのタイトルと設定ボタンを表示する。
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

        {{-- ======================================================
             設定ボタン
             ------------------------------------------------------
             現在選択中のブログIDをURLへ渡すのではなく、
             SettingsController側で現在選択中ブログを取得する。
             ====================================================== --}}

        <a
            href="{{ route('settings') }}"
            class="site-settings-link"
        >
            設定
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
                    <li><a href="{{ $link['url'] }}" @if ($link['modal']) data-modal-open="{{ $link['modal'] }}" @endif>{{ $link['label'] }}</a></li>
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

@php($headerThemes = app(\App\Services\ThemeService::class))

<div
    id="theme-switch-modal"
    class="blog-switch-modal theme-switch-modal"
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
     ポップアップを開く・閉じる JavaScript（テーマ切替など。D-57）
     --------------------------------------------------------------
     ・data-modal-open="ポップアップのid" を付けた要素を押す → そのポップアップを表示（リンクの移動はしない）
     ・data-modal-close を付けたボタン、背景、Esc → 閉じる
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
                // メニューから開いた場合は、メニューを閉じる
                const menu = opener.closest('details');
                if (menu) {
                    menu.open = false;
                }
                modal.style.display = 'block';
            });
        });

        document.querySelectorAll('.theme-switch-modal').forEach(function (modal) {
            modal.querySelectorAll('[data-modal-close]').forEach((button) => button.addEventListener('click', () => close(modal)));
            modal.addEventListener('click', (event) => { if (event.target === modal) { close(modal); } });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                document.querySelectorAll('.theme-switch-modal').forEach(close);
            }
        });

    });
</script>
