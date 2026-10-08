{{--
    WordPress API情報の画面の「詳細」の表（D-72-10）。入れ子の項目は、まとまりの名前の行を押すと、中の項目の表が開く（この部品をくり返し使う）。
    受け取る値：$fields（項目 => 値）・$resource（応答の種類。説明を探すため）・$path（任意。上のまとまりの道筋。「.」でつなぐ）
--}}
<table class="data">
    <tbody>
        @foreach ($fields as $key => $value)
            @php
                $fieldPath = ($path ?? '') === '' ? (string) $key : $path . '.' . $key;
                $shown = \App\Support\WordPressApiFields::leaf($fieldPath, $value);
                // 説明の「?」は、入れ子でない一番上の項目だけ（入れ子のまとまりと、その中の項目には付けない。利用者の判断）
                $fieldNote = ($shown !== null && ($path ?? '') === '') ? \App\Support\WordPressApiFields::describe($resource, $fieldPath) : null;
            @endphp
            @if ($shown === null)
                {{-- 入れ子のまとまり：名前の行を押すと、中の項目の表が開く --}}
                <tr>
                    <td colspan="2" class="api-field-group">
                        <details>
                            <summary><strong>{{ $key }}</strong></summary>
                            <div class="api-field-children">
                                @include('wordpress-api.fields', ['fields' => $value, 'resource' => $resource, 'path' => $fieldPath])
                            </div>
                        </details>
                    </td>
                </tr>
            @else
                <tr>
                    {{-- 項目名と「?」は折り返さない（列の幅は、項目名に合わせる） --}}
                    <th style="text-align:left; vertical-align:top; white-space:nowrap; width:1%;">
                        {{ $key }}
                        @if ($fieldNote)
                            @include('partials.tip', ['tip' => $fieldNote])
                        @endif
                    </th>
                    <td>
                        @if (mb_strlen($shown) > 300)
                            <details>
                                <summary>{{ \Illuminate\Support\Str::limit($shown, 100) }}</summary>
                                <pre style="white-space:pre-wrap; word-break:break-all;">{{ $shown }}</pre>
                            </details>
                        @else
                            {{ $shown }}
                            @if ($key === 'slug' && \App\Support\Slug::display($shown) !== $shown)
                                <br><span class="text-muted">（読める形：{{ \App\Support\Slug::display($shown) }}）</span>
                            @endif
                        @endif
                    </td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>
