{{--
    タイトル・メタディスクリプションの改善の候補（D-36）
--}}

@extends('layouts.app')

@section('content')

    <h1>タイトル・メタディスクリプションの改善の候補（{{ $blog->display_name }}）</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a>・<a href="{{ route('analytics.index') }}">分析</a></p>

    <p style="color:#666;">
        公開中の記事 {{ count($rows) }}件のうち、ルールに合わない点がある記事は {{ $withIssues }}件です
        （品質基準 writing.md 3-1・3-2、ブログ別の定義のタイトルの型）。
        Search Console の指標は {{ $from->toDateString() }}〜{{ $to->toDateString() }}（90日）です。
        「取りこぼし」は、掲載順位ごとのクリック率の目安と比べて足りないクリックの数の目安で、多い記事ほどタイトル・メタディスクリプションを直す効果が大きいと考えられます（目安は大まかな値です）。
        表示回数が 0 の記事は、Google にインデックスされていない可能性があります。
    </p>

    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead>
                <tr><th>タイトル</th><th>文字数</th><th>メインキーワード</th><th>表示回数</th><th>クリック</th><th>クリック率</th><th>平均順位</th><th>取りこぼし</th><th>ルールに合わない点</th></tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td style="max-width:360px;"><a href="{{ route('articles.show', ['type' => $row['type'], 'id' => $row['article']->id]) }}">{{ $row['article']->title_raw ?: '（タイトルなし）' }}</a></td>
                        <td>{{ mb_strlen((string) $row['article']->title_raw) }}</td>
                        <td>{{ $row['keyword'] ?? '未設定' }}</td>
                        <td>{{ number_format($row['impressions']) }}</td>
                        <td>{{ number_format($row['clicks']) }}</td>
                        <td>{{ $row['ctr'] !== null ? number_format($row['ctr'] * 100, 1) . '%' : '-' }}</td>
                        <td>{{ $row['position'] !== null ? number_format($row['position'], 1) : '-' }}</td>
                        <td>{{ $row['missed'] > 0 ? number_format($row['missed'], 1) : '-' }}</td>
                        <td style="max-width:420px;">
                            @if ($row['issues'] === [])
                                <span style="color:#070;">なし</span>
                            @else
                                <ul style="margin:0; padding-left:18px;">
                                    @foreach ($row['issues'] as $issue)
                                        <li>{{ $issue }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@endsection
