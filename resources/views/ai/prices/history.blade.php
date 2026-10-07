{{--
    OpenAI API料金表との同期履歴（ai_price_changes。メニューの「履歴 → OpenAI API料金表との同期履歴」。D-63-27）

    以前はAIの設定の画面の「料金表の変更の記録」。料金表は全ブログ共通（D-31-03）。確認待ち（値下がり）は、AIの設定の画面で反映する・しない。
--}}

@extends('layouts.app')

@section('content')

    <h1>OpenAI API料金表との同期履歴 @include('partials.tip', ['tip' => "API実行の料金表を、OpenAI の公式のページと照合して変えた記録です。\n値上がりは自動で反映し、値下がりは AIの設定の画面で確認してから反映します（確認待ちのものは、ここには出しません）。"])</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    @if ($latestPriceCheck)
        <p class="text-muted">最後の照合：{{ \App\Support\DisplayTime::format($latestPriceCheck->created_at) }}（{{ $latestPriceCheck->succeeded() ? '照合できた' : '読み取れなかった料金あり' }}）</p>
    @endif

    <section class="panel">
    <h2>料金表の変更の記録</h2>
    @include('partials.history-count', ['paginator' => $priceHistory])
    <div style="overflow-x:auto;">
        <table class="data">
            <thead><tr><th>日時</th><th>項目</th><th>変更前</th><th>変更後</th><th>状態</th><th>確認した人</th></tr></thead>
            <tbody>
                @forelse ($priceHistory as $change)
                    <tr>
                        <td>{{ \App\Support\DisplayTime::format($change->decided_at ?? $change->created_at) }}</td>
                        <td>{{ \App\Services\Ai\AiPriceCheckService::label($change->price_key, $change->field) }}</td>
                        <td>{{ $change->old_value ?? '-' }}</td>
                        <td>{{ $change->new_value }}</td>
                        <td>{{ $change->status->label() }}</td>
                        <td>{{ $change->decider?->name ?? '（自動）' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">ありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $priceHistory->links() }}
    </section>

@endsection
