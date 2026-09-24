<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>Search Console情報一覧（si-note.com）</title>
</head>
<body>
    <h1>Search Console情報一覧（si-note.com）</h1>
    <p><a href="{{ url('/') }}">トップページに戻る</a></p>

    @if ($error)
        <p>{{ $error }}</p>
    @else
        <h2>ページ・クエリ・国・デバイス別</h2>
        <p>取得件数：{{ $total }}件（過去28日間）</p>

        <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
            <colgroup>
                <col style="width:22%">
                <col style="width:20%">
                <col style="width:10%">
                <col style="width:10%">
                <col style="width:12%">
                <col style="width:12%">
                <col style="width:14%">
            </colgroup>
            <thead>
                <tr>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">ページURL</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">検索クエリ</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">国</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">デバイス</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">クリック数</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">表示回数</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">CTR(%) / 平均順位</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['page'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['query'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['country'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['device'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['clicks'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['impressions'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['ctr'] }}% / {{ $row['position'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">データが取得できませんでした。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <h2>検索での見え方（searchAppearance）別</h2>
        <p>取得件数：{{ $appearanceTotal }}件（過去28日間）</p>
        <p>※このディメンションはAPI仕様上、他のディメンションと組み合わせられないため別表にしています。</p>

        <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
            <colgroup>
                <col style="width:40%">
                <col style="width:20%">
                <col style="width:20%">
                <col style="width:20%">
            </colgroup>
            <thead>
                <tr>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">検索での見え方</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">クリック数</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">表示回数</th>
                    <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">CTR(%) / 平均順位</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($appearanceRows as $row)
                    <tr>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['appearance'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['clicks'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['impressions'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $row['ctr'] }}% / {{ $row['position'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">データが取得できませんでした（該当データなしの場合もあります）。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @endif
</body>
</html>
