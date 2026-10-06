{{--
    Google連携のポップアップ（D-63-19。以前は画面「Google連携」の「Googleアカウント」「このブログの対応先」の欄。D-21-01）

    メニューの「設定 → Google → アカウント」「Google Analytics 4」「Search Console」「AdSense」を押した場合に表示する
    （data-modal-open="google-account-modal"・GoogleService::modalId()。layouts/header から読み込む）。
    保存・解除・接続の後は、開いていた画面に戻り、同じポップアップを開いて結果を出す（session の google_modal）。
    入力の誤りで戻ったときも、ポップアップを開いたままにして誤りを出す（_form）。
    対応先の候補は、Google API を呼ぶため、「候補を読み込む」を押したときだけ読む（google.candidates。画面は移らない）。
    トークンは画面に出さない。
--}}

@php
    $googleAccountsRepo = app(\App\Repositories\GoogleAccountRepository::class);
    $googleAccounts = $googleAccountsRepo->all();
    $googleConfigured = app(\App\Clients\Google\GoogleOAuthClient::class)->isConfigured();
    $googleProperties = $selectedBlog ? $googleAccountsRepo->propertiesForBlog($selectedBlog->id) : collect();
    $googleLatestRuns = $selectedBlog ? app(\App\Repositories\GoogleFetchRunRepository::class)->latestForBlog($selectedBlog->id) : collect();
    $googleBlogHost = $selectedBlog ? parse_url($selectedBlog->home, PHP_URL_HOST) : null;
    // 結果を出すポップアップ（保存・解除・接続の後は google_modal、入力の誤りは _form）
    $googleOpen = session('google_modal') ?? (str_starts_with((string) old('_form'), 'google-') ? old('_form') : null);
@endphp

{{-- アカウント --}}
<div
    id="google-account-modal"
    class="blog-switch-modal site-modal google-modal"
    @if ($googleOpen === 'google-account-modal') data-modal-autoopen @endif
>

    <div
        class="blog-switch-dialog"
    >

        <h2>
            Googleアカウント
            @include('partials.tip', ['tip' => "GA4・Search Console・AdSense のデータを取得するための、Googleアカウントの接続です。\n接続を解除すると、そのアカウントを使う対応先の設定も解除します（取得済みのデータは残ります）。"])
        </h2>

        @if ($googleOpen === 'google-account-modal')
            @include('partials.flash')
        @endif

        @if (! $googleConfigured)
            <p class="text-error">GoogleのOAuthクライアントが設定されていません（.env の GOOGLE_OAUTH_CLIENT_ID・GOOGLE_OAUTH_CLIENT_SECRET・GOOGLE_OAUTH_REDIRECT_URI）。</p>
        @endif

        <div style="overflow-x:auto;">
            <table class="data">
                <thead><tr><th>アカウント</th><th>状態</th><th>最後の更新</th><th></th></tr></thead>
                <tbody>
                    @forelse ($googleAccounts as $account)
                        <tr>
                            <td>{{ $account->email }}</td>
                            <td>
                                @if ($account->last_error)
                                    <strong class="text-error">{{ $account->last_error }}</strong>
                                @else
                                    接続済み
                                @endif
                            </td>
                            <td>{{ \App\Support\DisplayTime::format($account->last_refreshed_at) }}</td>
                            <td>
                                <form method="POST" action="{{ route('google.accounts.destroy', ['id' => $account->id]) }}" style="display:inline;" onsubmit="return confirm('接続を解除しますか？このアカウントを使う対応先の設定も解除されます（取得済みのデータは残ります）。');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn-danger" type="submit">接続を解除</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">接続されていません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($googleConfigured)
            <p><a href="{{ route('google.oauth.redirect') }}">Googleアカウントを接続する（または接続し直す）</a></p>
        @endif

        <div
            class="blog-switch-actions"
        >
            <button
                type="button"
                class="btn-secondary"
                data-modal-close
            >
                閉じる
            </button>
        </div>

    </div>

</div>

