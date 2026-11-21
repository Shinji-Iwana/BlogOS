{{--
    反映記録の一覧（wordpress_push_operations）
--}}

@extends('layouts.app')

@section('content')

    <h1>WordPress反映履歴一覧</h1>

    <p>
        <a href="{{ route('drafts.index') }}">編集案一覧</a>
    </p>

    @include('partials.flash')

    @include('partials.history-count', ['paginator' => $operations])
    <div style="overflow-x:auto;">
        <table class="data">
            <thead><tr><th>#</th><th>対象</th><th>操作</th><th>状態</th><th>承認</th><th>日時</th><th>メッセージ</th></tr></thead>
            <tbody>
                @forelse ($operations as $operation)
                    <tr @if (in_array($operation->state, \App\Enums\PushState::locking(), true)) class="row-error" @endif>
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
    {{ $operations->links() }}

@endsection
