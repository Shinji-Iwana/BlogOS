{{--
    WordPress由来のテーブルの一覧（DB確認画面の入口）
--}}

@extends('layouts.app')

@section('content')

    <h1>取り込んだWordPressのデータ（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('database.sync-runs.index') }}">同期の記録</a>
    </p>

    <table border="1" cellpadding="4" cellspacing="0">
        <thead>
            <tr>
                <th>データ</th>
                <th>件数</th>
                <th>うちWordPress側で削除済み</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($counts as $table => $count)
                <tr>
                    <td><a href="{{ route('database.wordpress-records.index', ['table' => $table]) }}">{{ $count['label'] }}</a>（{{ $table }}）</td>
                    <td>{{ $count['total'] }}</td>
                    <td>{{ $count['deleted'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

@endsection
