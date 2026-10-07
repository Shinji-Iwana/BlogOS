{{--
    アニメーションの設定（ironman テーマ。設定 → 画面のテーマのポップアップの、ironman の選択欄の中。D-73-02）

    このブラウザだけに保存する（localStorage の blogos.animations）。負荷は機器・画面の大きさで変わるため、機器ごとに選べるようにする。
    ironman を選んでいるときだけ変えられ、「切替」を押したときに保存する（保存した後、画面を読み込み直して反映する）。
    どのテーマの画面からでも開けるよう、欄の動き（読み込み・選べるかの切り替え・保存）は、この部品の JavaScript で行う。
    表示する前に html の data-anim-* に写すのは layouts/head、止める見た目は css/accessibility.css、
    JavaScript で動かすものの切り替えは js/script.js（ANIMATION_DEFAULTS は、下の初めの値と同じにする）。
    パソコンの「動きを減らす」設定にしている場合は、ここの設定にかかわらず止まる。

    負荷の目安は、トップページを開いたまま何もしていないときの、Chrome の CPU（1コアを使い切って100）の参考（D-73。ウルトラワイドの画面）
--}}
@php
    // [設定の名前, 表示名, 負荷, 説明]
    $animationItems = [
        ['grid', '背景の格子を流れる光', '中', '画面いっぱいの光を、1秒に約60回描き直します（全画面・横の画面のパネルの中）。'],
        ['connectors', 'アークリアクターからの線を流れる電気', '小', 'トップページで、パネルにマウスを乗せている間だけ、線を出して電気を流します。止めると、線は出ますが流れません。'],
        ['panel-flow', 'パネルの枠を流れる電気', '小', 'パネルにマウスを乗せている間だけ動きます。'],
        ['button-flow', 'ボタン・メニューの枠を流れる電気', '小', 'ヘッダーのボタンにマウスを乗せている間と、メニューを開いている間だけ動きます。'],
        ['modal-flow', 'ポップアップの枠を流れる電気', '小', 'ポップアップを開いている間だけ動きます。'],
        ['blink', '点滅（要対応の状態の点・音声の操作中のマイク）', 'ごく小', ''],
        ['clock', 'ヘッダーの時計の秒', 'ごく小', '止めると、時刻を分まで出し、1分ごとに進めます。'],
        ['page', '画面を開いたときの動き', 'ごく小', '画面がふわっと出る動きです（1回だけ）。'],
    ];
    // 初めの値（js/script.js の ANIMATION_DEFAULTS と同じ）
    $animationDefaults = ['reactor' => 'always', 'reactor-panel' => 'on', 'reactor-voice' => 'on', 'reactor-sync' => 'on']
        + array_fill_keys(array_column($animationItems, 0), 'on');
@endphp
<div class="animation-settings" id="animation-settings" data-defaults='@json($animationDefaults)'>
    <div class="animation-settings-title">
        <span>
            アニメーション
            @include('partials.tip', ['tip' => "このブラウザだけに保存します（機器ごとに選べます）。ironman を選んでいるときだけ変えられ、「切替」を押したときに反映します。\n負荷は、動いている間に使う CPU の目安です（画面の大きさ・機器で変わります）。パソコンの「動きを減らす」設定にしている場合は、ここの設定にかかわらず止まります。"])
        </span>
        <span class="animation-settings-all">
            <button type="button" class="btn-secondary" data-anim-all="on">全て動かす</button>
            <button type="button" class="btn-secondary" data-anim-all="off">全て止める</button>
        </span>
    </div>

    <fieldset class="animation-settings-reactor">
        <legend>アークリアクター（負荷：大）</legend>
        <label><input type="radio" name="anim-reactor" value="always"> 常に動かす</label>
        <label><input type="radio" name="anim-reactor" value="when"> 次のときだけ動かす</label>
        <div class="animation-settings-when">
            <label><input type="checkbox" data-anim-key="reactor-panel"> トップページのパネルにマウスを乗せている（指で触って選んだ）間</label>
            <label><input type="checkbox" data-anim-key="reactor-voice"> 音声の操作中</label>
            <label><input type="checkbox" data-anim-key="reactor-sync"> 同期の実行中</label>
        </div>
        <span class="animation-settings-line">
            <label><input type="radio" name="anim-reactor" value="off"> 止める</label>
            @include('partials.tip', ['tip' => '止めている間は、光の脈打ちの一番暗いところで止めます。'])
        </span>
    </fieldset>

    <ul class="animation-settings-list">
        @foreach ($animationItems as [$key, $label, $load, $note])
            <li>
                <label><input type="checkbox" data-anim-key="{{ $key }}"> {{ $label }}</label>
                <span class="text-muted">（負荷：{{ $load }}）</span>
                @if ($note !== '')
                    @include('partials.tip', ['tip' => $note])
                @endif
            </li>
        @endforeach
    </ul>
