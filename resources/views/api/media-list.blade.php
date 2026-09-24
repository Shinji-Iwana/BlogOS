<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>メディア一覧（si-note.com）</title>
</head>
<body>
    <h1>メディア一覧（si-note.com）</h1>
    <p><a href="{{ url('/') }}">トップページに戻る</a></p>
    <p>取得件数：{{ $total }}件</p>

    <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
        <colgroup>
            <col style="width:4%">
            <col style="width:12%">
            <col style="width:7%">
            <col style="width:8%">
            <col style="width:8%">
            <col style="width:8%">
            <col style="width:7%">
            <col style="width:7%">
            <col style="width:7%">
            <col style="width:6%">
            <col style="width:6%">
            <col style="width:7%">
            <col style="width:6%">
            <col style="width:7%">
        </colgroup>
        <thead>
            <tr>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">ID</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">タイトル</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">ステータス</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">投稿日</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">更新日</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">ファイルURL</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">MIMEタイプ</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">メディア種別</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">代替テキスト</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">キャプション</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">説明</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">サイズ(px)</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">添付先投稿ID</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">リンク</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($media as $item)
                <tr>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $item['id'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $item['title']['rendered'] ?? $item['title']['raw'] ?? '(タイトルなし)' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $item['status'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $item['date'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $item['modified'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        @if (!empty($item['source_url']))
                            <a href="{{ $item['source_url'] }}" target="_blank">ファイル</a>
                        @endif
                    </td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $item['mime_type'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $item['media_type'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $item['alt_text'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{!! strip_tags($item['caption']['rendered'] ?? $item['caption']['raw'] ?? '') !!}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{!! strip_tags($item['description']['rendered'] ?? $item['description']['raw'] ?? '') !!}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        {{ ($item['media_details']['width'] ?? '') }}{{ isset($item['media_details']['width']) ? '×' : '' }}{{ $item['media_details']['height'] ?? '' }}
                    </td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $item['post'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        @if (!empty($item['link']))
                            <a href="{{ $item['link'] }}" target="_blank">詳細</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="14">メディアが取得できませんでした。</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
