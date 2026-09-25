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

<header
    style="
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 20px;
        border-bottom: 1px solid #ccc;
    "
>

    {{-- ==========================================================
         ヘッダー左側
         ----------------------------------------------------------
         BlogOSのタイトルと設定ボタンを表示する。
         ========================================================== --}}

    <div
        style="
            display: flex;
            align-items: center;
            gap: 10px;
        "
    >

        {{-- BlogOSタイトル --}}
        <a
            href="{{ route('home') }}"
            style="
                text-decoration: none;
                font-size: 20px;
                font-weight: bold;
            "
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
            style="
                display: inline-block;
                padding: 6px 12px;
                border: 1px solid #999;
                border-radius: 4px;
                text-decoration: none;
            "
        >
            設定
        </a>

    </div>


    {{-- ==========================================================
         ヘッダー右側
         ----------------------------------------------------------
         現在選択中のブログ名をボタンとして表示する。
         ========================================================== --}}

    {{-- 選択中のブログがない場合も、切り替えられるようボタンを表示する --}}
    @if ($blogs->isNotEmpty())

        <button
            type="button"
            id="blog-switch-open"
            style="
                padding: 6px 12px;
                border: 1px solid #999;
                border-radius: 4px;
                background: #fff;
                cursor: pointer;
            "
        >
            {{ $selectedBlog?->display_name ?? '（ブログを選択）' }}
        </button>

    @endif

</header>


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
        style="
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
        "
    >

        {{-- ======================================================
             ポップアップ本体
             ====================================================== --}}

        <div
            style="
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 500px;
                max-width: calc(100% - 40px);
                max-height: calc(100% - 40px);
                overflow-y: auto;
                padding: 20px;
                background: #fff;
                border-radius: 6px;
            "
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
                        style="
                            display: block;
                            padding: 10px;
                            margin-bottom: 8px;
                            border: 1px solid #ddd;
                            border-radius: 4px;
                            cursor: pointer;
                        "
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
                    style="
                        display: flex;
                        justify-content: flex-end;
                        gap: 10px;
                        margin-top: 20px;
                    "
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
                    >
                        キャンセル
                    </button>

                </div>

            </form>

        </div>

    </div>

@endif


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
