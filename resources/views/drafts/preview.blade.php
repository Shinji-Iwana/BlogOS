{{--
    編集案のプレビュー（D-28）：左に変更前（WordPressの今の記事）、右に編集案を、実際のサイトの表示で並べる。

    ページは WordPress が保存せずに表示したものを BlogOS が受け取り、スクリプトを取り除いて表示する
    （アクセス解析・広告・表示回数に数えない）。PC表示は幅1200px、スマホ表示は幅390pxで表示し、枠に合わせて縮める。
--}}

@extends('layouts.app')

@section('content')

    @php
        $width = $device === 'mobile' ? 390 : 1200;
        $frame = fn (string $side) => route('drafts.preview.frame', ['id' => $draft->id, 'side' => $side, 'device' => $device]);
    @endphp

    <h1>プレビュー：編集案 #{{ $draft->id }}「{{ $draft->title_raw }}」</h1>

    <p>
        <a href="{{ route('drafts.edit', ['id' => $draft->id]) }}">編集案に戻る</a>
        ・表示：
        @foreach (['pc' => 'PC', 'mobile' => 'スマホ'] as $value => $label)
            @if ($device === $value)
                <strong>{{ $label }}</strong>
            @else
                <a href="{{ route('drafts.preview', ['id' => $draft->id, 'device' => $value]) }}">{{ $label }}</a>
            @endif
        @endforeach
        ・<label><input type="checkbox" id="sync-scroll" checked> 左右のスクロールを連動する</label>
    </p>

    <p style="color:#666;">
        保存済みの編集案を表示しています（編集画面で直した場合は、保存してから開き直してください）。
        スクリプトを止めて表示しているため、広告・コードのコピーのボタン・メニューの開閉など、スクリプトで動く部分は表示されません。リンクは新しいタブで開きます。
        @if ($draft->isNewArticle())
            新規記事のため、右側は最新の公開済みの記事を土台にして、タイトル・本文だけを差し替えています（カテゴリ・日付・関連記事などは土台の記事のものです）。
        @endif
    </p>

    <div style="display:flex; gap:12px; align-items:flex-start;">
        @foreach (['before' => '変更前（WordPressの今の記事）', 'after' => '編集案'] as $side => $label)
            <div style="flex:1; min-width:0;">
                <p><strong>{{ $label }}</strong></p>
                <div class="preview-box" data-width="{{ $width }}" style="border:1px solid #ccc; height:80vh; overflow:hidden; position:relative; background:#fff;">
                    <iframe
                        class="preview-frame"
                        src="{{ $frame($side) }}"
                        title="{{ $label }}"
                        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                        style="border:0; width:{{ $width }}px; transform-origin:0 0; position:absolute; top:0; left:0;"
                    ></iframe>
                </div>
            </div>
        @endforeach
    </div>

    <script>
        (() => {
            const boxes = [...document.querySelectorAll('.preview-box')];
            const frames = boxes.map(box => box.querySelector('iframe'));

            // 枠の幅に合わせて縮める（PC表示を半分の幅で見ても、PCのレイアウトのまま表示するため）
            const fit = () => boxes.forEach((box, i) => {
                const scale = Math.min(1, box.clientWidth / Number(box.dataset.width));
                frames[i].style.transform = `scale(${scale})`;
                frames[i].style.height = `${box.clientHeight / scale}px`;
            });
            window.addEventListener('resize', fit);
            fit();

            // 左右のスクロールの連動（枠の中身は BlogOS と同じ出どころのため、読み書きできる）
            let syncing = false;
            frames.forEach((frame, i) => frame.addEventListener('load', () => {
                const win = frame.contentWindow;
                win.addEventListener('scroll', () => {
                    if (syncing || ! document.getElementById('sync-scroll').checked) {
                        return;
                    }
                    const doc = win.document.documentElement;
                    const ratio = win.scrollY / Math.max(1, doc.scrollHeight - win.innerHeight);
                    const other = frames[1 - i].contentWindow;
                    syncing = true;
                    other.scrollTo(0, ratio * (other.document.documentElement.scrollHeight - other.innerHeight));
                    requestAnimationFrame(() => { syncing = false; });
                });
            }));
        })();
    </script>

@endsection
