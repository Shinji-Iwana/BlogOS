{{--
    アフィリエイト提携先の登録ポップアップ（D-63-20。以前は画面「アフィリエイトのプログラム」の「プログラムを登録する」の欄。D-33-08）

    メニューの「設定 → アフィリエイト提携先を登録」を押した場合に表示する（data-modal-open="affiliate-program-modal"。layouts/header から読み込む）。
    登録の後は、開いていた画面に戻り、このポップアップを開いて結果を出す（session の affiliate_program_modal）。
    入力の誤りで戻ったときも、ポップアップを開いたままにして誤りを出す（_form）。
--}}

@php
    $programFailed = old('_form') === 'affiliate-program';
    $programOpen = $programFailed || session('affiliate_program_modal');
@endphp

<div
    id="affiliate-program-modal"
    class="blog-switch-modal site-modal affiliate-program-modal"
    @if ($programOpen) data-modal-autoopen @endif
>

    <div
        class="blog-switch-dialog"
    >

        <h2>
            アフィリエイト提携先を登録
            @include('partials.tip', ['tip' => "選択中のブログに、まだ記事で使っていないプログラム（申請中・否認を含む）を登録します。\n状態が「申請中・否認・提携終了」のプログラムのリンクは、記事で紹介に使いません（AIの教材の候補から外します）。\n登録したプログラムは、画面「アフィリエイトのプログラム」で確認・変更できます。"])
        </h2>

        @if ($programOpen)
            @include('partials.flash')
        @endif

        @if (! $selectedBlog)
            <p>ブログを選んでください（画面上部のブログ切り替え）。</p>

            <div
                class="blog-switch-actions"
            >
                <button type="button" class="btn-secondary" data-modal-close>閉じる</button>
            </div>
        @else
            <form
                method="POST"
                action="{{ route('materials.programs.store') }}"
            >

                @csrf
                @include('partials.selected-blog-field')
                <input type="hidden" name="_form" value="affiliate-program">

                {{-- 項目名（左の列）と入力欄（右の列）を表に並べ、入力欄の始まりをそろえる（入力欄の大きさは変えない。色・枠なし） --}}
                <table class="form-grid">
                    <tbody>
                        <tr>
                            <th><label for="program-asp">ASP</label></th>
                            <td>
                                <select name="asp" id="program-asp">
                                    <option value="moshimo" @selected($programFailed && old('asp') === 'moshimo')>もしもアフィリエイト</option>
                                    <option value="a8" @selected($programFailed && old('asp') === 'a8')>A8.net</option>
                                    <option value="other" @selected($programFailed && old('asp') === 'other')>その他</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="program-external-id">広告ID</label> @include('partials.tip', ['tip' => 'もしもアフィリエイトの広告IDは、リンクの p_id= の数字です。'])</th>
                            <td><input type="text" name="external_id" id="program-external-id" value="{{ $programFailed ? old('external_id') : '' }}" style="width:140px;" required></td>
                        </tr>
                        <tr>
                            <th><label for="program-name">名前</label></th>
                            <td><input type="text" name="name" id="program-name" value="{{ $programFailed ? old('name') : '' }}" style="width:100%; max-width:320px;" required></td>
                        </tr>
                        <tr>
                            <th><label for="program-kind">教材の種類</label></th>
                            <td>
                                <select name="material_kind" id="program-kind">
                                    <option value="">（決めない）</option>
                                    @foreach (\App\Enums\MaterialKind::cases() as $option)
                                        <option value="{{ $option->value }}" @selected($programFailed && old('material_kind') === $option->value)>{{ $option->label() }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="program-status">状態</label></th>
                            <td>
                                <select name="status" id="program-status">
                                    @foreach (\App\Enums\AffiliateProgramStatus::cases() as $option)
                                        <option value="{{ $option->value }}" @selected(($programFailed ? old('status') : 'active') === $option->value)>{{ $option->label() }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="program-memo">メモ</label></th>
                            <td><input type="text" name="memo" id="program-memo" value="{{ $programFailed ? old('memo') : '' }}" style="width:100%; max-width:320px;"></td>
                        </tr>
                    </tbody>
                </table>

                <div
                    class="blog-switch-actions"
                >
                    <button type="submit">登録する</button>
                    <button type="button" class="btn-secondary" data-modal-close>キャンセル</button>
                </div>

            </form>
        @endif

    </div>

</div>
