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

    <p style="color:#666;">
        記事の本文にある教材のリンクは、同期のたびに照合し、どの記事でどの教材を使っているかを記録します。
        記事に合う教材を選ぶための情報（分野・レベル・向いている場面など）は、各教材の「AIで調べる」で調べ、人が確認して登録します。
    </p>

    <p>
        種類：
        <a href="{{ route('materials.index') }}">@if (! $kind)<strong>すべて</strong>@else すべて @endif</a>
        @foreach (\App\Enums\MaterialKind::cases() as $option)
            ・<a href="{{ route('materials.index', ['kind' => $option->value]) }}">@if ($kind === $option)<strong>{{ $option->label() }}</strong>@else{{ $option->label() }}@endif</a>
        @endforeach
    </p>

    <form method="POST" action="{{ route('materials.relink') }}" style="margin-bottom:8px;">
        @csrf
        @include('partials.selected-blog-field')
        <button type="submit">記事との照合をやり直す</button>
        <span style="color:#666;">（教材を登録・更新したときは自動で行います）</span>
    </form>

    @if ($materials->isEmpty())
        <p>教材はまだありません。まずは「既存の記事のリンクから登録する」から、記事で使っている教材を登録してください。</p>
    @else
        <form method="POST" action="{{ route('materials.research.many') }}" onsubmit="return confirm('チェックした教材を、AIでまとめて調べますか？（API実行・料金がかかります）');">
            @csrf
            @include('partials.selected-blog-field')

            <div style="overflow-x:auto;">
                <table border="1" cellpadding="4" cellspacing="0">
                    <thead>
                        <tr>
                            <th><input type="checkbox" onclick="document.querySelectorAll('.material-check').forEach(c => c.checked = this.checked)"></th>
                            <th>種類</th><th>名前</th><th>版・出版日</th><th>カテゴリ・分野の語句</th><th>レベル・向いている場面</th><th>状態</th><th>使っている記事</th><th>AIで調べた日</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($materials as $material)
                            <tr>
                                <td><input type="checkbox" class="material-check" name="selected[]" value="{{ $material->id }}" @checked($material->researched_at === null)></td>
                                <td>{{ $material->kind->label() }}</td>
                                <td>
                                    <a href="{{ route('materials.edit', ['id' => $material->id]) }}">{{ $material->name }}</a>
                                    @if ($material->successors->isNotEmpty())<br><span style="color:#b60;">新しい版：{{ $material->successors->pluck('name')->implode('、') }}</span>@endif
                                    @if ($material->previous)<br><span style="color:#666;">前の版：{{ $material->previous->name }}</span>@endif
                                    @if ($programProblems[$material->id] ?? [])<br><span style="color:#b00;">提携中でないリンク：{{ implode('／', $programProblems[$material->id]) }}</span>@endif
                                </td>
                                <td>{{ $material->edition }} {{ $material->published_on?->format('Y-m-d') }}</td>
                                <td style="max-width:260px;">
                                    {{ $material->categories->pluck('name')->implode('、') }}
                                    @if ($material->topics)<br><span style="color:#666;">{{ implode('、', $material->topics) }}</span>@endif
                                </td>
                                <td style="max-width:220px;">
                                    {{ implode('、', array_map(fn ($v) => \App\Models\Material::LEVELS[$v] ?? $v, (array) $material->levels)) }}
                                    @if ($material->scenes)<br><span style="color:#666;">{{ implode('、', array_map(fn ($v) => \App\Models\Material::SCENES[$v] ?? $v, $material->scenes)) }}</span>@endif
                                </td>
                                <td>@if ($material->isActive()){{ $material->status->label() }}@else<span style="color:#b00;">{{ $material->status->label() }}</span>@endif</td>
                                <td>
                                    {{ $material->article_materials_count }}件
                                    @if ($needsReview[$material->id] ?? 0)<br><a href="{{ route('materials.reviews.index') }}" style="color:#b60;">見直し {{ $needsReview[$material->id] }}件</a>@endif
                                </td>
                                <td>{{ $material->researched_at ? \App\Support\DisplayTime::format($material->researched_at) : '未調査' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <h2>チェックした教材を、AIでまとめて調べる</h2>
            @if ($api['configured'])
                <p style="color:#666;">
                    API実行だけです（1つずつ調べるときは、教材の画面から手動実行も選べます）。結果は「教材の案の確認」で確認して登録します。
                    @include('partials.ai-cost-line')。
                </p>
                @include('materials.partials.method', ['method' => 'api', 'webSearch' => true, 'prefix' => 'bulk', 'apiOnly' => true, 'api' => $api + ['defaults' => config('blogos.ai.api.defaults.material_research')]])
                <p><button type="submit">チェックした教材を調べる</button></p>
            @else
                <p style="color:#b00;">APIキーが設定されていないため、まとめて調べることはできません。教材の画面から、手動実行で1つずつ調べてください。</p>
            @endif
        </form>
    @endif

@endsection
