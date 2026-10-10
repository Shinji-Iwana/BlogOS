{{--
    アークリアクターの動き（D-76）：状態ごとの動きを並べて見比べる画面。

    テーマの選択（ポップアップ）の、ironman のアニメーションの設定の「アークリアクターの動きを見る」から開く。
    アークリアクターは、本番と同じ部品（themes/ironman/components/reactor）と CSS で描く（見比べ用の data-preview を付け、
    画面の状態から写さず、アニメーションの設定でも止めない）。どのテーマを選んでいても見られるよう、アークリアクターの CSS をここで読み込む。
    色（青・黄・赤）は、画面の上で選ぶ（全てのアークリアクターの data-state を変える）。
--}}

@extends('layouts.app')

@section('content')

    @php
        // アークリアクターの CSS（ironman テーマ。.reactor の中だけに効く）
        $reactorStyles = ['css/reactor/reactor.css', 'css/animation/reactor.css', 'css/accessibility.css'];

        // 色：[値, 名前, 説明（どういう状態か）]
        $colors = [
            ['normal', '青', ['トップページの状態のパネルに、「注意」も「要対応」もないとき。']],
            ['warning', '黄', [
                '「要対応」はなく、「注意」が1つ以上あるとき：',
                '同期：まだ同期していない／未解決の問題がある',
                'WordPress：更新がある',
                'AI の残高：残りが少なめ／OpenAI API料金表の値下がりの確認待ちがある',
                '内部リンク：切り替え・修正の編集案がある',
            ]],
            ['critical', '赤', [
                '「要対応」が1つ以上あるとき：',
                '同期：最後の同期が成功以外',
                '定期実行：26時間以上動いていない／失敗がある',
                'WordPress：公開停止のプラグインがある',
                'AI の残高：残りわずか／OpenAI API料金表を読み取れなかった',
                '内部リンク：リンク切れがある',
                '教材・提携：提携終了の疑いがある',
            ]],
        ];

        $voiceBack = '音声の操作を終えると、元の動き（トップページ・パネル（引き出し）の通常時、AI の実行中）に戻る。';
        $voiceFirst = 'トップページでも、パネル（引き出し）を開いていても、すぐに切り替わる。AI の実行中・パネルの通常時より優先する。';

        // 状態：[名前, 部品に渡す値, 速さ（[部分 => [青・黄の値, 赤の値（null なら同じ）]]）, この動きになるとき, 通常時に戻るとき]
        $speed = fn (string $hud, string $ring, string $pulse, string $coil, ?string $pulseCritical = null, ?string $coilCritical = null) => [
            'HUDの円'     => [$hud, null],
            '外側の輪'    => [$ring, null],
            '脈打ち'      => [$pulse, $pulseCritical],
            'コイルの電気' => [$coil, $coilCritical],
        ];
        $scenes = [
            ['トップページの通常時', [],
                $speed('60秒・90秒で1周', '12〜34秒で1周', '3秒', '0.8秒', '1.5秒', '0.5秒'),
                ['トップページを開いていて、パネル（引き出し）を閉じていて、AI の実行中でも音声の操作中でもないとき。', 'ログイン画面と、ブログが1件もないときのトップページは、いつもこの動き（青）。'],
                ['ほかの動きが終わると、ここに戻る。']],
            ['パネル（引き出し）の通常時', ['reactorScene' => 'drawer'],
                $speed('30秒・45秒で1周', '6〜17秒で1周', '1.5秒', '0.4秒'),
                ['トップページで、画面のパネル（引き出し）を開いている間（AI の実行中・音声の操作中を除く）。', 'パネルを開くと、すぐに切り替わる。'],
                ['パネルを閉じたとき（すぐに切り替わる）。']],
            ['AI の実行中', ['reactorBusy' => true],
                $speed('12秒・18秒で1周', '4〜11秒で1周', 'なし（一番明るいまま光る）', '0.2秒'),
                [
                    'BlogOS 全体で、次のどれかがあるとき（ブログによらない）：',
                    '・選択中のブログの同期の実行中・開始待ち',
                    '・裏の処理（Queue）の開始待ち・実行中：同期、AI の実行（編集案・新規記事・品質診断など）、AI のまとめて実行、画像の作成、Google の取得、インデックスの確認、定期実行の「今すぐ実行」',
                    '・定期実行の実行中',
                    'トップページを開いている間（パネルを開いている間も）、10秒ごとに確かめて切り替える。パネル（引き出し）の通常時より優先する。',
                ],
                [
                    '上のどれもなくなったとき（次の確認で切り替わる。10秒以内）。',
                    'queue:work が止まっていると、開始待ちが残るため戻らない。3時間を過ぎた定期実行の実行中は、止まったとみなして数えない。',
                    'タブを見ていない間は確かめない（タブに戻ったとき、すぐ確かめる）。',
                ]],
            ['音声：聞いている間', ['reactorVoice' => 'listening'],
                $speed('60秒・90秒で1周', '12〜34秒で1周', '1秒', '0.8秒'),
                ['録音の会話：マイクのボタンを押して、録音している間。', 'リアルタイム会話：つながった直後、こちらが話し始めたとき、AI が話し終えたとき（次の話しかけを待つ間）。', $voiceFirst],
                [
                    '録音の会話：もう一度押す・少し黙る・録音の上限（' . (int) config('blogos.voice.max_seconds') . '秒）のどれかで録音を終えると「考えている間」へ（0.3秒未満の録音は、元の動きへ）。',
                    'リアルタイム会話：こちらが話し終えると「考えている間」へ。会話を終える・' . (int) config('blogos.voice.realtime.idle_seconds') . '秒話しかけがない・会話の上限（' . (int) config('blogos.voice.realtime.max_session_seconds') . '秒）で、元の動きへ。',
                ]],
            ['音声：考えている間', ['reactorVoice' => 'thinking'],
                $speed('6秒・9秒で1周', '12〜34秒で1周', '3秒', '0.4秒'),
                ['録音の会話：録音を終えてから、文字起こし・AI の返事を待つ間。', 'リアルタイム会話：始める操作の直後（つながるまで）と、こちらが話し終えてから AI が話し始めるまで。', $voiceFirst],
                ['返事の声が始まると「話している間」へ。', 'うまくいかなかったとき（エラー・マイクを使えないなど）は、元の動きへ。']],
            ['音声：話している間', ['reactorVoice' => 'speaking'],
                $speed('60秒・90秒で1周', '12〜34秒で1周', '0.7秒', '0.8秒'),
                ['AI の返事の声を流している間。', $voiceFirst],
                ['録音の会話：声が終わると、元の動きへ。', 'リアルタイム会話：声が終わると「聞いている間」へ。', $voiceBack]],
        ];
    @endphp

    @foreach ($reactorStyles as $style)
        <link rel="stylesheet" href="{{ asset('themes/ironman/' . $style) }}?v={{ @filemtime(public_path('themes/ironman/' . $style)) }}">
    @endforeach

    <h1>アークリアクターの動き @include('partials.tip', ['tip' => "ironman のテーマのトップページの、アークリアクターの動きを、状態ごとに並べます（実際の画面と同じ動きです）。\n色（青・黄・赤）は、上で選べます。色は、どの動きとも重なります。\nアニメーションの設定（テーマの選択）で止めていても、この画面では動かします。"])</h1>

    <p><a href="{{ route('home') }}">トップページに戻る</a></p>

    <section class="panel reactor-compare-colors">
        <h2 data-code="COLOR">色 @include('partials.tip', ['tip' => "色は、トップページを開いている間、10秒ごとに確かめて切り替えます（AI の実行中かと一緒に）。\n重なったときの動きは、音声 ＞ AI の実行中 ＞ パネル（引き出し）の通常時 ＞ トップページの通常時 の順に優先します。"])</h2>
        <p>
            {{-- 色ごとの説明（どういう状態か）は、選択肢のツールチップに出す --}}
            @foreach ($colors as [$value, $label, $notes])
                <label class="reactor-compare-color"><input type="radio" name="reactor-color" value="{{ $value }}" @checked($value === 'normal')> {{ $label }}</label>
                @include('partials.tip', ['tip' => $notes[0] . (count($notes) > 1 ? "\n・" . implode("\n・", array_slice($notes, 1)) : '')])
            @endforeach
        </p>
    </section>

    <section class="panel">
        <h2 data-code="MOTION">状態ごとの動き</h2>
        <div class="reactor-compare" data-color="normal">
            @foreach ($scenes as $sceneIndex => [$name, $params, $speeds, $when, $back])
                <figure class="reactor-compare-item">
                    <div class="reactor-compare-stage">
                        @include('themes.ironman.components.reactor', ['reactorState' => 'normal', 'reactorPreview' => true] + $params)
                    </div>
                    {{-- 状態の名前を押すと、速さの表と条件が開く --}}
                    <details class="reactor-compare-details">
                    <summary><strong>{{ $name }}</strong></summary>
                    {{-- 速さ：部分ごとの表（赤のときだけ違う値は、色を選ぶと切り替わる） --}}
                    <table class="data reactor-compare-table">
                        <colgroup><col class="reactor-compare-table-label"><col></colgroup>
                        <tbody>
                            @foreach ($speeds as $part => [$value, $critical])
                                <tr>
                                    <th style="text-align:left;">{{ $part }}</th>
                                    <td>
                                        @if ($critical === null)
                                            {{ $value }}
                                        @else
                                            <span class="reactor-compare-speed-base">{{ $value }}</span>
                                            <span class="reactor-compare-speed-critical">{{ $critical }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    {{-- 条件：1行ずつ出し、押すと中身が開く（品質評価の採点項目と同じ動き） --}}
                    <table class="data reactor-compare-table reactor-compare-cond">
                        <tbody>
                            @foreach (['この動きになるとき' => $when, '通常時に戻るとき' => $back] as $condLabel => $lines)
                                @php
                                    $condId = 'reactor-cond-' . $sceneIndex . '-' . $loop->index;
                                @endphp
                                <tr class="expand-row" tabindex="0" role="button" aria-expanded="false" aria-controls="{{ $condId }}"><td>{{ $condLabel }}</td></tr>
                                <tr class="expand-row-detail" id="{{ $condId }}" hidden>
                                    <td>
                                        <ul>
                                            @foreach ($lines as $line)
                                                <li>{{ $line }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </details>
                </figure>
            @endforeach
        </div>
    </section>

    <style>
        .reactor-compare-color { margin-left: 18px; }
        .reactor-compare-color:first-child { margin-left: 0; }
        .reactor-compare { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 32px 24px; }
        .reactor-compare-item { margin: 0; }
        /* アークリアクターは暗い背景で見る（どのテーマでも同じ見え方にする） */
        .reactor-compare-stage { display: flex; justify-content: center; padding: 40px 0; background: #050b14; border-radius: 4px; overflow: hidden; }
        .reactor-compare-stage .reactor { width: 220px; }
        .reactor-compare-details > summary { margin: 10px 0 6px; text-align: center; line-height: 1.6; cursor: pointer; }
        /* 速さと条件の表は、カードの幅いっぱいで、開いても大きさが変わらないようにする */
        .reactor-compare-table { width: 100%; table-layout: fixed; margin-top: 6px; font-size: 13px; }
        /* 部分の名前（最長「コイルの電気」）は改行しない */
        .reactor-compare-table-label { width: 9em; }
        .reactor-compare-table th { white-space: nowrap; }
        .reactor-compare-cond ul { margin: 0; padding-left: 1.2em; line-height: 1.6; }
        .reactor-compare-speed-critical,
        .reactor-compare[data-color="critical"] .reactor-compare-speed-base { display: none; }
        .reactor-compare[data-color="critical"] .reactor-compare-speed-critical { display: inline; }
    </style>

    <script>
        // 色を選ぶと、全てのアークリアクターの色と、速さの書き方を切り替える（色の説明は、選択肢のツールチップ）
        (function () {
            const grid = document.querySelector('.reactor-compare');
            document.querySelectorAll('input[name="reactor-color"]').forEach((radio) => radio.addEventListener('change', () => {
                if (!radio.checked) {
                    return;
                }
                grid.dataset.color = radio.value;
                grid.querySelectorAll('.reactor[data-preview]').forEach((reactor) => reactor.setAttribute('data-state', radio.value));
            }));
        })();

        // 条件の行を押す（Enter・スペースでも）と、すぐ下の中身を開く・閉じる
        document.querySelectorAll('.reactor-compare .expand-row').forEach((row) => {
            const toggle = () => {
                const open = row.getAttribute('aria-expanded') !== 'true';
                row.setAttribute('aria-expanded', open ? 'true' : 'false');
                document.getElementById(row.getAttribute('aria-controls')).hidden = !open;
            };
            row.addEventListener('click', toggle);
            row.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggle();
                }
            });
        });
    </script>

@endsection
