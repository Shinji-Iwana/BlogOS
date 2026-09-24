<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>記事詳細（si-note.com）</title>
</head>
<body>
    <h1>記事詳細（si-note.com）</h1>
    <p><a href="{{ route('blog-info') }}">記事一覧に戻る</a></p>

    @if (empty($post))
        <p>該当の記事が見つかりませんでした。</p>
    @else
        <table style="width:100%; border-collapse:collapse;" border="1" cellpadding="8" cellspacing="0">
            <colgroup>
                <col style="width:20%">
                <col style="width:80%">
            </colgroup>
            <tbody>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">ID</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $post['id'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">タイトル</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $post['title']['rendered'] ?? $post['title']['raw'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">ステータス</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $post['status'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">スラッグ</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ rawurldecode($post['slug'] ?? '') }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">投稿日</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $post['date'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">更新日</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $post['modified'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">リンク</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $post['link'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">抜粋</th>
                    <td><textarea readonly rows="4" style="width:100%; box-sizing:border-box;">{{ strip_tags($post['excerpt']['rendered'] ?? $post['excerpt']['raw'] ?? '') }}</textarea></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">カテゴリID</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ isset($post['categories']) ? implode(', ', $post['categories']) : '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">タグID</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ isset($post['tags']) ? implode(', ', $post['tags']) : '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">メタディスクリプション</th>
                    <td><textarea readonly rows="3" style="width:100%; box-sizing:border-box;">{{ $post['aioseo_meta_description'] ?? '' }}</textarea></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">コメント許可</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $post['comment_status'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">ピンバック許可</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $post['ping_status'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">投稿フォーマット</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $post['format'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">アイキャッチ画像</th>
                    <td><input type="text" readonly style="width:100%; box-sizing:border-box;" value="{{ $post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '' }}"></td>
                </tr>
                <tr>
                    <th style="text-align:left; white-space:nowrap;">本文</th>
                    <td><textarea readonly rows="20" style="width:100%; box-sizing:border-box;">{{ $post['content']['rendered'] ?? $post['content']['raw'] ?? '' }}</textarea></td>
                </tr>
            </tbody>
        </table>
    @endif
</body>
</html>
