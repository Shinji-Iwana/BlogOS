{{--
    ブログ一覧（DB確認画面）

    blogs の保存内容を表示する。サイト名などWordPressの設定は blog_settings にあり、詳細画面で表示する。
--}}

@extends('layouts.app')

@section('content')

    <h1>ブログ一覧</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    <p>登録件数：{{ $total }}件</p>

    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>表示名</th>
                    <th>ホームURL</th>
                    <th>品質基準</th>
                    <th>選択中</th>
                    <th>アーカイブ</th>
                    <th>登録日時</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($blogs as $blog)
                    <tr>
                        <td><a href="{{ route('database-blog-detail', $blog->id) }}">{{ $blog->id }}</a></td>
                        <td>{{ $blog->display_name }}</td>
                        <td><a href="{{ $blog->home }}" target="_blank" rel="noopener">{{ $blog->home }}</a></td>
                        <td>{{ $blog->quality_profile ?? '（未設定）' }}</td>
                        <td>{{ $blog->is_selected ? '○' : '' }}</td>
                        <td>{{ $blog->archived_at }}</td>
                        <td>{{ $blog->created_at }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">登録されているブログがありません。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
