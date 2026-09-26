{{--
    人による品質評価（resources/quality/common/scoring.md、D-06-02、D-07-03）

    評価項目・配点・必須条件は品質基準のファイルから読み込む（D-06-08）。対象外の項目は表示しない。
    AIの評価を元にする場合は、AIの判定を初期値にする（人が見直す）。
--}}

@extends('layouts.app')

@section('content')

    <h1>品質評価：{{ $draft ? '編集案 #' . $draft->id . '「' . $draft->title_raw . '」' : ($article->title_raw ?: '（タイトルなし）') }}</h1>

    <p>
        @if ($draft)
            <a href="{{ route('drafts.edit', ['id' => $draft->id]) }}">編集案に戻る</a>
        @else
            <a href="{{ route('articles.show', ['type' => $article instanceof \App\Models\Post ? 'posts' : 'pages', 'id' => $article->id]) }}">記事に戻る</a>
        @endif
    </p>

    @include('partials.flash')

    <p>
        品質基準：共通基準 {{ $standard->commonVersion }}{{ $standard->profile ? '、' . $standard->profile . ' ' . $standard->profileVersion : '' }}
        ・記事種類：{{ $standard->articleTypes[$articleType] ?? ($articleType ?: '未設定（記事の管理情報で設定すると、記事種類ごとの対象外が反映されます）') }}
        @if ($base) ・<strong>AIの評価を初期値にしています。</strong>「要人間確認」の項目を判定してください。@endif
    </p>

    @php
        $judgmentOptions = \App\Enums\Judgment::cases();
        $current = fn (string $key) => old("judgments.{$key}", $base?->get($key)?->judgment?->value);
        $comment = fn (string $key) => old("comments.{$key}", $base?->get($key)?->comment);
    @endphp

    <form method="POST" action="{{ route('evaluations.store') }}">
        @csrf
        @include('partials.selected-blog-field')
        <input type="hidden" name="target" value="{{ $target }}">

        <h2>必須条件（1つでも × なら公開不可）</h2>
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>キー</th><th>条件</th><th>判定</th><th>理由・メモ</th></tr></thead>
            <tbody>
                @foreach ($standard->required as $key => $condition)
                    <tr>
                        <td><code>{{ $key }}</code></td>
                        <td>{{ $condition['label'] }}@if (! $condition['ai'])（判定者：人）@endif</td>
                        <td style="white-space:nowrap;">
                            @foreach ([\App\Enums\Judgment::Good, \App\Enums\Judgment::Bad, \App\Enums\Judgment::NeedsHuman] as $option)
                                <label><input type="radio" name="judgments[{{ $key }}]" value="{{ $option->value }}" @checked($current($key) === $option->value)> {{ $option->label() }}</label>
                            @endforeach
                        </td>
                        <td><input type="text" name="comments[{{ $key }}]" value="{{ $comment($key) }}" style="width:320px;"></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>採点項目（○：配点どおり、△：50%、×：0点）</h2>
        @foreach (collect($applicable)->groupBy('category', true) as $category => $items)
            <h3>{{ $category }}</h3>
            <table border="1" cellpadding="4" cellspacing="0">
                <thead><tr><th>キー</th><th>評価項目</th><th>配点</th><th>判定</th><th>理由・改善点</th></tr></thead>
                <tbody>
                    @foreach ($items as $key => $item)
                        <tr>
                            <td><code>{{ $key }}</code></td>
                            <td>{{ $item['label'] }}@if (! $item['ai'])（判定者：人）@endif</td>
                            <td>{{ $item['points'] }}</td>
                            <td style="white-space:nowrap;">
                                @foreach ($judgmentOptions as $option)
                                    <label><input type="radio" name="judgments[{{ $key }}]" value="{{ $option->value }}" @checked($current($key) === $option->value)> {{ $option->label() }}</label>
                                @endforeach
                            </td>
                            <td><input type="text" name="comments[{{ $key }}]" value="{{ $comment($key) }}" style="width:320px;"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach

        @if ($standard->excludedItems($articleType) !== [])
            <p style="color:#666;">この記事種類で対象外の項目：{{ implode('、', $standard->excludedItems($articleType)) }}</p>
        @endif

        <h2>総評</h2>
        <textarea name="summary" rows="5" style="width:100%; max-width:800px;">{{ old('summary', $baseSummary) }}</textarea>

        <p>
            <label><input type="checkbox" name="confirm" value="1" @checked(old('confirm'))> <strong>この評価を確定する</strong>（全ての項目と必須条件を ○・△・× で判定した場合だけ確定できます。公開の可否は、確定した評価で判断します）</label>
        </p>
        <button type="submit">評価を保存する</button>
    </form>

@endsection
