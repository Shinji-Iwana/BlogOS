{{--
    サイト内検索（WordPress の検索 API。D-52）

    共通の枠（layouts.app）で、選んだテーマの見た目にする。検索の対象は選択中のブログ（$selectedBlog は ShareCurrentBlog が共有）。
--}}

@extends('layouts.app')

@section('title', 'サイト内検索（BlogOS）')

@section('content')

    <h1>サイト内検索</h1>

    <p><a href="{{ url('/') }}">トップページに戻る</a></p>

    <form method="GET" action="{{ route('api-site-search') }}" class="panel" data-code="SEARCH">
        <input type="text" name="q" value="{{ $keyword }}" placeholder="検索したいキーワードを入力" style="width:100%; max-width:480px;">
        <button type="submit">検索</button>
    </form>

    @if ($keyword !== '')
        <p>「{{ $keyword }}」の検索結果：{{ $total }}件</p>

        <table class="data" style="width:100%;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>タイトル</th>
                    <th>種別</th>
                    <th>サブタイプ</th>
                    <th>リンク</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($results as $result)
                    <tr>
                        <td>{{ $result['id'] ?? '' }}</td>
                        <td>{{ $result['title'] ?? '' }}</td>
                        <td>{{ $result['type'] ?? '' }}</td>
                        <td>{{ $result['subtype'] ?? '' }}</td>
                        <td>
                            @if (! empty($result['url']))
                                <a href="{{ $result['url'] }}" target="_blank">{{ $result['url'] }}</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">該当する結果が見つかりませんでした。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @endif

@endsection
