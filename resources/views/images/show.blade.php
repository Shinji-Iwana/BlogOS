{{--
    画像の詳細（D-32）：確認・alt 等の入力・SVG の編集と PNG への変換・画像モデルでの作成・別の形式との比較・WordPress への登録
--}}

@extends('layouts.app')

@section('content')

    @php
        $isDiagram = $image->kind === \App\Enums\ImageKind::Diagram;
        $svgSize = filled($image->svg_source) ? \App\Support\SvgSanitizer::size($image->svg_source) : null;
        $running = $image->generations->firstWhere('status', \App\Enums\AiGenerationStatus::Running);
    @endphp

    <h1>画像：{{ $image->title }}</h1>

    <p>
        <a href="{{ route('images.index') }}">画像の一覧に戻る</a>
        @if ($image->variantOf) ・比べる元の画像：<a href="{{ route('images.show', ['id' => $image->variantOf->id]) }}">{{ $image->variantOf->title }}</a>@endif
    </p>

    @include('partials.flash')

    <p>
        種類：{{ $image->kind->label() }}
        ・状態：<strong @style(['color:#070' => $image->isReady()])>{{ $image->status->label() }}</strong>
        @if ($image->source)・作り方：{{ $image->source->label() }}@endif
        @if ($image->width)・大きさ：{{ $image->width }}×{{ $image->height }}（{{ number_format((int) $image->file_size / 1024) }}KB）@endif
        @if ($image->media)・<span style="color:#070;">WordPress に登録済み（メディア ID {{ $image->media->wordpress_id }}）</span>@endif
    </p>
    @if ($image->description)<p style="color:#666; white-space:pre-wrap;">依頼：{{ $image->description }}</p>@endif
    @if ($image->ai_note)<p style="color:#666; white-space:pre-wrap;">AI：{{ $image->ai_note }}</p>@endif

    @if ($running)
        <meta http-equiv="refresh" content="10">
        <p style="color:#b60;">AI が作っています（<a href="{{ route('ai.generations.show', ['id' => $running->id]) }}">{{ $running->purpose->label() }} #{{ $running->id }}</a>）。この画面は10秒ごとに更新されます。</p>
    @endif

    {{-- 画像 --}}
    <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-start;">
        <div>
            <h2>画像</h2>
            @if ($image->hasFile())
                <img src="{{ route('images.file', ['id' => $image->id]) }}?v={{ $image->updated_at?->timestamp }}" alt="{{ $image->alt }}" style="max-width:640px; width:100%; border:1px solid #ccc;">
            @elseif (filled($image->svg_source))
                <p style="color:#b60;">SVG のプレビューです。まだ PNG にしていません。</p>
                <img id="svg-saved-preview" src="data:image/svg+xml;base64,{{ base64_encode($image->svg_source) }}" alt="{{ $image->alt }}" style="max-width:640px; width:100%; border:1px solid #ccc; background:#fff;">
            @else
                <p style="color:#666;">画像はまだありません。</p>
            @endif
        </div>

        {{-- 別の形式で作った画像と並べて比べる --}}
        @php $others = $image->variants->concat($image->variantOf ? [$image->variantOf] : []); @endphp
        @foreach ($others as $other)
            <div>
                <h2>比べる：{{ $other->kind->label() }}</h2>
                <a href="{{ route('images.show', ['id' => $other->id]) }}">
                    @if ($other->hasFile())
                        <img src="{{ route('images.file', ['id' => $other->id]) }}?v={{ $other->updated_at?->timestamp }}" alt="{{ $other->alt }}" style="max-width:480px; width:100%; border:1px solid #ccc;">
                    @elseif (filled($other->svg_source))
                        <img src="data:image/svg+xml;base64,{{ base64_encode($other->svg_source) }}" alt="{{ $other->alt }}" style="max-width:480px; width:100%; border:1px solid #ccc; background:#fff;">
                    @else
                        <span>{{ $other->title }}（まだ画像がありません）</span>
                    @endif
                </a>
                <p style="color:#666;">使わない方は、その画像の画面から削除してください。</p>
            </div>
        @endforeach
    </div>

    {{-- 図解：SVG の編集と、PNG への変換 --}}
    @if ($isDiagram && filled($image->svg_source) && ! $image->media_id)
        <h2>SVG を PNG にする</h2>
        <p style="color:#666;">
            この画面（ブラウザ）で、SVG を{{ config('blogos.ai.image.png_scale') }}倍の大きさの PNG にして保存します（あなたのPCの日本語のフォントで描くため、文字化けしません）。
            SVG を直したら、もう一度 PNG にしてください。
        </p>
        <p>
            <button type="button" id="svg-to-png">PNG にして保存する</button>
            <span id="png-status" style="color:#666;"></span>
        </p>
        <textarea id="svg-saved" hidden>{{ $image->svg_source }}</textarea>

        <details @if ($errors->has('svg')) open @endif>
            <summary>SVG を直す</summary>
            <form method="POST" action="{{ route('images.svg', ['id' => $image->id]) }}">
                @csrf
                @method('PUT')
                @include('partials.selected-blog-field')
                <div style="display:flex; flex-wrap:wrap; gap:12px;">
                    <textarea name="svg" id="svg-edit" rows="24" style="width:100%; max-width:640px; font-family:monospace; font-size:12px;">{{ old('svg', $image->svg_source) }}</textarea>
                    <div>
                        <p style="color:#666; margin:0;">直している SVG のプレビュー（保存前）</p>
                        <img id="svg-edit-preview" alt="" style="max-width:480px; width:100%; border:1px solid #ccc; background:#fff;">
                    </div>
                </div>
                <p style="color:#666;">スクリプト・外部の読み込み・HTML の埋め込みは、保存するときに取り除きます。</p>
                <button type="submit">SVG を保存する</button>
            </form>
        </details>

        <script>
            (() => {
                const toDataUrl = (svg) => 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
                const edit = document.getElementById('svg-edit');
                const preview = document.getElementById('svg-edit-preview');
                const refresh = () => { preview.src = toDataUrl(edit.value); };
                edit.addEventListener('input', refresh);
                refresh();

                document.getElementById('svg-to-png').addEventListener('click', () => {
                    const status = document.getElementById('png-status');
                    const svg = document.getElementById('svg-saved').value;
                    const scale = {{ (int) config('blogos.ai.image.png_scale') }};
                    const size = @json($svgSize);
                    const image = new Image();
                    status.textContent = '変換しています…';
                    image.onload = () => {
                        const width = (size ? size[0] : image.naturalWidth) || 800;
                        const height = (size ? size[1] : image.naturalHeight) || 600;
                        const canvas = document.createElement('canvas');
                        canvas.width = width * scale;
                        canvas.height = height * scale;
                        const context = canvas.getContext('2d');
                        context.fillStyle = '#ffffff';
                        context.fillRect(0, 0, canvas.width, canvas.height);
                        context.drawImage(image, 0, 0, canvas.width, canvas.height);
                        canvas.toBlob(async (blob) => {
                            if (! blob) { status.textContent = 'PNG にできませんでした。'; return; }
                            const form = new FormData();
                            form.append('file', blob, 'diagram.png');
                            form.append('_token', @json(csrf_token()));
                            form.append('selected_blog_id', @json($selectedBlog?->id));
                            const response = await fetch(@json(route('images.png', ['id' => $image->id])), { method: 'POST', body: form, headers: { 'Accept': 'application/json' } });
                            const result = await response.json().catch(() => ({}));
                            if (response.ok && result.redirect) {
                                location.href = result.redirect;
                            } else {
                                status.textContent = '保存できませんでした：' + (result.message || response.status);
                            }
                        }, 'image/png');
                    };
                    image.onerror = () => { status.textContent = 'SVG を描けませんでした（SVG の形を確認してください）。'; };
                    image.src = toDataUrl(svg);
                });
            })();
        </script>
    @endif

    {{-- 画像の情報 --}}
    <h2>画像の情報</h2>
    <form method="POST" action="{{ route('images.update', ['id' => $image->id]) }}">
        @csrf
        @method('PUT')
        @include('partials.selected-blog-field')
        <table border="1" cellpadding="4" cellspacing="0" style="max-width:900px;">
            <tr><th style="text-align:left;">名前</th><td><input type="text" name="title" value="{{ old('title', $image->title) }}" style="width:100%;" required></td></tr>
            <tr>
                <th style="text-align:left;">種類</th>
                <td>
                    <select name="kind">
                        @foreach (\App\Enums\ImageKind::cases() as $option)
                            <option value="{{ $option->value }}" @selected(old('kind', $image->kind->value) === $option->value)>{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </td>
            </tr>
            <tr><th style="text-align:left; vertical-align:top;">alt（必須）</th><td><textarea name="alt" rows="2" style="width:100%;" placeholder="画像が表示されないとき・読み上げのときの代わりの文章。画像の内容が分かるように">{{ old('alt', $image->alt) }}</textarea></td></tr>
            <tr><th style="text-align:left; vertical-align:top;">キャプション</th><td><textarea name="caption" rows="2" style="width:100%;">{{ old('caption', $image->caption) }}</textarea></td></tr>
            <tr>
                <th style="text-align:left;">ファイル名（必須）</th>
                <td>
                    <input type="text" name="filename" value="{{ old('filename', $image->filename) }}" style="width:300px;" placeholder="js-event-flow">
                    <span style="color:#666;">英小文字・数字・ハイフン（内容が分かる名前）。WordPress に登録するときのファイル名になります。</span>
                </td>
            </tr>
        </table>
        <p><button type="submit">情報を保存する</button></p>
    </form>

    {{-- 確認済みにする・WordPress に登録する --}}
    <h2>確認と登録</h2>
    @if (! $image->isReady())
        @php $missing = $image->missingForReady(); @endphp
        @if ($missing !== [])
            <p style="color:#b60;">確認済みにするには：{{ implode('／', $missing) }}</p>
        @endif
        <form method="POST" action="{{ route('images.ready', ['id' => $image->id]) }}" style="display:inline;">
            @csrf
            @include('partials.selected-blog-field')
            <button type="submit" @disabled($missing !== [])>確認済みにする</button>
        </form>
    @elseif (! $image->media_id)
        <form method="POST" action="{{ route('images.wordpress', ['id' => $image->id]) }}" onsubmit="return confirm('この画像を、{{ $blog->display_name }} の WordPress のメディアに登録しますか？');" style="display:inline;">
            @csrf
            @include('partials.selected-blog-field')
            <button type="submit">WordPress のメディアに登録する</button>
        </form>
        <span style="color:#666;">登録すると、記事で使えるようになります（記事への組み込みは、記事の改修・作成で行います）。</span>
    @else
        <p>WordPress のメディア：<a href="{{ $image->media->source_url }}" target="_blank" rel="noopener noreferrer">{{ $image->media->source_url }}</a>（<a href="{{ route('database.wordpress-records.show', ['table' => 'media', 'id' => $image->media->id]) }}">DB確認</a>）</p>
    @endif

    {{-- 図がまだない図解（記事の「画像の依頼」から作り、自動で図を作れなかった場合など。D-34） --}}
    @if ($image->kind === \App\Enums\ImageKind::Diagram && blank($image->svg_source) && ! $image->hasFile())
        <h2>AIで図を作る</h2>
        <form method="POST" action="{{ route('images.redesign', ['id' => $image->id]) }}">
            @csrf
            @include('partials.selected-blog-field')
            <p style="color:#666;">「依頼の内容」をもとに、SVG の図を作ります。</p>
            @include('materials.partials.method', ['method' => config('blogos.ai.methods.image_design', 'manual'), 'prefix' => 'redesign'])
            <button type="submit">図を作る</button>
        </form>
    @endif

    {{-- 画像モデル・アップロード --}}
    @if (! $image->media_id && $image->kind !== \App\Enums\ImageKind::Diagram)
        <h2>{{ $image->kind === \App\Enums\ImageKind::Screenshot ? '画像を差し替える' : '画像を作る・差し替える' }}</h2>
        @if ($image->kind !== \App\Enums\ImageKind::Screenshot)
            <fieldset style="max-width:900px;">
                <legend>画像モデルで作る（API実行・料金がかかります）</legend>
                @if ($api['configured'])
                    <form method="POST" action="{{ route('images.generate', ['id' => $image->id]) }}" onsubmit="return confirm('画像モデルで作りますか？（料金がかかります）');">
                        @csrf
                        @include('partials.selected-blog-field')
                        <p><label>指示文（英語。画像の中に文字を入れない）<br><textarea name="prompt" rows="4" style="width:100%;">{{ old('prompt', $image->image_prompt) }}</textarea></label></p>
                        <p>
                            <label>品質
                                <select name="quality">
                                    @foreach ($qualities as $quality)
                                        <option value="{{ $quality }}" @selected($quality === $api['quality'])>{{ $quality }}</option>
                                    @endforeach
                                </select>
                            </label>
                            モデル：{{ $api['imageModel'] }}
                            @if ($imageCost !== null)・1枚の最大の目安：約 ${{ number_format($imageCost, 3) }}（{{ $api['quality'] }}）@endif
                        </p>
                        <p style="color:#666;">@include('partials.ai-cost-line')</p>
                        <button type="submit">{{ $image->hasFile() ? '作り直す' : '画像を作る' }}</button>
                    </form>
                @else
                    <p style="color:#b00;">APIキーが設定されていないため、画像モデルは使えません。下の指示文で ChatGPT 等で作った画像をアップロードしてください。</p>
                    @if ($image->image_prompt)<pre style="white-space:pre-wrap;">{{ $image->image_prompt }}</pre>@endif
                @endif
            </fieldset>
        @endif
        <form method="POST" action="{{ route('images.file.replace', ['id' => $image->id]) }}" enctype="multipart/form-data" style="margin-top:8px;">
            @csrf
            @include('partials.selected-blog-field')
            <input type="file" name="file" accept="image/png,image/jpeg,image/webp" data-resize="replace-note" required>
            <button type="submit">アップロードして差し替える</button>
            <span id="replace-note" style="color:#666;"></span>
        </form>
    @endif

    {{-- もう一方の形式でも作る --}}
    @if (in_array($image->kind, [\App\Enums\ImageKind::Diagram, \App\Enums\ImageKind::Illustration], true) && $image->variants->isEmpty() && ! $image->variantOf)
        <h2>もう一方の形式でも作って比べる</h2>
        <form method="POST" action="{{ route('images.variant', ['id' => $image->id]) }}" onsubmit="return confirm('もう一方の形式でも作りますか？');">
            @csrf
            @include('partials.selected-blog-field')
            @if ($isDiagram)
                <p style="color:#666;">同じ内容をイラストで作ります（画像モデル。API実行・料金がかかります）。指示文：{{ $image->image_prompt ?: '（なし）' }}</p>
                <button type="submit" @disabled(! $api['configured'] || blank($image->image_prompt))>イラストでも作る</button>
            @else
                <p style="color:#666;">同じ内容を SVG の図で作ります（文章のモデル）。</p>
                @include('materials.partials.method', ['method' => config('blogos.ai.methods.image_design', 'manual'), 'prefix' => 'variant'])
                <button type="submit">SVG の図でも作る</button>
            @endif
        </form>
    @endif

    {{-- 記録 --}}
    @if ($image->generations->isNotEmpty())
        <h2>AI の実行記録</h2>
        <ul>
            @foreach ($image->generations as $generation)
                <li>
                    <a href="{{ route('ai.generations.show', ['id' => $generation->id]) }}">#{{ $generation->id }} {{ $generation->purpose->label() }}</a>
                    （{{ $generation->status->label() }}{{ $generation->estimated_cost !== null ? '・$' . number_format($generation->estimated_cost, 4) : '' }}・{{ \App\Support\DisplayTime::format($generation->created_at) }}）
                    @if ($generation->error)<span style="color:#b00;">{{ $generation->error }}</span>@endif
                </li>
            @endforeach
        </ul>
    @endif

    @if (! $image->media_id)
        <form method="POST" action="{{ route('images.destroy', ['id' => $image->id]) }}" onsubmit="return confirm('この画像を削除しますか？');" style="margin-top:16px;">
            @csrf
            @method('DELETE')
            @include('partials.selected-blog-field')
            <button type="submit">この画像を削除する</button>
        </form>
    @endif

    @include('images.partials.resize-script')

@endsection
