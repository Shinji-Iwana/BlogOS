{{--
    アークリアクター（ironman テーマ。D-49-06）

    参考画像（public/themes/ironman/images/arc-reactor.png）を基準に、SVG で描く。
    ・金属：黒に近い鏡面の金属。鋭い白い映り込みと、コアの青い光の映り込みで金属に見せる
    ・外から：段になった鋼の外枠（継ぎ目・ボルトつき）→ 10個のコイル（土台の板・縁・棒・留め金・ねじと支え。
      窓の中を、外枠の HUD と同じ蛍光色の電気が流れる）
      → コアを囲む金属の輪 → 金属で縁取った三角のコア → 金属の輪で囲んだ中心の光 → 前面のガラス
    ・金属は動かさない。動くのは、光の脈打ち・コイルの電気の流れ・周りの HUD の円の回転だけ
    ・色は css/reactor/reactor.css の変数（--reactor-*）で決め、data-state で状態の色に変える

    受け取る値（任意）：
    ・$reactorState：normal（青）／warning（金）／critical（赤）。省略時は normal
    ・$reactorBusy：true なら HUD の円を速く回す（同期の実行中など）
    ・$reactorVariant：形。1＝基本、2＝コアの外側の三角の金属と、コアの背面のコイル（金属は動かさず、電気を外側と逆向きに流す）を足した形。
      省略時は config/themes.php の ironman の reactor_variant
--}}
@php
    // SVG の部品の名前（id）を、リアクターごとに変える（同じ画面に2つ以上置いても、色が混ざらないように）
    $rid = 'reactor-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6));
    $variant = (int) ($reactorVariant ?? config('themes.themes.ironman.reactor_variant', 1));

    // 中心（200,200）からの角度（真上が0°・時計回り）と半径で、座標を求める
    $pt = fn (float $r, float $deg) => sprintf('%.2f,%.2f', 200 + $r * sin(deg2rad($deg)), 200 - $r * cos(deg2rad($deg)));
    // 円弧（$from°→$to°、時計回り）
    $arc = fn (float $r, float $from, float $to) => 'M' . $pt($r, $from) . ' A' . $r . ',' . $r . ' 0 ' . (($to - $from) > 180 ? 1 : 0) . ' 1 ' . $pt($r, $to);
    // 扇形の帯（半径 $r1〜$r2、角度 $from°〜$to°）
    $sector = fn (float $r1, float $r2, float $from, float $to) => 'M' . $pt($r2, $from) . ' A' . $r2 . ',' . $r2 . ' 0 0 1 ' . $pt($r2, $to)
        . ' L' . $pt($r1, $to) . ' A' . $r1 . ',' . $r1 . ' 0 0 0 ' . $pt($r1, $from) . ' Z';
    // 小さな円（ねじ。半径 $r・角度 $deg の位置に、大きさ $size）
    $dot = fn (float $r, float $deg, float $size) => sprintf('<circle cx="%.2f" cy="%.2f" r="%s"/>', 200 + $r * sin(deg2rad($deg)), 200 - $r * cos(deg2rad($deg)), $size);
    // 正三角形（下向き。中心から頂点までの距離 $r）。角は C 面取りする（各頂点から、辺の長さの $chamfer の位置で斜めに切る）
    $chamfer = 0.085;
    $triangle = function (float $r) use ($chamfer) {
        $corners = array_map(fn ($deg) => [200 + $r * sin(deg2rad($deg)), 200 - $r * cos(deg2rad($deg))], [180, 300, 60]);
        $points = [];
        foreach ($corners as $i => [$x, $y]) {
            [$px, $py] = $corners[($i + 2) % 3];
            [$nx, $ny] = $corners[($i + 1) % 3];
            $points[] = sprintf('%.2f,%.2f', $x + ($px - $x) * $chamfer, $y + ($py - $y) * $chamfer);
            $points[] = sprintf('%.2f,%.2f', $x + ($nx - $x) * $chamfer, $y + ($ny - $y) * $chamfer);
        }

        return 'M' . implode(' L', $points) . ' Z';
    };
