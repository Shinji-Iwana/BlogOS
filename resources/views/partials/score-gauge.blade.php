{{--
    点数の円グラフ（100点満点のうち何点か）。品質評価の詳細の一番上の表の左（D-79-06）と、記事の画面のページの速さ（D-78-09）で使う。
    受け取る値：
    ・$score：点数（null なら「-」）
    ・$grades（任意）：色と状態の区切り [[下限, good・mid・bad, 状態], …]（上から順に当てはめる）。
      省略時は品質評価の判定の区切り（95点以上：基準以上、90点以上：あと少し、90点未満：要改善。ScoreCalculator::verdict）
    ・$decimals（任意）：点数の小数の桁（省略時 1）／$size（任意）：大きさ（px。省略時 150）
    ・$noneLabel（任意）：点数がないときの状態（省略時「未評価」）
    色はアークリアクターのコイルと同じ（good・mid・bad）。品質評価で、必須条件などで公開できないかは、表の「判定」の行で見る。
    線はアークリアクターのように、目盛りの外輪・点数の線・内側の細い輪を重ねる（動きは付けない。D-79-07）。
    点数の線は、アークリアクターのコイルのように、深い色の区切りの窓の上を、細い蛍光の線が走る（D-79-09）。
    色は CSS の color で決め、線は currentColor で引く
--}}
@php
    $acceptance = (float) config('blogos.ai.acceptance_score', 95);
    $grades ??= [[$acceptance, 'good', '基準以上'], [90.0, 'mid', 'あと少し'], [-INF, 'bad', '要改善']];
    $decimals ??= 1;
    $size ??= 150;
    $grade = $score === null ? [null, 'none', $noneLabel ?? '未評価'] : collect($grades)->first(fn ($grade) => $score >= $grade[0], [null, 'bad', '']);
    [$gaugeClass, $gaugeLabel] = [$grade[1], $grade[2]];
    $shown = $score !== null ? number_format($score, $decimals) : '-';
    $ratio = $score === null ? 0 : max(0, min(100, (float) $score)) / 100;
    // 点数の線（半径 46）。12時の位置から時計回りに、点数の割合だけ引く
    $circumference = 2 * M_PI * 46;
    $filled = $circumference * $ratio;
    // 点数の線の先端の光る点
    $angle = deg2rad($ratio * 360 - 90);
    $tip = ['x' => round(60 + 46 * cos($angle), 2), 'y' => round(60 + 46 * sin($angle), 2)];
    // 区切りのある点数の線は、区切りの輪を点数の線で切り抜いて描く（ページの中で重ならない id）
    $maskId = 'score-gauge-mask-' . uniqid();
@endphp
<figure class="score-gauge score-gauge-{{ $gaugeClass }}" style="--gauge-size: {{ (int) $size }}px;" role="img" aria-label="点数 {{ $shown }} / 100点（{{ $gaugeLabel }}）">
    <svg viewBox="0 0 120 120" width="{{ (int) $size }}" height="{{ (int) $size }}" aria-hidden="true">
        <defs>
            <mask id="{{ $maskId }}">
                <circle cx="60" cy="60" r="46" fill="none" stroke="#fff" stroke-width="12"
                    stroke-dasharray="{{ round($filled, 2) }} {{ round($circumference, 2) }}" transform="rotate(-90 60 60)"></circle>
            </mask>
            {{-- 区切りの窓の色：アークリアクターのコイルの窓と同じく、線の幅の真ん中が光の色、縁が深い色（透けると、白い背景で薄い色に見えるため、透けさせない） --}}
            <radialGradient id="{{ $maskId }}-window" gradientUnits="userSpaceOnUse" cx="60" cy="60" r="51">
                <stop offset="0.8" style="stop-color: var(--gauge-deep)"></stop>
                <stop offset="0.9" style="stop-color: currentColor"></stop>
                <stop offset="1" style="stop-color: var(--gauge-deep)"></stop>
            </radialGradient>
            {{-- 蛍光の線：コイルの電気の線と同じく、ぼかした光を線に重ねる（components/reactor の neon と同じ作り） --}}
            <filter id="{{ $maskId }}-neon" x="-20%" y="-20%" width="140%" height="140%">
                <feGaussianBlur stdDeviation="1.2" result="blur"></feGaussianBlur>
                <feMerge><feMergeNode in="blur"></feMergeNode><feMergeNode in="SourceGraphic"></feMergeNode></feMerge>
            </filter>
        </defs>

        {{-- 外輪：細い輪と、60本の目盛り（10点ごとに長い目盛り） --}}
        <circle class="score-gauge-faint" cx="60" cy="60" r="58" fill="none" stroke="currentColor" stroke-width="0.6"></circle>
        <circle class="score-gauge-faint" cx="60" cy="60" r="55" fill="none" stroke="currentColor" stroke-width="2.4"
            stroke-dasharray="0.5 {{ round(2 * M_PI * 55 / 60 - 0.5, 3) }}" transform="rotate(-90 60 60)"></circle>
        <circle class="score-gauge-dim" cx="60" cy="60" r="54.4" fill="none" stroke="currentColor" stroke-width="3.6"
            stroke-dasharray="0.8 {{ round(2 * M_PI * 54.4 / 10 - 0.8, 3) }}" transform="rotate(-90.4 60 60)"></circle>

        {{-- 点数の線：下地の区切りの輪（暗い）と、点数の分だけのコイルの窓（深い色の窓の上を、細い蛍光の線が走る。D-79-09） --}}
        <circle class="score-gauge-track" cx="60" cy="60" r="46" fill="none" stroke="currentColor" stroke-width="9"
            stroke-dasharray="4.2 1.4"></circle>
        @if ($filled > 0)
            <g mask="url(#{{ $maskId }})">
                <circle class="score-gauge-value" cx="60" cy="60" r="46" fill="none" stroke="url(#{{ $maskId }}-window)" stroke-width="9"
                    stroke-dasharray="4.2 1.4"></circle>
                <g class="score-gauge-neon" fill="none" stroke="currentColor" filter="url(#{{ $maskId }}-neon)">
                    <circle cx="60" cy="60" r="50.2" stroke-width="0.9"></circle>
                    <circle cx="60" cy="60" r="46" stroke-width="0.7" stroke-dasharray="4.2 1.4"></circle>
                    <circle cx="60" cy="60" r="41.8" stroke-width="0.9"></circle>
                </g>
            </g>
            <circle class="score-gauge-tip" cx="{{ $tip['x'] }}" cy="{{ $tip['y'] }}" r="1.8" fill="currentColor" filter="url(#{{ $maskId }}-neon)"></circle>
        @endif

        {{-- 内側：細い輪と、細かい点線の輪 --}}
        <circle class="score-gauge-dim" cx="60" cy="60" r="38.5" fill="none" stroke="currentColor" stroke-width="0.8"></circle>
        <circle class="score-gauge-faint" cx="60" cy="60" r="36" fill="none" stroke="currentColor" stroke-width="0.6" stroke-dasharray="1 2"></circle>
    </svg>
    <figcaption>
        <span class="score-gauge-number">{{ $shown }}</span>
        <span class="score-gauge-max">/100</span>
        <span class="score-gauge-label">{{ $gaugeLabel }}</span>
    </figcaption>
</figure>
