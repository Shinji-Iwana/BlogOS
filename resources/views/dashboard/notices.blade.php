{{--
    トップページのその場限りの表示（入力エラー・処理結果・ブログを選んでいないときの案内・同期。D-49-07）

    共通の dashboard/content と、テーマのトップページから読み込む。
    要対応・注意のお知らせ（OpenAI の残高・料金表・リンクの切り替え・定期実行・リンク切れ・WordPress の更新・アフィリエイト）は、
    記録してお知らせの画面に出す（D-74。App\Services\Notices\NoticeService）。ここには出さない。
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

    {{-- 選択中のブログの名前はヘッダーに出ているため、選んでいないときだけ案内する（D-55） --}}
    @if (! $selectedBlog)
        <p class="text-warn">ブログが選択されていません。画面上部のボタンから選択してください。</p>
    @endif

    @if (session('status'))
        <p class="text-ok">{{ session('status') }}</p>
    @endif

    {{-- 同期（テーマが別の場所に出す場合は、$withSync = false で読み込む） --}}
    @if ($selectedBlog && ($withSync ?? true))
        @include('partials.sync-status')
    @endif

@endif
