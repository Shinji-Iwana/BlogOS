{{--
    教材の登録・編集（D-30）。編集では、AIでの調査と、この教材を使っている記事も表示する
--}}

@extends('layouts.app')

@section('content')

    @php
        $isNew = ! $material->exists;
        $kindValue = old('kind', $material->kind?->value);
        $lines = fn ($value) => implode("\n", (array) $value);
        $selectedCategories = old('category_ids', $isNew ? [] : $material->categories->pluck('id')->all());
    @endphp

    <h1>{{ $isNew ? '教材を登録する' : "教材：{$material->name}" }}</h1>

    <p>
        <a href="{{ route('materials.index') }}">教材の一覧に戻る</a>
        @unless ($isNew)
            ・<a href="{{ route('materials.suggestions.index') }}">教材の案の確認</a>
        @endunless
    </p>

    @include('partials.flash')

    @unless ($isNew)
        @if ($material->successors->isNotEmpty())
            <p class="text-warn">新しい版が登録されています：
                @foreach ($material->successors as $next)<a href="{{ route('materials.edit', ['id' => $next->id]) }}">{{ $next->name }}</a> @endforeach
            </p>
        @endif

        {{-- AIでの調査 --}}
        <fieldset style="max-width:900px;">
            <legend>AIで調べる（記事に合う教材を選ぶための情報・新しい版の確認）</legend>
            <p class="text-muted">
                結果は案として保存し、「教材の案の確認」で、写す項目を選んでから教材に写します（教材はすぐには変わりません）。
                最後に調べた日：{{ $material->researched_at ? \App\Support\DisplayTime::format($material->researched_at) : '未調査' }}。
                @if ($material->kind === \App\Enums\MaterialKind::Book)
                    書籍は、楽天ブックスAPIの情報も渡します{{ $rakuten ? '' : '（楽天ウェブサービスのアプリIDが設定されていないため、今は使いません）' }}。
                @endif
            </p>
            <form method="POST" action="{{ route('materials.research', ['id' => $material->id]) }}">
                @csrf
                @include('partials.selected-blog-field')
                @include('materials.partials.method', ['method' => config('blogos.ai.methods.material_research', 'api'), 'webSearch' => true, 'prefix' => 'research', 'api' => $api + ['defaults' => config('blogos.ai.api.defaults.material_research')]])
                <p>
                    <label>販売サイト等のページの文章（任意。貼り付けると、それも根拠にします）<br>
                        <textarea name="pasted" rows="4" style="width:100%; max-width:860px;" placeholder="楽天・Amazon・出版社・Udemyの講座ページなどの説明の部分を、そのまま貼り付けてください（形式はサイトごとに違ってかまいません）">{{ old('pasted') }}</textarea>
                    </label>
                </p>
                <p><button type="submit">AIで調べる</button></p>
            </form>
        </fieldset>
    @endunless

    <form method="POST" action="{{ $isNew ? route('materials.store') : route('materials.update', ['id' => $material->id]) }}">
        @csrf
        @unless ($isNew) @method('PUT') @endunless
        @include('partials.selected-blog-field')

        <h2>基本</h2>
        <table class="data" style="max-width:900px;">
            <tr>
                <th style="text-align:left;">種類</th>
                <td>
                    @foreach (\App\Enums\MaterialKind::cases() as $option)
                        <label><input type="radio" name="kind" value="{{ $option->value }}" @checked($kindValue === $option->value) onchange="document.querySelectorAll('[data-kind]').forEach(e => e.style.display = e.dataset.kind.split(',').includes(this.value) ? '' : 'none')"> {{ $option->label() }}</label>
                    @endforeach
                </td>
            </tr>
            <tr>
                <th style="text-align:left;">状態</th>
                <td>
                    <select name="status">
                        @foreach (\App\Enums\MaterialStatus::cases() as $option)
                            <option value="{{ $option->value }}" @selected(old('status', $material->status?->value) === $option->value)>{{ $option->label() }}</option>
                        @endforeach
                    </select>
                    <span class="text-muted">「使わない」にすると、この教材を使っている記事が見直しの対象になります。</span>
                </td>
            </tr>
            <tr><th style="text-align:left;">名前</th><td><input type="text" name="name" value="{{ old('name', $material->name) }}" style="width:100%;" required></td></tr>
            <tr data-kind="book" @style(['display:none' => $kindValue !== 'book'])>
                <th style="text-align:left;">Amazonのリンク<br>（もしも）</th>
                <td><textarea name="amazon_url" rows="2" style="width:100%;" placeholder="https://af.moshimo.com/af/c/click?... または <a href=&quot;...&quot;> を含むHTML">{{ old('amazon_url', $material->amazon_url) }}</textarea></td>
            </tr>
            <tr data-kind="book" @style(['display:none' => $kindValue !== 'book'])>
                <th style="text-align:left;">楽天のリンク<br>（もしも）</th>
                <td><textarea name="rakuten_url" rows="2" style="width:100%;">{{ old('rakuten_url', $material->rakuten_url) }}</textarea></td>
            </tr>
            <tr data-kind="udemy,school,question_bank" @style(['display:none' => $kindValue === 'book'])>
                <th style="text-align:left;">アフィリエイトのリンク</th>
                <td><textarea name="affiliate_url" rows="2" style="width:100%;" placeholder="Udemyの紹介リンク、もしものリンクなど（URL または <a href=&quot;...&quot;> を含むHTML）">{{ old('affiliate_url', $material->affiliate_url) }}</textarea></td>
            </tr>
            <tr>
                <th style="text-align:left;">同じ教材の別のリンク</th>
                <td>
                    <textarea name="extra_urls" rows="2" style="width:100%;">{{ old('extra_urls', $lines($material->extra_urls)) }}</textarea><br>
                    <span class="text-muted">記事で使われている古いリンクなど（1行に1つ）。記事との照合だけに使います。</span>
                </td>
            </tr>
            <tr data-kind="book" @style(['display:none' => $kindValue !== 'book'])>
                <th style="text-align:left;">Amazonの商品ページ</th>
                <td>
                    <input type="text" name="amazon_product_url" value="{{ old('amazon_product_url', $material->amazon_product_url) }}" style="width:100%;" placeholder="https://www.amazon.co.jp/dp/…"><br>
                    <span class="text-muted">空なら、Amazonのリンク（もしも）の遷移先から補います。</span>
                </td>
            </tr>
            <tr data-kind="book" @style(['display:none' => $kindValue !== 'book'])>
                <th style="text-align:left;">楽天の商品ページ</th>
                <td>
                    <input type="text" name="rakuten_product_url" value="{{ old('rakuten_product_url', $material->rakuten_product_url) }}" style="width:100%;" placeholder="https://books.rakuten.co.jp/rb/…/"><br>
                    <span class="text-muted">空なら、楽天のリンク（もしも）の遷移先から補います。</span>
                </td>
            </tr>
            <tr>
                <th style="text-align:left;">
                    <span data-kind="udemy,school,question_bank" @style(['display:none' => $kindValue === 'book'])>商品ページ</span>
                    <span data-kind="book" @style(['display:none' => $kindValue !== 'book'])>出版社などのページ</span>
                </th>
                <td>
                    <input type="text" name="product_url" value="{{ old('product_url', $material->product_url) }}" style="width:100%;"><br>
                    <span class="text-muted">アフィリエイトではないURL（Udemyの講座ページ・スクールの公式サイト・書籍の出版社のページ。書籍は任意）。AIの調査と定期チェックに使います。</span>
                </td>
            </tr>
        </table>

        <h2>出版の情報</h2>
        <table class="data" style="max-width:900px;">
            <tr><th style="text-align:left;">著者・講師・運営</th><td><input type="text" name="creator" value="{{ old('creator', $material->creator) }}" style="width:100%;"></td></tr>
            <tr><th style="text-align:left;">出版社・提供元</th><td><input type="text" name="publisher" value="{{ old('publisher', $material->publisher) }}" style="width:100%;"></td></tr>
            <tr><th style="text-align:left;">版</th><td><input type="text" name="edition" value="{{ old('edition', $material->edition) }}" style="width:160px;" placeholder="第2版"></td></tr>
            <tr><th style="text-align:left;">出版日（Udemyは最終更新日）</th><td><input type="date" name="published_on" value="{{ old('published_on', $material->published_on?->format('Y-m-d')) }}"></td></tr>
            <tr data-kind="book" @style(['display:none' => $kindValue !== 'book'])>
                <th style="text-align:left;">ISBN</th>
                <td><input type="text" name="isbn" value="{{ old('isbn', $material->isbn) }}" style="width:160px;"> <span class="text-muted">空なら、AmazonのリンクのASINから補います。{{ $material->asin ? "（ASIN：{$material->asin}）" : '' }}</span></td>
            </tr>
            <tr>
                <th style="text-align:left;">前の版</th>
                <td>
                    <select name="previous_material_id">
                        <option value="">（なし）</option>
                        @foreach ($previousOptions as $option)
                            <option value="{{ $option->id }}" @selected((int) old('previous_material_id', $material->previous_material_id) === $option->id)>{{ $option->kind->label() }}：{{ $option->name }}</option>
                        @endforeach
                    </select>
                    <span class="text-muted">新しい版を登録すると、前の版を使っている記事が見直しの対象になります。</span>
                </td>
            </tr>
        </table>

        <h2>記事に合う教材を選ぶための情報</h2>
        <p class="text-muted">AIで調べた結果を「教材の案の確認」から写せます。リストは1行に1つです。</p>
        <table class="data" style="max-width:900px;">
            <tr>
                <th style="text-align:left; vertical-align:top;">カテゴリ</th>
                <td>
                    <select name="category_ids[]" multiple size="8" style="min-width:300px;">
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(in_array($category->id, array_map('intval', (array) $selectedCategories), true))>{{ str_repeat('　', (int) ($category->depth ?? 0)) }}{{ $category->name }}</option>
                        @endforeach
                    </select><br>
                    <span class="text-muted">Ctrlキーを押しながらクリックで、複数選べます。親のカテゴリを選ぶと、子のカテゴリの記事にも合う候補になります。</span>
                </td>
            </tr>
            <tr><th style="text-align:left; vertical-align:top;">分野の語句</th><td><textarea name="topics" rows="3" style="width:100%;" placeholder="JavaScript&#10;DOM&#10;イベント">{{ old('topics', $lines($material->topics)) }}</textarea><br><span class="text-muted">記事のタイトル・キーワードに含まれていれば、候補にします。</span></td></tr>
            <tr><th style="text-align:left; vertical-align:top;">対象のバージョン</th><td><textarea name="target_versions" rows="2" style="width:100%;" placeholder="JavaScript ES2022">{{ old('target_versions', $lines($material->target_versions)) }}</textarea></td></tr>
            <tr>
                <th style="text-align:left;">対象のレベル</th>
                <td>
                    @foreach (\App\Models\Material::LEVELS as $value => $label)
                        <label><input type="checkbox" name="levels[]" value="{{ $value }}" @checked(in_array($value, (array) old('levels', $material->levels ?? []), true))> {{ $label }}</label>
                    @endforeach
                </td>
            </tr>
            <tr>
                <th style="text-align:left; vertical-align:top;">向いている場面</th>
                <td>
                    @foreach (\App\Models\Material::SCENES as $value => $label)
                        <label style="display:inline-block; margin-right:8px;"><input type="checkbox" name="scenes[]" value="{{ $value }}" @checked(in_array($value, (array) old('scenes', $material->scenes ?? []), true))> {{ $label }}</label>
                    @endforeach
                </td>
            </tr>
            <tr><th style="text-align:left; vertical-align:top;">学べる内容</th><td><textarea name="summary" rows="3" style="width:100%;">{{ old('summary', $material->summary) }}</textarea></td></tr>
            <tr><th style="text-align:left; vertical-align:top;">向いている人</th><td><textarea name="target_readers" rows="2" style="width:100%;">{{ old('target_readers', $material->target_readers) }}</textarea></td></tr>
            <tr><th style="text-align:left; vertical-align:top;">向いていない人</th><td><textarea name="not_for" rows="2" style="width:100%;">{{ old('not_for', $material->not_for) }}</textarea></td></tr>
            <tr><th style="text-align:left; vertical-align:top;">メリット</th><td><textarea name="merits" rows="3" style="width:100%;">{{ old('merits', $lines($material->merits)) }}</textarea></td></tr>
            <tr><th style="text-align:left; vertical-align:top;">注意点</th><td><textarea name="cautions" rows="3" style="width:100%;">{{ old('cautions', $lines($material->cautions)) }}</textarea></td></tr>
            <tr data-kind="school,question_bank" @style(['display:none' => ! in_array($kindValue, ['school', 'question_bank'], true)])>
                <th style="text-align:left;">費用の目安・学習期間</th>
                <td>
                    <input type="text" name="cost_note" value="{{ old('cost_note', $material->cost_note) }}" style="width:260px;" placeholder="費用の目安">
                    <input type="text" name="duration_note" value="{{ old('duration_note', $material->duration_note) }}" style="width:200px;" placeholder="学習期間">
                    確認した日 <input type="date" name="cost_checked_on" value="{{ old('cost_checked_on', $material->cost_checked_on?->format('Y-m-d')) }}">
                </td>
            </tr>
            @if ($material->sources)
                <tr><th style="text-align:left; vertical-align:top;">調査の根拠</th><td>@foreach ($material->sources as $url)<a href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $url }}</a><br>@endforeach</td></tr>
            @endif
            <tr><th style="text-align:left; vertical-align:top;">メモ</th><td><textarea name="memo" rows="2" style="width:100%;">{{ old('memo', $material->memo) }}</textarea></td></tr>
        </table>

        <p><button type="submit">{{ $isNew ? '登録する' : '更新する' }}</button></p>
    </form>

    @unless ($isNew)
        <h2>この教材を使っている記事：{{ $articles->count() }}件</h2>
        @if ($articles->isNotEmpty())
            <ul>
                @foreach ($articles as $record)
                    @php $article = $record->article(); @endphp
                    @if ($article)
                        <li>
                            <a href="{{ route('articles.show', ['type' => $record->post_id ? 'posts' : 'pages', 'id' => $article->id]) }}">{{ $article->title_raw }}</a>
                            （{{ $record->source->label() }}）
                            @if ($reason = $record->reviewReason())<span class="text-warn">見直し：{{ $reason }}</span>@endif
                        </li>
                    @endif
                @endforeach
            </ul>
        @endif

        <form method="POST" action="{{ route('materials.destroy', ['id' => $material->id]) }}" onsubmit="return confirm('この教材を削除しますか？');">
            @csrf
            @method('DELETE')
            @include('partials.selected-blog-field')
            <button type="submit">この教材を削除する</button>
            <span class="text-muted">（記事で使っている教材は削除できません）</span>
        </form>
    @endunless

@endsection
