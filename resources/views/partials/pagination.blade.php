{{-- ==========================================================
     一覧のページ送り（全画面で共通。AppServiceProvider で既定にしている）
     ----------------------------------------------------------
     Laravel 標準の部品は Tailwind CSS 前提のため、テーマに CSS がないと矢印の画像が大きく崩れる。
     画像を使わず、文字とリンクだけで表示する。
     ========================================================== --}}
@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="ページ送り">
        <p>
            全{{ number_format($paginator->total()) }}件中 {{ number_format($paginator->firstItem()) }}〜{{ number_format($paginator->lastItem()) }}件を表示
        </p>
        <p>
            @if ($paginator->onFirstPage())
                <span>« 前へ</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev">« 前へ</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span>{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <strong aria-current="page">{{ $page }}</strong>
                        @else
                            <a href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next">次へ »</a>
            @else
                <span>次へ »</span>
            @endif
        </p>
    </nav>
@endif
