{{--
    古い画像（/wp-content/img/）の移し替え（D-90）：どこにも使われなくなった古いファイルの削除のコマンドと、古い画像が残っている記事
--}}

@extends('layouts.app')

@section('content')

    <h1>古い画像の移し替え</h1>

    <p><a href="{{ route('images.index') }}">画像の一覧</a></p>

    @include('partials.flash')

    <p class="text-muted">
        古い記事は、メディアライブラリにない古い画像（/wp-content/img/…）を使っています。記事の画面の「古い画像の移し替え」で、取り込み・確認・WordPress への登録・置き換えをしてください。
        置き換えが済み、どこにも使われなくなった古いファイルは、下のコマンドを SSH で実行して消してください（BlogOS は WordPress のファイルを消しません）。
    </p>

    <section class="panel">
    <h2 data-code="DELETE">削除できる古いファイル</h2>
    @if (! $deletion['available'])
        <p class="text-warn">一覧を出せません：{{ $deletion['reason'] }}記事以外（ウィジェット・テーマなど）での使用を確かめられないため、削除の一覧は出しません。</p>
    @elseif ($deletion['deletable'] === [] && $deletion['used'] === [])
        <p>WordPress のメディアに登録した古い画像で、ファイルが残っているものはありません。</p>
    @else
        @if ($deletion['deletable'] === [])
            <p>削除できるファイルはありません。</p>
        @else
            <p>
                次の {{ count($deletion['deletable']) }}件は、BlogOS の記事・作業中の編集案、WordPress の DB（投稿の版を除く全ての文字の列）、テーマのファイルのどこにも使われていません。
                SSH で実行してください（月1回のバックアップに、元のファイルが残ります）。実行すると、次に開いたときに一覧から消えます。
            </p>
            <textarea readonly rows="{{ min(12, count($deletion['deletable']) + 1) }}" style="width:100%; font-family:monospace;">{{ $deletion['command'] }}</textarea>
        @endif

        @if ($deletion['used'] !== [])
            <h3>まだ使われているファイル（{{ count($deletion['used']) }}件）</h3>
            <ul>
                @foreach ($deletion['used'] as $row)
                    <li style="word-break:break-all;">{{ $row['image']->legacy_path }}：{{ implode('、', $row['places']) }}</li>
                @endforeach
            </ul>
        @endif
    @endif
    </section>

    <section class="panel">
    <h2 data-code="REMAINING">古い画像が残っている記事（{{ count($remaining) }}件）</h2>
    @if ($remaining === [])
        <p>ありません。</p>
    @else
        <table class="data">
            <thead><tr><th>記事</th><th>古い画像</th><th>取り込み済み</th><th>WordPress に登録済み</th></tr></thead>
            <tbody>
                @foreach ($remaining as $row)
                    <tr>
                        <td><a href="{{ route('articles.show', ['type' => $row['article'] instanceof \App\Models\Post ? 'posts' : 'pages', 'id' => $row['article']->id]) }}#legacy-images">{{ $row['article']->title_raw ?: '（タイトルなし）' }}</a></td>
                        <td>{{ $row['paths'] }}</td>
                        <td>{{ $row['imported'] }}</td>
                        <td>{{ $row['registered'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    </section>

@endsection
