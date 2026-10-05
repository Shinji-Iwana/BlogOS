{{--
    音声操作ポップアップ（D-61。以前は画面「AIの設定」の「音声の操作」の欄。D-58）

    メニューの「AI → 音声操作」を押した場合に表示する（data-modal-open="voice-settings-modal"。layouts/header から読み込む）。
    テーマ切替・ブログ切替のポップアップと同じ形。BlogOS 全体の設定（system_settings の voice.*）。
    保存した後は、開いていた画面に戻る。入力の誤りで戻ったときは、ポップアップを開いたままにして誤りを出す。
--}}

@php
    $voiceSettings = app(\App\Services\Voice\VoiceSettings::class);
    // 入力の誤りで戻ったとき（このポップアップから送った場合だけ）
    $voiceFailed = old('_form') === 'voice' && $errors->any();
@endphp

<div
    id="voice-settings-modal"
    class="blog-switch-modal site-modal voice-settings-modal"
    @if ($voiceFailed) data-modal-autoopen @endif
>

    <div
        class="blog-switch-dialog voice-settings-dialog"
    >

        <h2>
            音声操作
        </h2>

        <p class="text-muted">
            有効にすると、ヘッダーにマイクのボタンが出ます（ironman では、トップページのアークリアクターを押しても話しかけられます）。
            方式 A：ボタンを押して話し、もう一度押すか、少し黙ると送ります（1回 {{ config('blogos.voice.max_seconds') }}秒まで。発言のたびにボタンを押す）。
            方式 B・C：ボタンを押すと会話が始まり、そのまま続けて話せます。もう一度押す・Esc・{{ config('blogos.voice.realtime.idle_seconds') }}秒話しかけない・{{ intdiv((int) config('blogos.voice.realtime.max_session_seconds'), 60) }}分たつ、のどれかで終わります。開いた画面は、トップページの横のパネルに出て、会話は続きます。
        </p>
        <p class="text-muted">
            聞き取りは {{ config('blogos.voice.transcribe_model') }}、判断は {{ config('blogos.voice.text_model') }}、返事の声は {{ config('blogos.voice.tts_model') }}（OpenAI。返事は AI が作った音声です）。
            費用の目安は方式の欄のとおり（1ドル150円）で、「AIの費用と残高」の見込みと月の上限に含めます。
            できるのは、画面を開く・状況や件数や残高を答える・記事を探して開く、と、確認つきの操作（同期を始める・品質診断をまとめて実行する。内容と費用の目安を聞いてから「はい」で実行）です。WordPress への反映・削除・承認は声ではしません。
        </p>

        @unless (app(\App\Services\Ai\AiApiPolicy::class)->isConfigured())
            <p class="text-warn">OpenAI の API キー（.env の OPENAI_API_KEY）が設定されていないため、使えません。</p>
        @endunless
        <p class="text-muted">マイクは、HTTPS か localhost の画面でだけ使えます（本番の HTTPS は使える。ローカルの Herd は <code>herd secure blogos</code> で https://blogos.test にする）。</p>

        @if ($voiceFailed)
            <ul class="text-error">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        <form
            method="POST"
            action="{{ route('voice.settings.update') }}"
        >

            @csrf
            @method('PUT')
            <input type="hidden" name="_form" value="voice">

            <p><label><input type="checkbox" name="enabled" value="1" @checked($voiceFailed ? old('enabled') : $voiceSettings->enabled())> 音声の操作を使う</label></p>

            <p>方式：</p>
            @foreach (\App\Services\Voice\VoiceSettings::MODES as $value => $label)
                <label class="blog-switch-option">
                    <input type="radio" name="mode" value="{{ $value }}" @checked(($voiceFailed ? old('mode') : $voiceSettings->mode()) === $value) @disabled(! in_array($value, \App\Services\Voice\VoiceSettings::READY_MODES, true))>
                    {{ $label }}
                </label>
            @endforeach

            <p>
                <label>声：
                    <select name="voice">
                        @foreach (config('blogos.voice.voices') as $voiceName)
                            <option value="{{ $voiceName }}" @selected(($voiceFailed ? old('voice') : $voiceSettings->voice()) === $voiceName)>{{ $voiceName }}</option>
                        @endforeach
                    </select>
                </label>
                <span class="text-muted">（落ち着いた男性の声：cedar・onyx。女性の声：marin・nova・shimmer など）</span>
            </p>

            <p>
                <label>話し方の指示<br>
                    <textarea name="instructions" rows="2" style="width:100%;">{{ $voiceFailed ? old('instructions') : $voiceSettings->instructions() }}</textarea>
                </label>
            </p>

            <div
                class="blog-switch-actions"
            >

                {{-- 保存 --}}
                <button
                    type="submit"
                >
                    保存
                </button>

                {{-- キャンセル --}}
                <button
                    type="button"
                    class="btn-secondary"
                    data-modal-close
                >
                    キャンセル
                </button>

            </div>

        </form>

    </div>

</div>
