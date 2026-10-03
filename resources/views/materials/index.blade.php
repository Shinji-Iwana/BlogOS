{{--
    収益用の教材（書籍・Udemy・スクール）の一覧（D-30）
--}}

@extends('layouts.app')

@section('content')

    <h1>教材（{{ $blog->display_name }}）：{{ $materials->count() }}件</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・登録する：<a href="{{ route('materials.create', ['kind' => 'book']) }}">書籍</a>
        ／<a href="{{ route('materials.create', ['kind' => 'udemy']) }}">Udemy</a>
        ／<a href="{{ route('materials.create', ['kind' => 'school']) }}">スクール</a>
        ／<a href="{{ route('materials.create', ['kind' => 'question_bank']) }}">問題集・オンライン教材</a>
        ・<a href="{{ route('materials.programs.index') }}">アフィリエイトのプログラム（提携の状態）</a>
        ・<a href="{{ route('materials.detected') }}">既存の記事のリンクから登録する</a>
        ・<a href="{{ route('materials.discover.create') }}">AIで新しい教材の候補を探す</a>
        ・<a href="{{ route('materials.suggestions.index') }}">教材の案の確認</a>（{{ $pendingSuggestions }}件）
        ・<a href="{{ route('materials.reviews.index') }}">記事の教材の見直し</a>
    </p>

    @include('partials.flash')

    @include('partials.ai-credit-notice')

    <p class="text-muted">
        記事の本文にある教材のリンクは、同期のたびに照合し、どの記事でどの教材を使っているかを記録します。
        記事に合う教材を選ぶための情報（分野・レベル・向いている場面など）は、各教材の「AIで調べる」で調べ、人が確認して登録します。
    </p>

    {{-- カテゴリごとのそろい具合（D-45） --}}
    @php $kinds = \App\Enums\MaterialKind::cases(); @endphp
    <details @if (! $category) open @endif>
        <summary><strong>カテゴリごとの、記事で紹介に使える教材の数</strong></summary>
        <p class="text-muted" style="margin:4px 0;">
            記事の改修・新規記事作成では、記事のカテゴリと親カテゴリに登録した教材のうち、情報を調べてあり、提携中のリンクがある教材が候補になります。
            数は「このカテゴリに登録（＋親カテゴリに登録）」。記事があるのに0件の種類は赤字です（すべての種類が必要なわけではありません）。
        </p>
        <table class="data data-compact" style="font-size:90%;">
            <thead><tr><th>カテゴリ</th><th>記事</th>@foreach ($kinds as $option)<th>{{ $option->label() }}</th>@endforeach<th>使えない教材</th></tr></thead>
            <tbody>
                @foreach ($categories as $row)
                    @php $c = $coverage[$row->id] ?? null; @endphp
                    @continue($c === null)
                    <tr @if ($category?->id === $row->id) class="row-current" @endif>
                        <td style="padding-left:{{ 4 + ($row->depth ?? 0) * 16 }}px;"><a href="{{ route('materials.index', ['category' => $row->id]) }}">{{ $row->name }}</a></td>
                        <td>{{ $c['posts'] }}</td>
                        @foreach ($kinds as $option)
                            @php $own = $c['own'][$option->value]; $inherited = $c['inherited'][$option->value]; @endphp
                            <td @if ($c['posts'] > 0 && $own + $inherited === 0) class="text-error" @endif>{{ $own }}@if ($inherited > 0)（+{{ $inherited }}）@endif</td>
                        @endforeach
                        <td @if ($c['unusable'] > 0) class="text-warn" @endif>{{ $c['unusable'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>

    @if ($category)
        @php $c = $coverage[$category->id]; @endphp
        <div class="bordered" style="padding:8px 12px; margin:12px 0;">
            <strong>カテゴリ「{{ $category->name }}」</strong>（公開中の記事 {{ $c['posts'] }}件）：
            @foreach ($kinds as $option)
                {{ $option->label() }} {{ $c['own'][$option->value] + $c['inherited'][$option->value] }}件{{ $loop->last ? '' : '・' }}
            @endforeach
            @if ($c['unusable'] > 0)・<span class="text-warn">使えない教材 {{ $c['unusable'] }}件</span>@endif
            <br>
            足りないとき：<a href="{{ route('materials.discover.create', ['category' => $category->id]) }}">このカテゴリで、AIで新しい教材の候補を探す</a>
            ・情報を調べていない教材は、下の一覧でチェックして（初めから調べていない教材にチェックが入っています）「チェックした教材を調べる」
            ・カテゴリの付け直しは、教材の名前から編集
            ・<a href="{{ route('materials.index', ['kind' => $kind?->value]) }}">絞り込みを外す</a>
        </div>
    @endif

    <p>
        種類：
        <a href="{{ route('materials.index', ['category' => $category?->id]) }}">@if (! $kind)<strong>すべて</strong>@else すべて @endif</a>
        @foreach (\App\Enums\MaterialKind::cases() as $option)
            ・<a href="{{ route('materials.index', ['kind' => $option->value, 'category' => $category?->id]) }}">@if ($kind === $option)<strong>{{ $option->label() }}</strong>@else{{ $option->label() }}@endif</a>
        @endforeach
    </p>
    <form method="GET" action="{{ route('materials.index') }}" class="panel" data-code="FILTER" style="margin-bottom:8px;">
        @if ($kind)<input type="hidden" name="kind" value="{{ $kind->value }}">@endif
        <label>カテゴリ：
            <select name="category" onchange="this.form.submit()">
                <option value="">すべて</option>
                @foreach ($categories as $option)
                    <option value="{{ $option->id }}" @selected($category?->id === $option->id)>{{ str_repeat('　', $option->depth ?? 0) }}{{ $option->name }}</option>
                @endforeach
            </select>
        </label>
        <noscript><button class="btn-secondary" type="submit">絞り込む</button></noscript>
        @if ($category)<span class="text-muted">（親カテゴリに登録した教材も出します。記事で候補になる範囲と同じです）</span>@endif
    </form>

    <form method="POST" action="{{ route('materials.relink') }}" style="margin-bottom:8px;">
        @csrf
        @include('partials.selected-blog-field')
        <button class="btn-secondary" type="submit">記事との照合をやり直す</button>
        <span class="text-muted">（教材を登録・更新したときは自動で行います）</span>
    </form>

    @if ($materials->isEmpty())
        <p>教材はまだありません。まずは「既存の記事のリンクから登録する」から、記事で使っている教材を登録してください。</p>
    @else
        <form method="POST" action="{{ route('materials.research.many') }}" onsubmit="return confirm('チェックした教材を、AIでまとめて調べますか？（API実行・料金がかかります）');">
            @csrf
            @include('partials.selected-blog-field')

            <div style="overflow-x:auto;">
                <table class="data">
                    <thead>
                        <tr>
                            <th><input type="checkbox" onclick="document.querySelectorAll('.material-check').forEach(c => c.checked = this.checked)"></th>
                            <th>種類</th><th>名前</th><th>版・出版日</th><th>カテゴリ・分野の語句</th><th>レベル・向いている場面</th><th>状態</th><th>記事で紹介に使えるか</th><th>使っている記事</th><th>AIで調べた日</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($materials as $material)
                            <tr>
                                <td><input type="checkbox" class="material-check" name="selected[]" value="{{ $material->id }}" @checked($material->researched_at === null)></td>
                                <td>{{ $material->kind->label() }}</td>
                                <td>
                                    <a href="{{ route('materials.edit', ['id' => $material->id]) }}">{{ $material->name }}</a>
                                    @if ($material->successors->isNotEmpty())<br><span class="text-warn">新しい版：{{ $material->successors->pluck('name')->implode('、') }}</span>@endif
                                    @if ($material->previous)<br><span class="text-muted">前の版：{{ $material->previous->name }}</span>@endif
                                    @if ($programProblems[$material->id] ?? [])<br><span class="text-error">提携中でないリンク：{{ implode('／', $programProblems[$material->id]) }}</span>@endif
                                </td>
                                <td>{{ $material->edition }} {{ $material->published_on?->format('Y-m-d') }}</td>
                                <td style="max-width:260px;">
                                    {{ $material->categories->pluck('name')->implode('、') }}
                                    @if ($ancestorIds !== [] && $material->categories->pluck('id')->intersect($ancestorIds)->isNotEmpty() && ! $material->categories->contains('id', $category->id))
                                        <br><span class="text-muted">（親カテゴリに登録）</span>
                                    @endif
                                    @if ($material->topics)<br><span class="text-muted">{{ implode('、', $material->topics) }}</span>@endif
                                </td>
                                <td style="max-width:220px;">
                                    {{ implode('、', array_map(fn ($v) => \App\Models\Material::LEVELS[$v] ?? $v, (array) $material->levels)) }}
                                    @if ($material->scenes)<br><span class="text-muted">{{ implode('、', array_map(fn ($v) => \App\Models\Material::SCENES[$v] ?? $v, $material->scenes)) }}</span>@endif
                                </td>
                                <td>@if ($material->isActive()){{ $material->status->label() }}@else<span class="text-error">{{ $material->status->label() }}</span>@endif</td>
                                <td style="max-width:220px;">
                                    @if (($usability[$material->id] ?? []) === [])
                                        使える
                                    @else
                                        <span class="text-error">使えない：{{ implode('／', $usability[$material->id]) }}</span>
                                    @endif
                                    @if ($material->categories->isEmpty())<br><span class="text-warn">カテゴリが未登録（どの記事の候補にもなりにくい）</span>@endif
                                </td>
                                <td>
                                    {{ $material->article_materials_count }}件
                                    @if ($needsReview[$material->id] ?? 0)<br><a href="{{ route('materials.reviews.index') }}" class="text-warn">見直し {{ $needsReview[$material->id] }}件</a>@endif
                                </td>
                                <td>{{ $material->researched_at ? \App\Support\DisplayTime::format($material->researched_at) : '未調査' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <section class="panel">
            <h2>チェックした教材を、AIでまとめて調べる</h2>
            @if ($api['configured'])
                <p class="text-muted">
                    API実行だけです（1つずつ調べるときは、教材の画面から手動実行も選べます）。結果は「教材の案の確認」で確認して登録します。
                    @include('partials.ai-cost-line')。
                </p>
                @include('materials.partials.method', ['method' => 'api', 'webSearch' => true, 'prefix' => 'bulk', 'apiOnly' => true, 'api' => $api + ['defaults' => config('blogos.ai.api.defaults.material_research')]])
                <p><button type="submit">チェックした教材を調べる</button></p>
            @else
                <p class="text-error">APIキーが設定されていないため、まとめて調べることはできません。教材の画面から、手動実行で1つずつ調べてください。</p>
            @endif
            </section>
        </form>
    @endif

@endsection