@endphp
<div class="reactor" data-state="{{ $reactorState ?? 'normal' }}" @if ($reactorBusy ?? false) data-busy="1" @endif>
    <svg class="reactor-svg" viewBox="0 0 400 400" role="img" aria-label="アークリアクター">

        <defs>
            {{-- 鏡面の金属：暗い地に、鋭い白い映り込みの帯。輪ごとに向きを変え、映り込みがそろわないようにする --}}
            @foreach (['a' => 0, 'b' => 70, 'c' => 150, 'd' => 230, 'e' => 310] as $key => $angle)
                <linearGradient id="{{ $rid }}-chrome-{{ $key }}" x1="0" y1="0" x2="1" y2="1" gradientTransform="rotate({{ $angle }} 0.5 0.5)">
                    <stop offset="0" stop-color="#0a0c0f"/>
                    <stop offset="0.16" stop-color="#2b3238"/>
                    <stop offset="0.2" stop-color="#eef3f6"/>
                    <stop offset="0.24" stop-color="#3a4249"/>
                    <stop offset="0.46" stop-color="#0c0f12"/>
                    <stop offset="0.62" stop-color="#1d2329"/>
                    <stop offset="0.7" stop-color="#a7b3bd"/>
                    <stop offset="0.73" stop-color="#1b2025"/>
                    <stop offset="1" stop-color="#050607"/>
                </linearGradient>
            @endforeach
            {{-- コイルの枠・支えの金属（少し明るい暗色の鏡面） --}}
            <linearGradient id="{{ $rid }}-chrome-part" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#c9d2d9"/>
                <stop offset="0.12" stop-color="#4a535c"/>
                <stop offset="0.5" stop-color="#151a1f"/>
                <stop offset="0.85" stop-color="#2c333a"/>
                <stop offset="1" stop-color="#8994a0"/>
            </linearGradient>

            {{-- 光（状態の色。--reactor-* を使う） --}}
            <radialGradient id="{{ $rid }}-halo" r="0.5">
                <stop offset="0.72" style="stop-color: var(--reactor-glow); stop-opacity: 0"/>
                <stop offset="0.86" style="stop-color: var(--reactor-glow); stop-opacity: 0.2"/>
                <stop offset="1" style="stop-color: var(--reactor-glow); stop-opacity: 0"/>
            </radialGradient>
            <radialGradient id="{{ $rid }}-well" r="0.5">
                <stop offset="0" style="stop-color: var(--reactor-deep)"/>
                <stop offset="0.8" stop-color="#050c14"/>
                <stop offset="1" stop-color="#010305"/>
            </radialGradient>
            <radialGradient id="{{ $rid }}-core-glow" r="0.5">
                <stop offset="0" style="stop-color: var(--reactor-bright); stop-opacity: 0.7"/>
                <stop offset="0.35" style="stop-color: var(--reactor-glow); stop-opacity: 0.35"/>
                <stop offset="1" style="stop-color: var(--reactor-glow); stop-opacity: 0"/>
            </radialGradient>
            {{-- コイルの窓の奥（暗い光。流れる電気の線を目立たせる） --}}
            <radialGradient id="{{ $rid }}-coil-glow" cx="0.5" cy="0.5" r="0.7">
                <stop offset="0" style="stop-color: var(--reactor-glow); stop-opacity: 0.28"/>
                <stop offset="1" style="stop-color: var(--reactor-deep); stop-opacity: 0.9"/>
            </radialGradient>
            <linearGradient id="{{ $rid }}-tri" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" style="stop-color: var(--reactor-glow)"/>
                <stop offset="0.55" style="stop-color: var(--reactor-glow)"/>
                <stop offset="1" style="stop-color: var(--reactor-deep)"/>
            </linearGradient>
            <radialGradient id="{{ $rid }}-center" r="0.5">
                <stop offset="0" stop-color="#ffffff"/>
                <stop offset="0.6" style="stop-color: var(--reactor-bright)"/>
                <stop offset="1" style="stop-color: var(--reactor-glow)"/>
            </radialGradient>
            {{-- 金属に映るコアの光（内側ほど強い） --}}
            <radialGradient id="{{ $rid }}-reflect" r="0.5">
                <stop offset="0.5" style="stop-color: var(--reactor-glow); stop-opacity: 0.45"/>
                <stop offset="1" style="stop-color: var(--reactor-glow); stop-opacity: 0"/>
            </radialGradient>
            <linearGradient id="{{ $rid }}-glass" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#ffffff" stop-opacity="0.16"/>
                <stop offset="1" stop-color="#ffffff" stop-opacity="0"/>
            </linearGradient>

            {{-- 光のにじみ --}}
            <filter id="{{ $rid }}-blur" x="-50%" y="-50%" width="200%" height="200%">
                <feGaussianBlur stdDeviation="3" result="blur"/>
                <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
            </filter>
            <filter id="{{ $rid }}-blur-strong" x="-50%" y="-50%" width="200%" height="200%">
                <feGaussianBlur stdDeviation="8" result="blur"/>
                <feMerge><feMergeNode in="blur"/><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
            </filter>
            {{-- 電気の光（細い線を、蛍光灯のように強くにじませる） --}}
            <filter id="{{ $rid }}-neon" x="-50%" y="-50%" width="200%" height="200%">
                <feGaussianBlur stdDeviation="1.4" result="near"/>
                <feGaussianBlur stdDeviation="3.5" result="far"/>
                <feMerge><feMergeNode in="far"/><feMergeNode in="near"/><feMergeNode in="near"/><feMergeNode in="SourceGraphic"/></feMerge>
            </filter>
            {{-- 金属の鋭い映り込み（少しだけにじませる） --}}
            <filter id="{{ $rid }}-glint" x="-20%" y="-20%" width="140%" height="140%">
                <feGaussianBlur stdDeviation="0.8"/>
            </filter>

            <clipPath id="{{ $rid }}-lens"><circle cx="200" cy="200" r="147"/></clipPath>
            {{-- コアの土台の内側（形 2 の背面のコイルを、コアを囲む金属の内側に収める） --}}
            <clipPath id="{{ $rid }}-core"><circle cx="200" cy="200" r="84"/></clipPath>

            {{-- ===== コイルの部品（真上の1つを描き、回して10個並べる） ===== --}}
            {{-- 土台の板・一段高い縁・くぼみ・光る窓 --}}
            <path id="{{ $rid }}-coil-frame" d="{{ $sector(104, 147, -15.5, 15.5) }}"/>
            <path id="{{ $rid }}-coil-rim" d="{{ $sector(106.5, 144.5, -14, 14) }}"/>
            <path id="{{ $rid }}-coil-recess" d="{{ $sector(109, 142, -12.5, 12.5) }}"/>
            <path id="{{ $rid }}-coil-window" d="{{ $sector(112, 139, -11, 11) }}"/>
            {{-- 窓の中の電気の線（円弧5本） --}}
            <path id="{{ $rid }}-coil-lines" d="{{ $arc(115.5, -10, 10) }} {{ $arc(120.5, -10, 10) }} {{ $arc(125.5, -10, 10) }} {{ $arc(130.5, -10, 10) }} {{ $arc(135.5, -10, 10) }}"/>
            {{-- 窓の上下を押さえる金属の棒・左右の留め金・ねじ --}}
            <path id="{{ $rid }}-coil-rails" d="{{ $sector(138.4, 140.6, -12.5, 12.5) }} {{ $sector(110.4, 112.6, -12.5, 12.5) }}"/>
            <path id="{{ $rid }}-coil-clamps" d="{{ $sector(117, 134, -13.4, -11.2) }} {{ $sector(117, 134, 11.2, 13.4) }}"/>
            <g id="{{ $rid }}-coil-screws">
                {!! $dot(145.6, -14.4, 1.3) !!}
                {!! $dot(145.6, 14.4, 1.3) !!}
                {!! $dot(105.6, -13.6, 1.1) !!}
                {!! $dot(105.6, 13.6, 1.1) !!}
            </g>
            {{-- 窓のガラスの映り込み --}}
            <path id="{{ $rid }}-coil-shine" d="{{ $arc(137.6, -9.5, 3) }}"/>
            {{-- コイルの間の金属の支え（18°を中心に ±2.7°）と、その中央の溝 --}}
            <path id="{{ $rid }}-spoke" d="{{ $sector(103, 148, 15.3, 20.7) }}"/>
            <path id="{{ $rid }}-spoke-groove" d="M{{ $pt(106, 18) }} L{{ $pt(145, 18) }}"/>

            {{-- ===== 外枠の部品 ===== --}}
            {{-- 外の帯の継ぎ目（18°）・内の帯のボルト（0°） --}}
            <path id="{{ $rid }}-seam" d="M{{ $pt(166, 18) }} L{{ $pt(176, 18) }}"/>
            <path id="{{ $rid }}-seam-light" d="M{{ $pt(166, 18.7) }} L{{ $pt(176, 18.7) }}"/>
            <circle id="{{ $rid }}-bolt" cx="200" cy="{{ 200 - 160.5 }}" r="2.4"/>
        </defs>

        {{-- 周りに広がる光 --}}
        <circle class="reactor-halo" cx="200" cy="200" r="199" fill="url(#{{ $rid }}-halo)"/>

        {{-- HUD の円（ゆっくり回る。金属ではない） --}}
        <g class="reactor-hud reactor-hud-a">
            <circle cx="200" cy="200" r="197" fill="none" stroke-width="1.5" stroke-dasharray="70 18 140 24 40 30 160 40"/>
        </g>
        <g class="reactor-hud reactor-hud-b">
            <circle cx="200" cy="200" r="190.5" fill="none" stroke-width="4" stroke-dasharray="1 5" opacity="0.5"/>
            <circle cx="200" cy="200" r="186.5" fill="none" stroke-width="1" stroke-dasharray="4 10" opacity="0.55"/>
        </g>
        {{-- 外側に広がる電子の輪（4本。左回り・右回りを交互にし、外ほど薄く・遅くする。リアクターの枠の外にはみ出して描く） --}}
        <g class="reactor-hud reactor-hud-ring reactor-hud-c">
            <circle cx="200" cy="200" r="206" fill="none" stroke-width="1" stroke-dasharray="120 14 36 14 60 30" opacity="0.6"/>
            <circle cx="200" cy="200" r="210" fill="none" stroke-width="3" stroke-dasharray="1 7" opacity="0.35"/>
        </g>
        <g class="reactor-hud reactor-hud-ring reactor-hud-d">
            <circle cx="200" cy="200" r="219" fill="none" stroke-width="2" stroke-dasharray="40 26 8 26 180 50" opacity="0.5"/>
        </g>
        <g class="reactor-hud reactor-hud-ring reactor-hud-e">
            <circle cx="200" cy="200" r="229" fill="none" stroke-width="1" stroke-dasharray="6 6" opacity="0.4"/>
            <circle cx="200" cy="200" r="233" fill="none" stroke-width="1.2" stroke-dasharray="220 40 90 60" opacity="0.45"/>
        </g>
        <g class="reactor-hud reactor-hud-ring reactor-hud-f">
            <circle cx="200" cy="200" r="243" fill="none" stroke-width="0.8" stroke-dasharray="300 24 60 24 140 80" opacity="0.3"/>
            <circle cx="200" cy="200" r="243" fill="none" stroke-width="5" stroke-dasharray="2 46" opacity="0.4"/>
        </g>

        {{-- ===== 段になった鋼の外枠（半径 147〜182） ===== --}}
        <circle cx="200" cy="200" r="182" fill="#030405"/>
        {{-- 外の縁 --}}
        <circle cx="200" cy="200" r="179.5" fill="none" stroke="url(#{{ $rid }}-chrome-a)" stroke-width="5"/>
        {{-- 外の帯 --}}
        <circle cx="200" cy="200" r="171" fill="none" stroke="url(#{{ $rid }}-chrome-b)" stroke-width="10"/>
        {{-- 内の帯 --}}
        <circle cx="200" cy="200" r="160.5" fill="none" stroke="url(#{{ $rid }}-chrome-e)" stroke-width="9"/>
        {{-- 内の縁と、そこに映るコアの光 --}}
        <circle cx="200" cy="200" r="151" fill="none" stroke="url(#{{ $rid }}-chrome-c)" stroke-width="8"/>
        <circle cx="200" cy="200" r="151" fill="none" stroke="url(#{{ $rid }}-reflect)" stroke-width="8" class="reactor-reflect"/>
        {{-- 段の間の溝 --}}
        <circle cx="200" cy="200" r="176.6" fill="none" stroke="#020304" stroke-width="1.8"/>
        <circle cx="200" cy="200" r="165.6" fill="none" stroke="#020304" stroke-width="1.6"/>
        <circle cx="200" cy="200" r="155.4" fill="none" stroke="#020304" stroke-width="1.8"/>
        {{-- 外の帯の継ぎ目と、内の帯のボルト --}}
        <g>
            @foreach (range(0, 9) as $i)
                <use href="#{{ $rid }}-seam" stroke="#020304" stroke-width="1.6" transform="rotate({{ $i * 36 }} 200 200)"/>
                <use href="#{{ $rid }}-seam-light" stroke="#c7d1d8" stroke-opacity="0.35" stroke-width="0.6" transform="rotate({{ $i * 36 }} 200 200)"/>
                <use href="#{{ $rid }}-bolt" fill="url(#{{ $rid }}-chrome-a)" stroke="#020304" stroke-width="0.8" transform="rotate({{ $i * 36 }} 200 200)"/>
            @endforeach
        </g>
        {{-- 鋭い映り込み（左上と右下） --}}
        <g fill="none" stroke="#ffffff" stroke-linecap="round" filter="url(#{{ $rid }}-glint)">
            <path d="{{ $arc(181.2, 290, 340) }}" stroke-width="1.2" stroke-opacity="0.9"/>
            <path d="{{ $arc(175, 300, 322) }}" stroke-width="1" stroke-opacity="0.7"/>
            <path d="{{ $arc(164.6, 115, 150) }}" stroke-width="1" stroke-opacity="0.5"/>
            <path d="{{ $arc(156.4, 20, 42) }}" stroke-width="0.8" stroke-opacity="0.45"/>
            <path d="{{ $arc(147.8, 205, 235) }}" stroke-width="0.8" stroke-opacity="0.6"/>
        </g>

        {{-- ===== 10個のコイル（半径 103〜148） ===== --}}
        @include('themes.ironman.components.reactor-coils')

        {{-- ===== コアを囲む金属（半径 84〜104） ===== --}}
        <circle cx="200" cy="200" r="101.5" fill="none" stroke="url(#{{ $rid }}-chrome-d)" stroke-width="5"/>
        <circle cx="200" cy="200" r="98.4" fill="none" stroke="#020304" stroke-width="1.6"/>
        <circle cx="200" cy="200" r="91" fill="none" stroke="url(#{{ $rid }}-chrome-e)" stroke-width="13"/>
        <circle cx="200" cy="200" r="91" fill="none" stroke="url(#{{ $rid }}-reflect)" stroke-width="13" class="reactor-reflect"/>
        <circle cx="200" cy="200" r="84.3" fill="none" stroke="#020304" stroke-width="1.4"/>
        <g fill="none" stroke="#ffffff" stroke-linecap="round" filter="url(#{{ $rid }}-glint)">
            <path d="{{ $arc(103.5, 295, 335) }}" stroke-width="0.9" stroke-opacity="0.8"/>
            <path d="{{ $arc(96.8, 120, 160) }}" stroke-width="0.9" stroke-opacity="0.55"/>
        </g>

        {{-- コアの土台と光 --}}
        <circle cx="200" cy="200" r="83.6" fill="url(#{{ $rid }}-well)"/>
        @if ($variant === 2)
            {{-- 形 2：コアの背面に、外側と同じコイルを小さくして置く。金属は動かさず、中の電気を外側と逆向きに流す（半径 58〜83） --}}
            <g clip-path="url(#{{ $rid }}-core)" opacity="0.6">
                <g transform="translate(200 200) scale(0.56) translate(-200 -200)">
                    <g class="reactor-core-coils">
                        @include('themes.ironman.components.reactor-coils')
                    </g>
                </g>
            </g>
        @endif
        <circle class="reactor-core-glow" cx="200" cy="200" r="83" fill="url(#{{ $rid }}-core-glow)"/>

        {{-- ===== 金属で縁取った三角のコア（Mark VI） ===== --}}
        {{-- 三角の後ろに広がる光 --}}
        <path class="reactor-tri" d="{{ $triangle(64) }}" fill="none" stroke-width="30" stroke-linejoin="round" style="stroke: var(--reactor-glow)" opacity="0.5" filter="url(#{{ $rid }}-blur-strong)"/>
        @if ($variant === 2)
            {{-- 形 2：コアの一番外側の三角の金属（外 104・内 91。細い金属の枠。辺の太さ 6.5）。
                 角は面取りし、面取りした面を、コアを囲む金属の輪（半径 84〜98）の上に乗せて、留め具でつなぐ
                 （面の中央が半径 104×(1−1.5×0.085)≒91 になる大きさ） --}}
            <path d="{{ $triangle(104) }} {{ $triangle(91) }}" fill="url(#{{ $rid }}-chrome-b)" fill-rule="evenodd" stroke="#020304" stroke-width="1" stroke-linejoin="round"/>
            <g fill="url(#{{ $rid }}-chrome-a)" stroke="#020304" stroke-width="0.8">
                @foreach ([180, 300, 60] as $deg)
                    {!! $dot(91, $deg, 4.6) !!}
                @endforeach
            </g>
        @endif
        {{-- 金属の縁（外 86・内 41） --}}
        <path d="{{ $triangle(86) }} {{ $triangle(41) }}" fill="url(#{{ $rid }}-chrome-c)" fill-rule="evenodd" stroke="#020304" stroke-width="1" stroke-linejoin="round"/>
        {{-- 光る帯（外 76・内 50）と、帯の中を通る明るい線（暗くしすぎない脈打ち） --}}
        <g class="reactor-tri">
            <path d="{{ $triangle(76) }} {{ $triangle(50) }}" fill="url(#{{ $rid }}-tri)" fill-rule="evenodd" stroke-linejoin="round" filter="url(#{{ $rid }}-blur-strong)"/>
            <path d="{{ $triangle(64) }}" fill="none" stroke-width="6" stroke-linejoin="round" style="stroke: var(--reactor-bright)" filter="url(#{{ $rid }}-neon)"/>
            <path d="{{ $triangle(64) }}" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linejoin="round"/>
        </g>

        {{-- ===== 金属の輪で囲んだ中心の光 ===== --}}
        <circle cx="200" cy="200" r="27" fill="none" stroke="url(#{{ $rid }}-chrome-a)" stroke-width="7"/>
        <circle cx="200" cy="200" r="23.4" fill="none" stroke="#020304" stroke-width="1"/>
        <circle class="reactor-center" cx="200" cy="200" r="22.5" fill="url(#{{ $rid }}-center)" filter="url(#{{ $rid }}-blur)"/>

        {{-- 前面のガラスの映り込み --}}
        <g clip-path="url(#{{ $rid }}-lens)">
            <ellipse cx="150" cy="118" rx="130" ry="56" transform="rotate(-32 150 118)" fill="url(#{{ $rid }}-glass)"/>
        </g>

    </svg>
</div>
