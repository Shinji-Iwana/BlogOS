{{--
    記事の教材の見直し（D-30）：見直しが必要な記事と、AIの見直しの結果の確認
--}}

@extends('layouts.app')

@section('content')

    <h1>記事の教材の見直し（{{ $blog->display_name }}）</h1>

    <p>
        <a href="{{ route('materials.index') }}">教材の一覧に戻る</a>
        ・<a href="{{ route('ai.batches.create', ['mode' => 'material_review']) }}">AIでまとめて見直す</a>
    </p>

    @include('partials.flash')

    <p style="color:#666;">
        教材を「使わない」にした・新しい版を登録した・教材の情報を更新した・記事が更新された記事は、紹介している教材が今のままでよいか見直します。
        AIの見直しの結果を確認したら「確認した」を押してください。教材の差し替え・削除は、記事の改修で行います（記事は自動では変わりません）。
    </p>

    <h2>AIの見直しの結果（確認待ち）：{{ $reviews->count() }}件</h2>
    @forelse ($reviews as $review)
        @php $article = $review->article(); @endphp
        <fieldset style="max-width:900px; margin-bottom:12px;">
            <legend>
                @if ($article)
                    <a href="{{ route('articles.show', ['type' => $review->post_id ? 'posts' : 'pages', 'id' => $article->id]) }}">{{ $article->title_raw }}</a>
                @endif
                （<a href="{{ route('ai.generations.show', ['id' => $review->ai_generation_id]) }}">AI実行記録 #{{ $review->ai_generation_id }}</a>）
            </legend>
            @if ($review->summary)<p>{{ $review->summary }}</p>@endif
            <ul>
                @foreach ((array) ($review->result['current'] ?? []) as $item)
                    <li>
                        <strong>{{ ['keep' => 'このまま', 'replace' => '差し替え', 'remove' => '外す'][$item['judgment']] ?? $item['judgment'] }}</strong>：
                        {{ $materialNames[$item['material_id']] ?? "#{$item['material_id']}" }}
                        @if ($item['replace_with']) → {{ $materialNames[$item['replace_with']] ?? "#{$item['replace_with']}" }}@endif
                        @if ($item['reason'])<br><span style="color:#666;">{{ $item['reason'] }}</span>@endif
                    </li>
                @endforeach
                @foreach ((array) ($review->result['additions'] ?? []) as $item)
                    <li>
                        <strong>追加</strong>：{{ $materialNames[$item['material_id']] ?? "#{$item['material_id']}" }}
                        @if ($item['reason'])<br><span style="color:#666;">{{ $item['reason'] }}</span>@endif
                    </li>
                @endforeach
                @if (empty($review->result['current']) && empty($review->result['additions']))
                    <li>教材は紹介しない判断です。</li>
                @endif
            </ul>
            <form method="POST" action="{{ route('materials.reviews.confirm', ['id' => $review->id]) }}" style="display:inline;">
                @csrf
                @include('partials.selected-blog-field')
                <button type="submit">確認した</button>
            </form>
            <form method="POST" action="{{ route('materials.reviews.reject', ['id' => $review->id]) }}" style="display:inline;">
                @csrf
                @include('partials.selected-blog-field')
                <button type="submit">不採用にする</button>
            </form>
        </fieldset>
    @empty
        <p>確認待ちの結果はありません。</p>
    @endforelse

    <h2>見直しが必要な記事：{{ count($articles) }}件</h2>
    @if ($articles === [])
        <p>見直しが必要な記事はありません。</p>
    @else
        <form method="POST" action="{{ route('materials.reviews.run') }}" id="review-run-form">
            @csrf
            @include('partials.selected-blog-field')
            <input type="hidden" name="target" id="review-target">
            <fieldset style="max-width:800px;">
                <legend>「AIで見直す」の実行方式</legend>
                @include('materials.partials.method', ['prefix' => 'review'])
            </fieldset>
        </form>

        <div style="overflow-x:auto; margin-top:8px;">
            <table border="1" cellpadding="4" cellspacing="0">
                <thead><tr><th>記事</th><th>教材と理由</th><th></th></tr></thead>
                <tbody>
                    @foreach ($articles as $key => $row)
                        @php $type = \App\Http\Controllers\Materials\MaterialReviewController::articleType($row['article']); @endphp
                        <tr>
                            <td><a href="{{ route('articles.show', ['type' => $type, 'id' => $row['article']->id]) }}">{{ $row['article']->title_raw }}</a></td>
                            <td>
                                @foreach ($row['items'] as $item)
                                    <div>{{ $item['material']?->name }}：<span style="color:#b60;">{{ $item['reason'] }}</span></div>
                                @endforeach
                            </td>
                            <td style="white-space:nowrap;">
                                @if (isset($pending[$type][$row['article']->id]))
                                    <span style="color:#666;">確認待ちの結果あり</span><br>
                                @endif
                                <button type="button" onclick="document.getElementById('review-target').value = '{{ $key }}'; document.getElementById('review-run-form').submit();">AIで見直す</button>
                                <form method="POST" action="{{ route('materials.reviews.mark') }}" style="display:inline;">
                                    @csrf
                                    @include('partials.selected-blog-field')
                                    <input type="hidden" name="target" value="{{ $key }}">
                                    <button type="submit">このままでよい</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection
