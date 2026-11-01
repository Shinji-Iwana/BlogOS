{{--
    古い画像（/wp-content/img/）の置き換えの確認（D-90）。公開中の記事は URL だけを反映し、作業中の編集案の URL も置き換える
--}}

@extends('layouts.app')

@section('content')

    <h1>古い画像の置き換え：{{ $article->title_raw ?: '（タイトルなし）' }}</h1>

    <p><a href="{{ route('articles.show', ['type' => $type, 'id' => $article->id]) }}#legacy-images">記事に戻る</a></p>

    @include('partials.flash')

    @if ($replaced === [])
        <p>置き換えられる画像がありません。古い画像を WordPress のメディアに登録してから、もう一度開いてください。</p>
    @else
        <h2>1. 公開中の記事（WordPress に反映します）</h2>
        <p class="text-muted">
            今 WordPress にある本文の、次の画像の URL と、その画像の alt・幅・高さ・class（wp-image-ID）、画像を囲むリンクの URL だけを置き換えます。ほかの部分は変えません。
            作業中の編集案とは別に、すぐに反映します。
        </p>
        <table class="data">
            <thead><tr><th>古い画像</th><th>メディアの画像</th></tr></thead>
            <tbody>
                @foreach ($replaced as $row)
                    <tr>
                        <td style="word-break:break-all;">{{ $row['path'] }}</td>
                        <td style="word-break:break-all;"><a href="{{ $row['url'] }}" target="_blank" rel="noopener">{{ $row['url'] }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>2. 作業中の編集案（BlogOS の中で置き換えます。WordPress は変えません）</h2>
        @if ($drafts->isEmpty())
            <p>置き換える編集案はありません。</p>
        @else
            <ul>
                @foreach ($drafts as $draft)
                    <li><a href="{{ route('drafts.edit', ['id' => $draft->id]) }}">編集案 #{{ $draft->id }}</a>：{{ ($draft->post ?? $draft->page)?->title_raw ?? $draft->title_raw ?? '（新しい記事）' }}</li>
                @endforeach
            </ul>
            <p class="text-muted">
                この記事の作業中の編集案は、1 の反映の直前の版を元にしていれば、元の版を反映後の版に進めます（同じ置き換えを入れるため、あとで反映するときに競合になりません）。
                ほかの記事の編集案は、その記事の古い画像のうち、WordPress に登録した画像だけを置き換えます。
            </p>
        @endif

        <form method="POST" action="{{ route('articles.legacy-images.replace', ['type' => $type, 'id' => $article->id]) }}" onsubmit="return confirm('公開中の記事の画像の URL を置き換えて、WordPress に反映しますか？');">
            @csrf
            @include('partials.selected-blog-field')
            <button type="submit">承認して反映する</button>
        </form>
    @endif

@endsection
