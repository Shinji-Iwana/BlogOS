{{--
    記事で使う画像の一覧（D-32）：AI で図を作る・画像をアップロードする
--}}

@extends('layouts.app')

@section('content')

    <h1>画像：{{ $images->count() }}件</h1>

    <p>
        <a href="{{ route('home') }}">トップページに戻る</a>
        ・<a href="{{ route('images.eyecatches') }}">カテゴリごとのアイキャッチ</a>
    </p>

    @include('partials.flash')
    @include('partials.ai-credit-notice')

    <p class="text-muted">
        図解は AI が SVG で作り、画面で確かめて PNG にします。例え話・概念のイメージなど、イラストの方が伝わる場合は、AI がイラストを選び、画像モデルで作ります（もう一方の形式でも作って比べられます）。
        スクリーンショット・実行結果は、実際の画面を撮ってアップロードしてください。確認済みにした画像を、WordPress のメディアに登録して記事で使います。
    </p>

    <p>
        種類：
        <a href="{{ route('images.index', array_filter(['status' => $status?->value])) }}">@if (! $kind)<strong>すべて</strong>@else すべて @endif</a>
        @foreach (\App\Enums\ImageKind::cases() as $option)
            ・<a href="{{ route('images.index', array_filter(['kind' => $option->value, 'status' => $status?->value])) }}">@if ($kind === $option)<strong>{{ $option->label() }}</strong>@else{{ $option->label() }}@endif</a>
        @endforeach
        ／ 状態：
        <a href="{{ route('images.index', array_filter(['kind' => $kind?->value])) }}">@if (! $status)<strong>すべて</strong>@else すべて @endif</a>
        @foreach (\App\Enums\ImageStatus::cases() as $option)
            ・<a href="{{ route('images.index', array_filter(['kind' => $kind?->value, 'status' => $option->value])) }}">@if ($status === $option)<strong>{{ $option->label() }}</strong>@else{{ $option->label() }}@endif</a>
        @endforeach
    </p>

    <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-start;">
        <fieldset style="max-width:560px;">
            <legend>AI で図を作る</legend>
            <form method="POST" action="{{ route('images.design') }}">
                @csrf
                @include('partials.selected-blog-field')
                <p><label>図の名前<br><input type="text" name="title" value="{{ old('title') }}" style="width:100%;" required placeholder="例：addEventListener の処理の流れ"></label></p>
                <p><label>どんな図か<br><textarea name="description" rows="4" style="width:100%;" required placeholder="例：ボタンをクリックしてから、イベントが発生し、登録した関数が呼ばれるまでの流れ">{{ old('description') }}</textarea></label></p>
                <p>
                    形式：
                    @foreach ($formats as $value => $label)
                        <label><input type="radio" name="format" value="{{ $value }}" @checked(old('format', 'auto') === $value)> {{ $label }}</label>
                    @endforeach
                </p>
                <p><label>図を載せる記事（任意。<code>posts:ID</code>）<input type="text" name="target" value="{{ old('target', $target) }}" style="width:140px;" placeholder="posts:123"></label></p>
                <p><label>補足（任意）<br><textarea name="notes" rows="2" style="width:100%;">{{ old('notes') }}</textarea></label></p>
                @include('materials.partials.method', ['method' => config('blogos.ai.methods.image_design', 'manual'), 'prefix' => 'design'])
                @if ($api['configured'])
                    <p class="text-muted">AI がイラストを選んだ場合、API実行なら続けて画像モデル（{{ $api['imageModel'] }}・{{ $api['quality'] }}）で作ります（1枚あたり数セント〜）。@include('partials.ai-cost-line')</p>
                @endif
                <button type="submit">図を作る</button>
            </form>
        </fieldset>

        <fieldset style="max-width:460px;">
            <legend>画像をアップロードする</legend>
            <form method="POST" action="{{ route('images.store') }}" enctype="multipart/form-data">
                @csrf
                @include('partials.selected-blog-field')
                <p>
                    種類：
                    @foreach (\App\Enums\ImageKind::cases() as $option)
                        <label><input type="radio" name="kind" value="{{ $option->value }}" @checked(old('kind', 'screenshot') === $option->value)> {{ $option->label() }}</label>
                    @endforeach
                </p>
                <p><label>画像の名前<br><input type="text" name="title" value="" style="width:100%;" required placeholder="例：Chrome の開発者ツールのコンソール"></label></p>
                <p><label>説明（任意）<br><textarea name="description" rows="2" style="width:100%;"></textarea></label></p>
                <p>
                    <input type="file" name="file" accept="image/png,image/jpeg,image/webp" data-resize="upload-note" required><br>
                    <span id="upload-note" class="text-muted"></span>
                    <span class="text-muted">PNG・JPEG・WebP（{{ number_format(config('blogos.ai.image.max_upload_kb') / 1024) }}MB まで）。横幅が{{ config('blogos.ai.image.max_width') }}px を超える画像は、送る前に縮めます。</span>
                </p>
                <button type="submit">アップロードする</button>
            </form>
        </fieldset>
    </div>

    <section class="panel">
    <h2>画像の一覧</h2>
    @if ($images->isEmpty())
        <p>画像はまだありません。</p>
    @else
        <div style="display:flex; flex-wrap:wrap; gap:12px;">
            @foreach ($images as $image)
                <div class="bordered" style="width:220px; padding:6px;">
                    <a href="{{ route('images.show', ['id' => $image->id]) }}">
                        @if ($image->hasFile())
                            <img src="{{ route('images.file', ['id' => $image->id]) }}?v={{ $image->updated_at?->timestamp }}" alt="{{ $image->alt }}" class="bg-subtle" style="width:100%; height:130px; object-fit:contain;" loading="lazy">
                        @elseif (filled($image->svg_source))
                            <img src="data:image/svg+xml;base64,{{ base64_encode($image->svg_source) }}" alt="{{ $image->alt }}" class="bg-subtle" style="width:100%; height:130px; object-fit:contain;">
                        @else
                            <div class="bg-subtle text-faint" style="height:130px; display:flex; align-items:center; justify-content:center;">画像なし</div>
                        @endif
                    </a>
                    <div style="font-size:13px;">
                        <a href="{{ route('images.show', ['id' => $image->id]) }}">{{ $image->title }}</a><br>
                        {{ $image->kind->label() }}・<span @class(['text-ok' => $image->isReady()])>{{ $image->status->label() }}</span>
                        @if ($image->media_id)・<span class="text-ok">WordPress 登録済み</span>@endif
                        @if ($image->variantOf)<br><span class="text-muted">「{{ $image->variantOf->title }}」の別の形式</span>@endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @include('images.partials.resize-script')
    </section>

@endsection
