@extends('layouts.app')

@section('content')

@if ($blogs->isNotEmpty())
    <p>
        現在のブログ：
        {{ $selectedBlog?->name }}
    </p>
    <p><a href="{{ route('database-blog-list') }}">ブログ一覧ページへ</a></p>
    <p><a href="{{ route('database-blog-history-list') }}">ブログ変更履歴一覧ページへ</a></p>



    {{-- ==========================================================
         カテゴリ
         ----------------------------------------------------------
         現在選択中のブログを対象としてページへ遷移する。
         ========================================================== --}}

    @if ($selectedBlog)

        <p><a href="{{ route('database-category-list') }}">カテゴリ一覧ページへ</a></p>
        <p><a href="{{ route('database-category-history-list') }}">カテゴリ変更履歴一覧ページへ</a></p>

    @endif

    <p><a href="{{ route('api-site-search') }}">サイト内検索ページへ</a></p>
    <p><a href="{{ route('api-analytics-info') }}">Google Analytics情報一覧ページへ</a></p>
    <p><a href="{{ route('api-search-console-info') }}">Search Console情報一覧ページへ</a></p>
    <p><a href="{{ route('api-adsense-info') }}">AdSense情報一覧ページへ</a></p>

@else

    {{-- ==========================================================
         ブログ未登録時
         ----------------------------------------------------------
         blogsテーブルにブログが1件も存在しない場合。

         この場合は対象ブログを選択する必要がないため、
         ブログ登録画面への導線のみを表示する。
         ========================================================== --}}

    <p>
        登録されているブログがありません。
    </p>

    <p>
        ブログ登録画面を開いています。
    </p>

@endif

{{-- ==============================================================
     ログアウト
     ============================================================== --}}

<form method="POST" action="{{ route('logout') }}" style="display:inline;">
    @csrf
    <button type="submit">ログアウト</button>
</form>


{{-- ==============================================================
     ブログ登録ポップアップ
     --------------------------------------------------------------
     ブログが1件も登録されていない場合、
     DashboardControllerから渡された
     $showBlogRegisterModal=trueによって自動表示する。
     ============================================================== --}}

@if ($showBlogRegisterModal)

    <div
        id="blog-register-modal"
        style="
            display:flex;
            position:fixed;
            z-index:9999;
            top:0;
            left:0;
            width:100%;
            height:100%;
            background:rgba(0,0,0,0.6);
            align-items:center;
            justify-content:center;
        "
    >

        <div
            style="
                position:relative;
                width:900px;
                max-width:95%;
                height:85%;
                background:#fff;
                border-radius:8px;
                overflow:hidden;
            "
        >

            {{-- ==================================================
                 ブログ登録画面
                 --------------------------------------------------
                 既存のブログ登録画面をiframe内で表示する。
                 ================================================== --}}

            <iframe
                id="blog-register-frame"
                src="{{ route('database-blog-register') }}"
                style="
                    width:100%;
                    height:100%;
                    border:none;
                "
            ></iframe>

        </div>
    </div>

@endif


<script>
    /*
     * ==========================================================
     * ブログ登録完了通知
     * ==========================================================
     *
     * blog-register.blade.phpから、
     *
     * window.parent.postMessage()
     *
     * によって登録完了通知が送信される。
     *
     * 登録完了通知を受け取ったら、
     *
     * 1. ブログ登録ポップアップを閉じる
     * 2. トップページへ再アクセスする
     *
     * ことで、登録されたブログを元に
     * トップページを再描画する。
     */

    window.addEventListener('message', function (event) {

        /*
         * BlogOS自身のブログ登録画面から送信された
         * メッセージだけを受け取る。
         */
        if (event.data?.type !== 'blog-register-complete') {
            return;
        }

        /*
         * ブログ登録ポップアップを閉じる。
         */
        const modal =
            document.getElementById('blog-register-modal');

        if (modal) {
            modal.remove();
        }

        /*
         * トップページを再読み込みする。
         *
         * DashboardControllerが再度実行されるため、
         *
         * blogsテーブル
         *     ↓
         * is_selected=true
         *     ↓
         * 選択中ブログ取得
         *
         * の流れで、登録したブログを
         * 現在の操作対象として表示できる。
         */
        window.location.href = '{{ route('home') }}';
    });
</script>
@endsection
