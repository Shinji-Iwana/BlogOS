{{-- 立ち上げに加えられる子カテゴリの選択（$options） --}}
@if ($options['categories']->isEmpty() && $options['suggestions']->isEmpty())
    <p>加えられる子カテゴリはありません。<a href="{{ route('topics.index') }}">記事の企画</a>で、足りない子カテゴリの案を出して採用してください。</p>
@else
    @if ($options['categories']->isNotEmpty())
        <p style="margin-bottom:0;">既存の子カテゴリ：</p>
        <ul style="list-style:none; margin-top:0;">
            @foreach ($options['categories'] as $category)
                <li><label><input type="checkbox" name="categories[]" value="{{ $category->id }}" @checked(($options['counts'][$category->id] ?? 0) === 0)> {{ $category->name }}（{{ $category->slug }}・{{ $options['counts'][$category->id] ?? 0 }}記事）</label></li>
            @endforeach
        </ul>
    @endif
    @if ($options['suggestions']->isNotEmpty())
        <p style="margin-bottom:0;">採用した子カテゴリの案（WordPress にはまだありません）：</p>
        <ul style="list-style:none; margin-top:0;">
            @foreach ($options['suggestions'] as $suggestion)
                <li><label><input type="checkbox" name="suggestions[]" value="{{ $suggestion->id }}" checked> {{ $suggestion->title }}（{{ $suggestion->slug }}）</label></li>
            @endforeach
        </ul>
    @endif
@endif
