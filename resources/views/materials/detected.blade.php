{{--
    既存の記事にある教材のリンクから、教材を登録する（D-30）
--}}

@extends('layouts.app')

@section('content')

    <h1>既存の記事のリンクから教材を登録する（{{ $blog->display_name }}）</h1>

    <p><a href="{{ route('materials.index') }}">教材の一覧に戻る</a></p>

    @include('partials.flash')

    <p style="color:#666;">
        記事の本文にある、アフィリエイトのサービス（もしも・楽天アフィリエイト・Udemyの紹介リンク・Amazonの紹介リンク）を経由するリンクを、教材ごとにまとめました。
        別のサイトへの普通のリンクは対象にしていません。同じ見出しの下にある Amazon と楽天のリンクは、同じ書籍としてまとめています。
        名前・種類を確かめて（必要なら直して）、登録する教材をチェックしてください。登録した後に、各教材の「AIで調べる」で、記事に合う教材を選ぶための情報を調べます。
    </p>

    @php $unregistered = collect($detected)->whereNull('material')->count(); @endphp

    @if ($detected === [])
        <p>記事の本文に、教材のリンクは見つかりませんでした。</p>
    @else
        <form method="POST" action="{{ route('materials.detected.store') }}">
            @csrf
            @include('partials.selected-blog-field')

            <p>
                未登録：{{ $unregistered }}件／検出：{{ count($detected) }}件
                <button type="submit" @disabled($unregistered === 0)>チェックした教材を登録する</button>
            </p>

            <div style="overflow-x:auto;">
                <table border="1" cellpadding="4" cellspacing="0">
                    <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.detected-check:not(:disabled)').forEach(c => c.checked = this.checked)" checked></th><th>種類</th><th>名前</th><th>リンク</th><th>使っている記事</th></tr></thead>
                    <tbody>
                        @foreach ($detected as $index => $item)
                            <tr @style(['color:#888' => $item['material'] !== null])>
                                <td><input type="checkbox" class="detected-check" name="selected[]" value="{{ $index }}" @checked($item['material'] === null) @disabled($item['material'] !== null)></td>
                                <td>
                                    <select name="items[{{ $index }}][kind]" @disabled($item['material'] !== null)>
                                        @foreach (\App\Enums\MaterialKind::cases() as $option)
                                            <option value="{{ $option->value }}" @selected($item['kind'] === $option)>{{ $option->label() }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    @if ($item['material'])
                                        登録済み：<a href="{{ route('materials.edit', ['id' => $item['material']->id]) }}">{{ $item['material']->name }}</a>
                                    @else
                                        <input type="text" name="items[{{ $index }}][name]" value="{{ $item['name'] }}" style="width:320px;">
                                        @if (count($item['names']) > 1)
                                            <br><span style="color:#666;">記事での表記：{{ implode('／', array_keys($item['names'])) }}</span>
                                        @endif
                                    @endif
                                </td>
                                <td style="max-width:360px; word-break:break-all; font-size:12px;">
                                    @foreach (['Amazon' => $item['amazon_url'], '楽天' => $item['rakuten_url'], 'リンク' => $item['affiliate_url']] as $label => $url)
                                        @if ($url)<div>{{ $label }}：{{ \App\Support\AffiliateLink::innerUrl($url) !== $url ? \App\Support\AffiliateLink::innerUrl($url) . '（もしも経由）' : $url }}</div>@endif
                                    @endforeach
                                    @if ($item['extra_urls'])<div style="color:#666;">ほかに {{ count($item['extra_urls']) }}件の別のリンク</div>@endif
                                </td>
                                <td>
                                    <details>
                                        <summary>{{ count($item['articles']) }}件</summary>
                                        <ul style="margin:0; padding-left:16px;">
                                            @foreach (array_slice($item['articles'], 0, 30) as $article)
                                                <li><a href="{{ route('articles.show', ['type' => $article instanceof \App\Models\Post ? 'posts' : 'pages', 'id' => $article->id]) }}">{{ $article->title_raw }}</a></li>
                                            @endforeach
                                            @if (count($item['articles']) > 30)<li>ほか {{ count($item['articles']) - 30 }}件</li>@endif
                                        </ul>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </form>
    @endif

@endsection
