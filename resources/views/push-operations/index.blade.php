{{--
    反映記録の一覧（wordpress_push_operations）
--}}

@extends('layouts.app')

@section('content')

    <h1>反映記録（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('drafts.index') }}">編集案の一覧</a>
    </p>

    @include('partials.flash')

    <p>新しい順、最大200件</p>

    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            <thead><tr><th>#</th><th>対象</th><th>操作</th><th>状態</th><th>承認</th><th>日時</th><th>メッセージ</th></tr></thead>
            <tbody>
                @forelse ($operations as $operation)
                    <tr @if (in_array($operation->state, \App\Enums\PushState::locking(), true)) style="background:#ffebe9;" @endif>
                        <td><a href="{{ route('push-operations.show', ['id' => $operation->id]) }}">{{ $operation->id }}</a></td>
                        <td>{{ $operation->resource_type->label() }}：{{ $operation->targetLabel() }}</td>
                        <td>{{ $operation->operation->label() }}</td>
                        <td>{{ $operation->state->label() }}</td>
                        <td>{{ $operation->approver?->name ?? '-' }}</td>
                        <td>{{ \App\Support\DisplayTime::format($operation->created_at) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit((string) $operation->message, 100) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">ありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
