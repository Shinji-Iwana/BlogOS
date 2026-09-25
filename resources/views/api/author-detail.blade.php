@extends('layouts.app')

@section('content')

<div class="container">

<h1>ユーザー詳細</h1>

<p>
    <strong>Blog ID：</strong>
    {{ $blogId }}
</p>

<p>
    <strong>Author ID：</strong>
    {{ $authorId }}
</p>

@php
    if (is_object($author) && method_exists($author, 'toArray')) {
        $authorData = $author->toArray();
    } elseif (is_array($author)) {
        $authorData = $author;
    } else {
        $authorData = [];
    }
@endphp

@if (empty($authorData))

    <p>
        ユーザー情報を取得できませんでした。
    </p>

@else

    <table>
        <thead>
            <tr>
                <th>項目</th>
                <th>値</th>
            </tr>
        </thead>

        <tbody>

            @foreach ($authorData as $key => $value)

                <tr>
                    <td>
                        {{ $key }}
                    </td>

                    <td>

                        @if (is_array($value))

                            <pre>{{ json_encode(
                                $value,
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            ) }}</pre>

                        @elseif (is_object($value))

                            <pre>{{ json_encode(
                                $value,
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            ) }}</pre>

                        @elseif (is_null($value))

                            <span>null</span>

                        @elseif (is_bool($value))

                            {{ $value ? 'true' : 'false' }}

                        @else

                            {{ $value }}

                        @endif

                    </td>
                </tr>

            @endforeach

        </tbody>
    </table>

@endif

<p>
    <a href="{{ route('api-author-list') }}">
        ユーザー一覧へ戻る
    </a>
</p>

</div>

@endsection