{{-- 対応先（サービスごと） --}}
@foreach (\App\Enums\GoogleService::cases() as $service)
    @php
        $googleModalId = $service->modalId();
        $property = $googleProperties[$service->value] ?? null;
        $googleRun = $googleLatestRuns[$service->value] ?? null;
        $googleFailed = old('_form') === $googleModalId;
    @endphp

    <div
        id="{{ $googleModalId }}"
        class="blog-switch-modal site-modal google-modal"
        @if ($googleOpen === $googleModalId) data-modal-autoopen @endif
    >

        <div
            class="blog-switch-dialog"
        >

            <h2>
                {{ $service->label() }}
                @include('partials.tip', ['tip' => "選択中のブログの、{$service->label()}の対応先です。\n「候補を読み込む」を押すと、選んだアカウントで見られる対応先を候補に出します（Google API を呼びます）。候補を読み込まずに、値を直接入力することもできます。\n解除しても、取得済みのデータは残ります。"])
            </h2>

            @if ($googleOpen === $googleModalId)
                @include('partials.flash')
            @endif

            @if (! $selectedBlog)
                <p>ブログを選んでください（画面上部のブログ切り替え）。</p>
            @else
                <p>
                    現在：
                    @if ($property)
                        <strong>{{ $property->display_name ?: $property->resource_name }}</strong>（{{ $property->resource_name }}{{ $property->adsense_domain ? '、ドメイン ' . $property->adsense_domain : '' }}、{{ $property->account?->email }}）
                    @else
                        未設定
                    @endif
                </p>

                @if ($googleAccounts->isEmpty())
                    <p>Googleアカウントが接続されていません。メニューの「設定 → Google → アカウント」で接続してください。</p>
                @else
                    <form method="POST" action="{{ route('google.properties.update', ['service' => $service->value]) }}" data-google-property-form>
                        @csrf
                        @method('PUT')
                        @include('partials.selected-blog-field')
                        <input type="hidden" name="_form" value="{{ $googleModalId }}">

                        <p>
                            <label>アカウント
                                <select name="google_account_id">
                                    @foreach ($googleAccounts as $account)
                                        <option value="{{ $account->id }}" @selected((int) ($googleFailed ? old('google_account_id') : $property?->google_account_id) === $account->id)>{{ $account->email }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="button" class="btn-secondary" data-google-candidates="{{ route('google.candidates', ['service' => $service->value]) }}">候補を読み込む</button>
                        </p>
                        <p data-google-candidates-result hidden>
                            <label>候補
                                <select data-google-candidate-select>
                                    <option value="">（選ぶ）</option>
                                </select>
                            </label>
                        </p>
                        <p class="text-error" data-google-candidates-error hidden></p>

                        <p>
                            <label>対応先
                                <input type="text" name="resource_name" value="{{ $googleFailed ? old('resource_name') : $property?->resource_name }}" style="width:260px; max-width:100%;"
                                       placeholder="{{ ['ga4' => 'properties/123456789', 'search_console' => 'sc-domain:example.com', 'adsense' => 'accounts/pub-0000000000000000'][$service->value] }}">
                            </label>
                        </p>
                        <input type="hidden" name="display_name" value="{{ $googleFailed ? old('display_name') : $property?->display_name }}">

                        @if ($service === \App\Enums\GoogleService::Adsense)
                            <p>
                                <label>集計するドメイン
                                    <input type="text" name="adsense_domain" value="{{ $googleFailed ? old('adsense_domain') : ($property?->adsense_domain ?? $googleBlogHost) }}" style="width:200px; max-width:100%;">
                                </label>
                            </p>
                        @endif

                        @if ($googleRun)
                            <p class="text-muted">
                                最後の取得：{{ \App\Support\DisplayTime::format($googleRun->started_at) }}・{{ $googleRun->status->label() }}・{{ $googleRun->date_from?->toDateString() }}〜{{ $googleRun->date_to?->toDateString() }}・{{ $googleRun->row_count }}行
                                @if ($googleRun->message)・{{ $googleRun->message }}@endif
                            </p>
                        @endif

                        <div
                            class="blog-switch-actions"
                        >
                            <button type="submit">保存</button>
                            @if ($property)
                                <button class="btn-danger" type="submit" name="remove" value="1" onclick="return confirm('対応先を解除しますか？（取得済みのデータは残ります）');">解除</button>
                            @endif
                            <button type="button" class="btn-secondary" data-modal-close>キャンセル</button>
                        </div>
                    </form>
                @endif
            @endif

            @if (! $selectedBlog || $googleAccounts->isEmpty())
                <div
                    class="blog-switch-actions"
                >
                    <button type="button" class="btn-secondary" data-modal-close>閉じる</button>
                </div>
            @endif

        </div>

    </div>
@endforeach

<script>
    // 「候補を読み込む」：選んだアカウントで見られる対応先を読み、候補に出す。候補を選ぶと、対応先などの欄に入れる
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-google-property-form]').forEach(function (form) {
            const button = form.querySelector('[data-google-candidates]');
            const result = form.querySelector('[data-google-candidates-result]');
            const select = form.querySelector('[data-google-candidate-select]');
            const error = form.querySelector('[data-google-candidates-error]');

            button.addEventListener('click', async function () {
                const label = button.textContent;
                button.disabled = true;
                button.textContent = '読み込み中…';
                error.hidden = true;
                try {
                    const url = button.dataset.googleCandidates + '?account=' + encodeURIComponent(form.google_account_id.value);
                    const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    const data = await response.json();
                    select.length = 1;
                    (data.items || []).forEach(function (item) {
                        const option = new Option(item.display_name || item.resource_name, item.resource_name);
                        option.dataset.name = item.display_name || '';
                        option.dataset.domain = item.domain || '';
                        select.add(option);
                    });
                    result.hidden = (data.items || []).length === 0;
                    if (data.error || (data.items || []).length === 0) {
                        error.textContent = data.error
                            ? '候補を取得できませんでした：' + data.error + '（GCPのプロジェクトで、このAPIが有効になっているか確認してください）'
                            : '候補がありません。';
                        error.hidden = false;
                    }
                } catch (e) {
                    error.textContent = '候補を読み込めませんでした。';
                    error.hidden = false;
                } finally {
                    button.disabled = false;
                    button.textContent = label;
                }
            });

            select.addEventListener('change', function () {
                const option = select.selectedOptions[0];
                if (!option || option.value === '') {
                    return;
                }
                form.resource_name.value = option.value;
                form.display_name.value = option.dataset.name || '';
                if (form.adsense_domain && option.dataset.domain) {
                    form.adsense_domain.value = option.dataset.domain;
                }
            });
        });
    });
</script>
