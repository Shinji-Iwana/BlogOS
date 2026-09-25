<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>GA4 利用可能項目カタログ（si-note.com）</title>
</head>
<body>
    <h1>GA4 利用可能項目カタログ（si-note.com）</h1>
    <p><a href="{{ route('api-analytics-info') }}">Google Analytics情報一覧に戻る</a></p>

    @if ($error)
        <p>{{ $error }}</p>
    @else
        <h2>ディメンション（{{ count($dimensions) }}件）</h2>
        <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
            <colgroup>
                <col style="width:20%">
                <col style="width:20%">
                <col style="width:15%">
                <col style="width:45%">
            </colgroup>
            <thead>
                <tr>
                    <th>API名</th>
                    <th>表示名</th>
                    <th>カテゴリ</th>
                    <th>説明</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($dimensions as $dim)
                    <tr>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $dim['api_name'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $dim['ui_name'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $dim['category'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $dim['description'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">取得できませんでした。</td></tr>
                @endforelse
            </tbody>
        </table>

        <h2>メトリクス（{{ count($metrics) }}件）</h2>
        <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
            <colgroup>
                <col style="width:20%">
                <col style="width:20%">
                <col style="width:15%">
                <col style="width:45%">
            </colgroup>
            <thead>
                <tr>
                    <th>API名</th>
                    <th>表示名</th>
                    <th>カテゴリ</th>
                    <th>説明</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($metrics as $metric)
                    <tr>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $metric['api_name'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $metric['ui_name'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $metric['category'] }}</td>
                        <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $metric['description'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">取得できませんでした。</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
</body>
</html>
