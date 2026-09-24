<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>Google Analytics情報一覧（si-note.com）</title>
</head>
<body>
    <h1>Google Analytics情報一覧（si-note.com）</h1>
    <p><a href="{{ url('/') }}">トップページに戻る</a></p>
    <p><a href="{{ route('analytics-catalog') }}">利用可能な全ディメンション・メトリクスのカタログを見る</a></p>

    @if ($error)
        <p>{{ $error }}</p>
    @else
        <p>取得件数：{{ $total }}件（過去30日間・ページ別）</p>
        <p>※GA4はディメンション・メトリクスの組み合わせに互換性制限があり、1回のレポートで全項目（約150ディメンション×100+メトリクス）を同時取得することはできません。ここでは「ページパス」ディメンションと互換性のある標準メトリクスを最大限列挙しています。全項目のカタログは上記リンクから確認できます。</p>

        <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
            <thead>
                <tr>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; width:15%;">ページパス</th>
                    @foreach ($metricNames as $name)
                        <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $name }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['page_path'] }}</td>
                        @foreach ($metricNames as $name)
                            <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row[$name] ?? '' }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($metricNames) + 1 }}">データが取得できませんでした。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @endif
</body>
</html>