</div>

<script>
    // アニメーションの設定の欄：保存した値を出し、ironman を選んでいるときだけ変えられるようにし、「切替」を押したときに保存する
    (function () {
        const section = document.getElementById('animation-settings');
        if (!section) {
            return;
        }
        const STORAGE = 'blogos.animations';
        const defaults = JSON.parse(section.dataset.defaults);
        const form = section.closest('form');
        const radios = section.querySelectorAll('input[name="anim-reactor"]');
        const boxes = section.querySelectorAll('input[data-anim-key]');
        const themeRadios = form.querySelectorAll('input[name="theme"]');

        const read = () => {
            let saved = {};
            try {
                saved = JSON.parse(window.localStorage.getItem(STORAGE) || '{}') || {};
            } catch (e) {
                saved = {};
            }
            const settings = Object.assign({}, defaults);
            Object.keys(defaults).forEach((key) => {
                const allowed = key === 'reactor' ? ['always', 'when', 'off'] : ['on', 'off'];
                if (allowed.includes(saved[key])) {
                    settings[key] = saved[key];
                }
            });

            return settings;
        };
        const show = (settings) => {
            radios.forEach((radio) => { radio.checked = radio.value === settings.reactor; });
            boxes.forEach((box) => { box.checked = settings[box.dataset.animKey] !== 'off'; });
            refresh();
        };
        const collect = () => {
            const settings = Object.assign({}, defaults);
            radios.forEach((radio) => { if (radio.checked) { settings.reactor = radio.value; } });
            boxes.forEach((box) => { settings[box.dataset.animKey] = box.checked ? 'on' : 'off'; });

            return settings;
        };
        const ironmanChosen = () => Array.from(themeRadios).some((radio) => radio.checked && radio.value === 'ironman');
        // ironman を選んでいるときだけ変えられる。動かす条件は、「次のときだけ動かす」を選んでいるときだけ
        const refresh = () => {
            const editable = ironmanChosen();
            section.classList.toggle('is-disabled', !editable);
            section.querySelectorAll('input, button').forEach((input) => { input.disabled = !editable; });
            const when = Array.from(radios).some((radio) => radio.checked && radio.value === 'when');
            boxes.forEach((box) => {
                if (box.dataset.animKey.startsWith('reactor-')) {
                    box.disabled = !editable || !when;
                }
            });
        };

        show(read());
        section.addEventListener('change', refresh);
        themeRadios.forEach((radio) => radio.addEventListener('change', refresh));
        // 「全て動かす」「全て止める」は、欄の選び方だけ変える（保存は「切替」を押したとき）
        section.querySelectorAll('[data-anim-all]').forEach((button) => button.addEventListener('click', () => {
            const value = button.dataset.animAll;
            const settings = Object.assign({}, defaults);
            Object.keys(settings).forEach((key) => {
                if (!key.startsWith('reactor')) {
                    settings[key] = value;
                }
            });
            settings.reactor = value === 'on' ? 'always' : 'off';
            show(settings);
        }));
        // 「切替」を押したとき、ironman を選んでいれば保存する（画面を読み込み直すと、反映される）
        form.addEventListener('submit', () => {
            if (!ironmanChosen()) {
                return;
            }
            try {
                window.localStorage.setItem(STORAGE, JSON.stringify(collect()));
            } catch (e) {
                // 保存できないブラウザでは、反映しない
            }
        });
        // ポップアップを開き直したときは、保存した値に戻す（「キャンセル」で閉じた変更を残さない）
        document.querySelectorAll('[data-modal-open="theme-switch-modal"]').forEach((opener) => opener.addEventListener('click', () => {
            form.reset();
            show(read());
        }));
    })();
</script>
