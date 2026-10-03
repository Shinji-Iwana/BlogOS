{{-- 記事の企画の案の1行（$suggestion、$child：子カテゴリの案の最初の記事の案か） --}}
<tr @class(['row-group' => $suggestion->type === 'category'])>
    <td>{{ $child ? '' : $suggestion->category?->name }}</td>
    <td style="max-width:460px;">
        @if ($child)　└ @endif
        @if ($suggestion->type === 'category')
            <strong>子カテゴリの案：{{ $suggestion->title }}</strong>（{{ $suggestion->slug }}）
            @if ($suggestion->scope)<br>{{ $suggestion->scope }}@endif
            @if ($suggestion->roadmap_step)<br><span class="text-muted">位置：{{ $suggestion->roadmap_step }}</span>@endif
        @else
            {{ $suggestion->title }}
            <br><span class="text-muted">
                キーワード：{{ $suggestion->main_keyword ?? '-' }}
                @if ($suggestion->article_type)・{{ $types['types'][$suggestion->article_type] ?? $suggestion->article_type }}{{ $suggestion->article_subtype ? '（' . ($types['subtypes'][$suggestion->article_subtype] ?? $suggestion->article_subtype) . '）' : '' }}@endif
                @if ($suggestion->roadmap_step)・ステップ：{{ $suggestion->roadmap_step }}@endif
            </span>
            @if ($suggestion->search_intent)<br><span class="text-muted">検索意図：{{ $suggestion->search_intent }}</span>@endif
        @endif
    </td>
    <td>{{ \App\Models\TopicSuggestion::PRIORITIES[$suggestion->priority] ?? '-' }}</td>
    <td style="max-width:320px;">
        {{ $suggestion->reason }}
        @if ($suggestion->duplicate_note)<br><span class="text-error">重複の可能性：{{ $suggestion->duplicate_note }}</span>@endif
        @foreach ((array) $suggestion->sources as $url)<br><a href="{{ $url }}" target="_blank" rel="noopener noreferrer" style="font-size:90%;">{{ \Illuminate\Support\Str::limit($url, 50) }}</a>@endforeach
    </td>
    <td style="white-space:nowrap;">
        @foreach (['accept' => '採用', 'reject' => '見送り'] as $action => $label)
            <form method="POST" action="{{ route('topics.review', ['id' => $suggestion->id]) }}" style="display:inline;">
                @csrf
                @include('partials.selected-blog-field')
                <input type="hidden" name="action" value="{{ $action }}">
                <button type="submit" @class(['btn-secondary' => $action === 'reject'])>{{ $label }}</button>
            </form>
        @endforeach
        @if ($suggestion->type === 'article')
            <br><a href="{{ route('ai.generations.create', \App\Support\TopicLinks::newArticle($suggestion)) }}">この案で新規記事を作る</a>
        @endif
    </td>
</tr>
