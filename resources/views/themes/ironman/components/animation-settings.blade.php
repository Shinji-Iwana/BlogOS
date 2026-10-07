{{--
    アニメーションの設定（ironman テーマ。設定 → 画面のテーマのポップアップの中。D-73-02）

    このブラウザだけに保存する（localStorage の blogos.animations）。負荷は機器・画面の大きさで変わるため、機器ごとに選べるようにする。
    変えると、すぐに反映する（開いている横の画面のパネルの中・ほかのタブも）。読み込みと反映は js/script.js（startAnimationSettings）、
    表示する前に html の data-anim-* に写すのは layouts/head、止める見た目は css/accessibility.css。
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
@endphp
<section class="animation-settings" id="animation-settings" aria-label="アニメーション">
    <h3>
        アニメーション
        @include('partials.tip', ['tip' => "このブラウザだけに保存します（機器ごとに選べます）。変えると、すぐに反映します。\n負荷は、動いている間に使う CPU の目安です（画面の大きさ・機器で変わります）。パソコンの「動きを減らす」設定にしている場合は、ここの設定にかかわらず止まります。"])
    </h3>

    <fieldset class="animation-settings-reactor">
        <legend>アークリアクター（負荷：大）</legend>
        <label><input type="radio" name="anim-reactor" value="always"> 常に動かす</label>
        <label><input type="radio" name="anim-reactor" value="when"> 次のときだけ動かす</label>
        <div class="animation-settings-when">
            <label><input type="checkbox" data-anim-key="reactor-panel"> トップページのパネルにマウスを乗せている間</label>
            <label><input type="checkbox" data-anim-key="reactor-voice"> 音声の操作中</label>
            <label><input type="checkbox" data-anim-key="reactor-sync"> 同期の実行中</label>
        </div>
        <label><input type="radio" name="anim-reactor" value="off"> 止める</label>
        <p class="text-muted">止めている間は、光の脈打ちの一番暗いところで止めます。</p>
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

    <p>
        <button type="button" class="btn-secondary" data-anim-all="on">全て動かす</button>
        <button type="button" class="btn-secondary" data-anim-all="off">全て止める</button>
    </p>
</section>
