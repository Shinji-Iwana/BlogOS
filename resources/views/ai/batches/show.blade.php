{{--
    まとめて実行の進み具合と、記事ごとの結果（D-25）
--}}

@extends('layouts.app')

@section('content')

    @php
        $running = $batch->status === \App\Enums\AiBatchStatus::Running;
        $counts = $progress['counts'];
    @endphp

    @if ($running)
        <meta http-equiv="refresh" content="15">
    @endif

    <h1>まとめて実行 #{{ $batch->id }}：{{ $batch->purpose->label() }}（{{ $batch->status->label() }}）</h1>

    <p>
        <a href="{{ route('ai.batches.index') }}">まとめて実行の一覧</a>
        ・<a href="{{ route('ai.generations.index') }}">AI実行記録の一覧</a>
    </p>

    @include('partials.flash')

    <table border="1" cellpadding="4" cellspacing="0">
        <tr><th style="text-align:left;">きっかけ</th><td>{{ $batch->trigger->label() }}{{ $batch->requester ? '（' . $batch->requester->name . '）' : '' }}</td></tr>
        <tr><th style="text-align:left;">対象</th><td>{{ $batch->target->label() }}@if (isset($batch->target_parameters['below_score']))（{{ $batch->target_parameters['below_score'] }}点未満）@endif @if (isset($batch->target_parameters['revision_scope']))・改修範囲 {{ \App\Services\Ai\AutoReevaluationService::scopeOptions()[$batch->target_parameters['revision_scope']] ?? $batch->target_parameters['revision_scope'] }}@endif</td></tr>
        <tr><th style="text-align:left;">モデル・推論の深さ</th><td>{{ $batch->model }}・{{ $batch->reasoning_effort }}</td></tr>
        @if ($batch->parent)
            <tr><th style="text-align:left;">元の品質診断</th><td><a href="{{ route('ai.batches.show', ['id' => $batch->parent->id]) }}">まとめて実行 #{{ $batch->parent->id }}</a></td></tr>
        @endif
        @if (isset($batch->follow_up['revision']))
            <tr><th style="text-align:left;">診断の後の編集案の作成</th><td>
                {{ $batch->follow_up['revision']['below_score'] }}点未満、または必須条件を満たさない記事を、{{ $batch->follow_up['revision']['model'] }}・{{ $batch->follow_up['revision']['effort'] }} で改修する
                @forelse ($batch->children as $child)
                    →<a href="{{ route('ai.batches.show', ['id' => $child->id]) }}">まとめて実行 #{{ $child->id }}（{{ $child->total_count }}件・{{ $child->status->label() }}）</a>
                @empty
                    @if ($batch->status === \App\Enums\AiBatchStatus::Completed)（対象の記事はありませんでした）@elseif ($batch->status === \App\Enums\AiBatchStatus::Running)（全ての記事の診断が終わったら登録します）@else（診断が途中で止まったため、作りません）@endif
                @endforelse
            </td></tr>
        @endif
        <tr><th style="text-align:left;">進み具合</th><td>
            全{{ $batch->total_count }}件：
            @foreach (\App\Enums\AiBatchItemStatus::cases() as $status)
                @if (($counts[$status->value] ?? 0) > 0) {{ $status->label() }} {{ $counts[$status->value] }}件 @endif
            @endforeach
        </td></tr>
        <tr><th style="text-align:left;">費用の目安</th><td>${{ number_format($progress['cost'], 4) }}</td></tr>
        @if ($batch->stop_reason)<tr><th style="text-align:left;">止めた理由</th><td style="color:#b00;">{{ $batch->stop_reason }}</td></tr>@endif
        <tr><th style="text-align:left;">日時</th><td>{{ \App\Support\DisplayTime::format($batch->created_at) }}〜{{ \App\Support\DisplayTime::format($batch->completed_at) }}</td></tr>
    </table>

    @if ($running)
        <p style="color:#666;">この画面は15秒ごとに更新されます。進まない場合は、Queueの処理が動いているか確認してください（ローカルでは <code>php artisan queue:work</code>）。</p>
        <form method="POST" action="{{ route('ai.batches.cancel', ['id' => $batch->id]) }}" onsubmit="return confirm('残りの記事を実行せずに取り消しますか？');">
            @csrf
            @include('partials.selected-blog-field')
            <button type="submit">取り消す（実行中の記事は最後まで実行します）</button>
        </form>
    @endif

    <h2>記事ごとの結果</h2>
    <div style="overflow-x:auto;">
        <table border="1" cellpadding="4" cellspacing="0">
            @php $isRevision = $batch->purpose === \App\Enums\AiMode::Revision; @endphp
            <thead><tr><th>記事</th>@if ($batch->trigger === \App\Enums\AiBatchTrigger::Auto && ! $isRevision)<th>理由</th>@endif<th>状態</th>@if ($isRevision)<th>改修範囲</th><th>点数（改修前→編集案）</th>@endif<th>結果</th><th>費用の目安</th><th>メッセージ</th></tr></thead>
            <tbody>
                @foreach ($items as $item)
                    @php $article = $item->post ?? $item->page; @endphp
                    <tr>
                        <td>
                            @if ($article)
                                <a href="{{ route('articles.show', ['type' => $item->post ? 'posts' : 'pages', 'id' => $article->id]) }}">{{ $article->title_raw }}</a>
                            @else - @endif
                        </td>
                        @if ($batch->trigger === \App\Enums\AiBatchTrigger::Auto && ! $isRevision)<td>{{ $item->reason?->label() }}</td>@endif
                        <td>{{ $item->status->label() }}</td>
                        @if ($isRevision)
                            <td>{{ $item->revision_scope?->label() ?? '-' }}</td>
                            <td>
                                {{ $item->score_before !== null ? number_format($item->score_before, 1) : '-' }}
                                → {{ $item->score_after !== null ? number_format($item->score_after, 1) : '-' }}
                                @if ($item->score_before !== null && $item->score_after !== null)
                                    （{{ $item->score_after >= $item->score_before ? '+' : '' }}{{ number_format($item->score_after - $item->score_before, 1) }}）
                                @endif
                            </td>
                        @endif
                        <td>
                            @if ($item->generation)
                                <a href="{{ route('ai.generations.show', ['id' => $item->generation->id]) }}">AI実行記録 #{{ $item->generation->id }}</a>
                                @foreach ($item->generation->evaluations as $evaluation)
                                    ・<a href="{{ route('evaluations.show', ['id' => $evaluation->id]) }}">{{ $evaluation->score !== null ? number_format($evaluation->score, 1) . '点' : '評価' }}</a>
                                @endforeach
                                @foreach ($item->generation->createdDrafts as $draft)
                                    ・<a href="{{ route('drafts.edit', ['id' => $draft->id]) }}">編集案 #{{ $draft->id }}</a>
                                @endforeach
                                @if ($batch->purpose === \App\Enums\AiMode::ManagementSuggestion && $item->status === \App\Enums\AiBatchItemStatus::Succeeded)
                                    ・<a href="{{ route('management-suggestions.index') }}">案を確認する</a>
                                @endif
                            @else - @endif
                        </td>
                        @php $itemCost = (float) $item->generation?->estimated_cost + (float) $item->diagnosisGeneration?->estimated_cost; @endphp
                        <td>{{ $item->generation?->estimated_cost !== null ? '$' . number_format($itemCost, 4) : '-' }}</td>
                        <td>{{ $item->message }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@endsection
