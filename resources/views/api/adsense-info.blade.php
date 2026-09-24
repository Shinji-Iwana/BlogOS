<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>AdSense情報一覧（si-note.com）</title>
</head>
<body>
    <h1>AdSense情報一覧（si-note.com）</h1>
    <p><a href="{{ url('/') }}">トップページに戻る</a></p>

    @if ($needsAuth)
        <p>まだ認証されていません。<a href="{{ route('adsense.oauth.redirect') }}">Googleアカウントと連携する</a></p>
    @elseif ($error)
        <p>{{ $error }}</p>
    @else
        <p>取得件数：{{ $total }}件（過去30日間）</p>
        <p>※AdSense APIの仕様上、ディメンションは2つ程度までの組み合わせが推奨のため、ここでは「日付」「国」の2軸＋主要メトリクス全19種で表示しています。</p>

        <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
            <thead>
                <tr>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; width:8%;">アカウント</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; width:6%;">日付</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; width:6%;">国</th>
                    @foreach ($metricNames as $name)
                        <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $name }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['account'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['date'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['country'] }}</td>
                        @foreach ($metricNames as $name)
                            <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row[$name] ?? '' }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($metricNames) + 3 }}">データが取得できませんでした。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @endif
</body>
</html>
