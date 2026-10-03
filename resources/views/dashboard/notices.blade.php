{{--
    トップページのお知らせ（入力エラー・処理結果・選択中のブログ・同期・要対応と注意のお知らせ。D-49-07）

    共通の dashboard/content と、テーマのトップページから読み込む。
    受け取る値（任意）：$withSync（false なら同期の欄を出さない。ironman は状態のパネルに出すため）
--}}

@if ($errors->any())
    <div class="text-error">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

@if ($blogs->isNotEmpty())

    <p>
        現在のブログ：
        {{ $selectedBlog?->display_name ?? '（未選択。画面上部のボタンから選択してください）' }}
    </p>

    @if (session('status'))
        <p class="text-ok">{{ session('status') }}</p>
    @endif

    {{-- 同期（テーマが別の場所に出す場合は、$withSync = false で読み込む） --}}
    @if ($selectedBlog && ($withSync ?? true))
        @include('partials.sync-status')
    @endif

    {{-- OpenAI の残高の見込みのお知らせ（D-31-04） --}}
    @if ($selectedBlog)
        @include('partials.ai-credit-notice')
    @endif

    {{-- API実行の料金表のお知らせ（D-31-03） --}}
    @if ($selectedBlog && ($priceNotice['pending'] || $priceNotice['failed'] || $priceNotice['applied']))
        <p class="text-warn">
            AIの料金表：
            @if ($priceNotice['failed'])<strong class="text-error">公式のページから読み取れなかった料金があります。</strong>@endif
            @if ($priceNotice['pending'])値下がりの確認待ちが{{ $priceNotice['pending'] }}件あります。@endif
            @if ($priceNotice['applied'])直近7日に{{ $priceNotice['applied'] }}件の料金を変更しました。@endif
            <a href="{{ route('ai.settings.edit') }}#prices">料金表を確認する</a>
        </p>
    @endif

    {{-- 公開された記事へのリンクの切り替え（D-39） --}}
    @if (($linkSwitchDrafts ?? 0) > 0)
        <p class="text-warn">
            リンクの切り替え・修正の編集案が{{ $linkSwitchDrafts }}件あります（公開された記事へのリンク、古い URL など）。
            <a href="{{ route('drafts.link-switch') }}">確認して反映する</a>
        </p>
    @endif

    {{-- 定期実行（D-44） --}}
    @if (($scheduleNotice['stopped'] ?? false) || ! empty($scheduleNotice['failed']))
        <p class="text-error">
            定期実行：
            @if ($scheduleNotice['stopped'])26時間以上、定期実行が動いていません（サーバーの cron を確認してください）。@endif
            @if (! empty($scheduleNotice['failed']))前回が失敗した定期実行があります（{{ implode('、', $scheduleNotice['failed']) }}）。@endif
            <a href="{{ route('scheduled-tasks.index') }}">定期実行を確認する</a>
        </p>
    @endif

    {{-- 内部リンクの確認（D-42） --}}
    @if (($brokenLinks ?? 0) > 0)
        <p class="text-error">
            内部リンク：リンク切れが{{ $brokenLinks }}件あります。
            <a href="{{ route('links.check') }}#broken">内部リンクを確認する</a>
        </p>
    @endif

    {{-- WordPress の更新（D-38） --}}
    @if (($wordpressNotice['updates'] ?? 0) > 0 || ($wordpressNotice['closed'] ?? 0) > 0)
        <p class="text-error">
            WordPress：@if ($wordpressNotice['updates'])更新が{{ $wordpressNotice['updates'] }}件あります。@endif
            @if ($wordpressNotice['closed'])公開停止になったプラグインが{{ $wordpressNotice['closed'] }}件あります。@endif
            <a href="{{ route('wordpress-updates.index') }}">WordPress の更新を確認する</a>
        </p>
    @endif

    {{-- アフィリエイトのリンクの確認（D-33-09） --}}
    @if (($affiliateSuspects ?? 0) > 0)
        <p class="text-error">
            アフィリエイトのリンク：提携終了の疑いがあるプログラムが{{ $affiliateSuspects }}件あります。
            <a href="{{ route('materials.programs.index') }}">アフィリエイトのプログラムを確認する</a>
        </p>
    @endif

@endif
