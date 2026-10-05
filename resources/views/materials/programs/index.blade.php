{{--
    アフィリエイトのプログラム（提携先の広告）の一覧と状態（D-33-08）
--}}

@extends('layouts.app')

@section('content')

    <h1>アフィリエイトのプログラム：{{ $programs->count() }}件</h1>

    <p>
        <a href="{{ route('materials.index') }}">教材の一覧に戻る</a>
    </p>

    @include('partials.flash')

    <p class="text-muted">
        ASP（もしもアフィリエイトなど）で提携している広告と、その状態です。状態が「申請中・否認・提携終了」のプログラムのリンクは、記事で紹介に使いません（AIの教材の候補から外します）。
        「未確認」は、記事のリンクから自動で登録したもので、今の記事を止めないよう使える扱いにしています。ASP の管理画面で状態を確かめて、直してください。
    </p>

    <form method="POST" action="{{ route('materials.programs.register') }}" style="margin-bottom:8px;">
        @csrf
        @include('partials.selected-blog-field')
        <button class="btn-secondary" type="submit">記事のリンクから、未登録のプログラムを登録する</button>
    </form>

    <form method="POST" action="{{ route('materials.programs.check') }}" style="margin-bottom:8px;">
        @csrf
        @include('partials.selected-blog-field')
        <button class="btn-secondary" type="submit">リンクを今すぐ確かめる</button>
        <span class="text-muted">（毎週月曜に自動で確かめます。記事で使っているプログラムごとにリンクを1本だけ開き、提携が終わっていないかを見ます。状態は自動では変えません）</span>
    </form>

    @php $suspects = $programs->filter(fn ($program) => $program->isUsable() && $program->check_result === \App\Enums\AffiliateLinkCheckResult::Suspect); @endphp
    @if ($suspects->isNotEmpty())
        <section class="panel">
        <h2 class="text-error">提携終了の疑いがあるプログラム</h2>
        <p class="text-muted">ASP の管理画面（もしもならプロモーション検索・提携中のプロモーション）で確かめ、終わっていたら状態を「提携終了」にしてください。</p>
        <ul>
            @foreach ($suspects as $program)
                <li>{{ $program->name }}：{{ $program->check_detail }}（{{ \App\Support\DisplayTime::format($program->checked_at) }}）</li>
            @endforeach
        </ul>
        </section>
    @endif

    @php
        $problems = $programs->filter(fn ($program) => ! $program->isUsable() && ! empty($usage[$program->program_key]));
        $unregistered = array_diff(array_keys($usage), $programs->keys()->all());
    @endphp

    @if ($problems->isNotEmpty())
        <section class="panel">
        <h2 class="text-error">提携中でないプログラムのリンクがある記事</h2>
        <p class="text-muted">リンクが無効になっている、または報酬が発生しない可能性があります。記事の改修で、別の教材に差し替えるか、紹介をやめてください（記事の教材の見直しでも、差し替えの対象として扱います）。</p>
        @foreach ($problems as $program)
            <details>
                <summary>{{ $program->name }}（{{ $program->status->label() }}）：{{ count($usage[$program->program_key]) }}件の記事</summary>
                <ul>
                    @foreach ($usage[$program->program_key] as $article)
                        <li><a href="{{ route('articles.show', ['type' => $article instanceof \App\Models\Post ? 'posts' : 'pages', 'id' => $article->id]) }}">{{ $article->title_raw }}</a></li>
                    @endforeach
                </ul>
            </details>
        @endforeach
        </section>
    @endif

    @if ($unregistered !== [])
        <p class="text-warn">記事のリンクに、未登録のプログラムがあります：{{ implode('、', $unregistered) }}。上のボタンで登録してください。</p>
    @endif

    @if ($programs->isNotEmpty())
        <div style="overflow-x:auto;">
            <table class="data">
                <thead>
                    <tr><th>名前</th><th>ASP・識別子</th><th>教材の種類</th><th>状態</th><th>メモ</th><th>教材</th><th>記事</th><th>リンクの確認</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($programs as $program)
                        @php $formId = "program-{$program->id}"; @endphp
                        <tr>
                            <td><input type="text" name="name" value="{{ $program->name }}" form="{{ $formId }}" style="width:200px;"></td>
                            <td>{{ $program->asp }}<br><span class="text-muted">{{ $program->program_key }}</span></td>
                            <td>
                                <select name="material_kind" form="{{ $formId }}">
                                    <option value="">（決めない）</option>
                                    @foreach (\App\Enums\MaterialKind::cases() as $option)
                                        <option value="{{ $option->value }}" @selected($program->material_kind === $option)>{{ $option->label() }}</option>
                                    @endforeach
                                </select>
                                <br><label class="text-muted"><input type="checkbox" name="align_kind" value="1" form="{{ $formId }}"> 教材の種類もそろえる</label>
                            </td>
                            <td>
                                <select name="status" form="{{ $formId }}">
                                    @foreach (\App\Enums\AffiliateProgramStatus::cases() as $option)
                                        <option value="{{ $option->value }}" @selected($program->status === $option)>{{ $option->label() }}</option>
                                    @endforeach
                                </select>
                                @if (! $program->isUsable())<br><span class="text-error">紹介に使わない</span>@endif
                                @if ($program->status_changed_on)<br><span class="text-muted">{{ $program->status_changed_on->format('Y-m-d') }}</span>@endif
                            </td>
                            <td><textarea name="memo" rows="2" form="{{ $formId }}" style="width:200px;">{{ $program->memo }}</textarea></td>
                            <td>{{ $materials[$program->program_key]->count() }}件</td>
                            <td>{{ count($usage[$program->program_key] ?? []) }}件</td>
                            <td style="max-width:220px;">
                                @if ($program->check_result)
                                    <span @class(['text-error' => $program->check_result !== \App\Enums\AffiliateLinkCheckResult::Ok])>{{ $program->check_result->label() }}</span>
                                    <br><span class="text-muted">{{ \App\Support\DisplayTime::format($program->checked_at) }}</span>
                                @else
                                    <span class="text-muted">未確認</span>
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('materials.programs.update', ['id' => $program->id]) }}" id="{{ $formId }}">
                                    @csrf
                                    @method('PUT')
                                    @include('partials.selected-blog-field')
                                    <button type="submit">保存</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <section class="panel">
    <h2>プログラムを登録する</h2>
    <p class="text-muted">まだ記事で使っていないプログラム（申請中・否認を含む）を登録します。もしもアフィリエイトの広告IDは、リンクの <code>p_id=</code> の数字です。</p>
    <form method="POST" action="{{ route('materials.programs.store') }}">
        @csrf
        @include('partials.selected-blog-field')
        <p>
            ASP
            <select name="asp">
                <option value="moshimo" @selected(old('asp') === 'moshimo')>もしもアフィリエイト</option>
                <option value="a8" @selected(old('asp') === 'a8')>A8.net</option>
                <option value="other" @selected(old('asp') === 'other')>その他</option>
            </select>
            広告ID <input type="text" name="external_id" value="{{ old('external_id') }}" style="width:100px;">
            名前 <input type="text" name="name" value="{{ old('name') }}" style="width:260px;">
        </p>
        <p>
            教材の種類
            <select name="material_kind">
                <option value="">（決めない）</option>
                @foreach (\App\Enums\MaterialKind::cases() as $option)
                    <option value="{{ $option->value }}" @selected(old('material_kind') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
            状態
            <select name="status">
                @foreach (\App\Enums\AffiliateProgramStatus::cases() as $option)
                    <option value="{{ $option->value }}" @selected(old('status', 'active') === $option->value)>{{ $option->label() }}</option>
                @endforeach
            </select>
        </p>
        <p>メモ <input type="text" name="memo" value="{{ old('memo') }}" style="width:400px;"></p>
        <p><button type="submit">登録する</button></p>
    </form>
    </section>

@endsection
