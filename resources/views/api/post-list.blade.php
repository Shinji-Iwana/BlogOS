<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ブログ情報一覧（si-note.com）</title>
</head>
<body>
    <h1>ブログ情報一覧（si-note.com）</h1>
    <p><a href="{{ url('/') }}">トップページに戻る</a></p>
    <p>取得件数：{{ $total }}件</p>

    <table style="table-layout:fixed; width:100%; border-collapse:collapse;" border="1" cellpadding="5" cellspacing="0">
        <colgroup>
            <col style="width:4%">
            <col style="width:9%">
            <col style="width:5%">
            <col style="width:7%">
            <col style="width:6%">
            <col style="width:6%">
            <col style="width:4%">
            <col style="width:9%">
            <col style="width:5%">
            <col style="width:5%">
            <col style="width:10%">
            <col style="width:5%">
            <col style="width:5%">
            <col style="width:5%">
            <col style="width:6%">
            <col style="width:9%">
        </colgroup>
        <thead>
            <tr>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">ID</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">タイトル</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">ステータス</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">スラッグ</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">投稿日</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">更新日</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">リンク</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">抜粋</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">カテゴリID</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">タグID</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">メタディスクリプション</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">コメント許可</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">ピンバック許可</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">投稿フォーマット</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">アイキャッチ画像</th>
                <th style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">本文</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($posts as $post)
                <tr>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        <a href="{{ route('api-post-detail', $post['id']) }}">{{ $post['id'] ?? '' }}</a>
                    </td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $post['title']['rendered'] ?? $post['title']['raw'] ?? '(タイトルなし)' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $post['status'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ rawurldecode($post['slug'] ?? '') }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $post['date'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $post['modified'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        @if (!empty($post['link']))
                            <a href="{{ $post['link'] }}" target="_blank">リンク</a>
                        @endif
                    </td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{!! strip_tags($post['excerpt']['rendered'] ?? $post['excerpt']['raw'] ?? '') !!}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ isset($post['categories']) ? implode(', ', $post['categories']) : '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ isset($post['tags']) ? implode(', ', $post['tags']) : '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $post['aioseo_meta_description'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $post['comment_status'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $post['ping_status'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">{{ $post['format'] ?? '' }}</td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        @if (!empty($post['_embedded']['wp:featuredmedia'][0]['source_url']))
                            <a href="{{ $post['_embedded']['wp:featuredmedia'][0]['source_url'] }}" target="_blank">画像</a>
                        @else
                            なし
                        @endif
                    </td>
                    <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0;">
                        <details>
                            <summary>表示</summary>
                            <pre style="white-space:pre-wrap; word-break:break-word;">{{ strip_tags($post['content']['rendered'] ?? $post['content']['raw'] ?? '') }}</pre>
                        </details>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="16">記事が取得できませんでした。</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
