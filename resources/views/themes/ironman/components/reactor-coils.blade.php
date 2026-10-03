{{--
    アークリアクターの10個のコイル（components/reactor から読み込む。D-49-06）

    中心 200,200・半径 103〜148 に描く。外側のコイルと、コアの背面の逆回転のコイル（形 2）で同じものを使う。
    部品の形と色は、components/reactor の <defs>（$rid）を使う。
--}}
    <circle cx="200" cy="200" r="125.5" fill="none" stroke="#04070a" stroke-width="45"/>
    {{-- 金属の土台の板・一段高い縁・くぼみ（動かない） --}}
    <g class="reactor-coil-frames">
        @foreach (range(0, 9) as $i)
            <use href="#{{ $rid }}-coil-frame" fill="url(#{{ $rid }}-chrome-part)" stroke="#020304" stroke-width="1" transform="rotate({{ $i * 36 }} 200 200)"/>
            <use href="#{{ $rid }}-coil-rim" fill="none" stroke="url(#{{ $rid }}-chrome-a)" stroke-width="1.6" transform="rotate({{ $i * 36 }} 200 200)"/>
            <use href="#{{ $rid }}-coil-recess" fill="#04070a" stroke="#000" stroke-width="0.8" transform="rotate({{ $i * 36 }} 200 200)"/>
        @endforeach
    </g>
    {{-- 窓の奥の光（脈打つ） --}}
    <g class="reactor-coil-lights">
        @foreach (range(0, 9) as $i)
            <use href="#{{ $rid }}-coil-window" fill="url(#{{ $rid }}-coil-glow)" transform="rotate({{ $i * 36 }} 200 200)" style="animation-delay: -{{ $i * 0.4 }}s"/>
        @endforeach
    </g>
    {{-- 電気の線：うっすら光る線の上を、短い光（火花）と長い光が流れる --}}
    <g class="reactor-coil-base" fill="none">
        @foreach (range(0, 9) as $i)
            <use href="#{{ $rid }}-coil-lines" transform="rotate({{ $i * 36 }} 200 200)"/>
        @endforeach
    </g>
    <g class="reactor-coil-spark-a" fill="none" filter="url(#{{ $rid }}-neon)">
        @foreach (range(0, 9) as $i)
            <use href="#{{ $rid }}-coil-lines" transform="rotate({{ $i * 36 }} 200 200)" style="animation-delay: -{{ round($i * 0.37, 2) }}s"/>
        @endforeach
    </g>
    <g class="reactor-coil-spark-b" fill="none" filter="url(#{{ $rid }}-neon)">
        @foreach (range(0, 9) as $i)
            <use href="#{{ $rid }}-coil-lines" transform="rotate({{ $i * 36 }} 200 200)" style="animation-delay: -{{ round($i * 0.61, 2) }}s"/>
        @endforeach
    </g>
    {{-- 窓のガラス・上下の棒・左右の留め金・ねじ --}}
    <g>
        @foreach (range(0, 9) as $i)
            <use href="#{{ $rid }}-coil-shine" fill="none" stroke="#ffffff" stroke-opacity="0.35" stroke-width="0.8" transform="rotate({{ $i * 36 }} 200 200)"/>
            <use href="#{{ $rid }}-coil-rails" fill="url(#{{ $rid }}-chrome-part)" stroke="#020304" stroke-width="0.6" transform="rotate({{ $i * 36 }} 200 200)"/>
            <use href="#{{ $rid }}-coil-clamps" fill="url(#{{ $rid }}-chrome-d)" stroke="#020304" stroke-width="0.6" transform="rotate({{ $i * 36 }} 200 200)"/>
            <use href="#{{ $rid }}-coil-screws" fill="url(#{{ $rid }}-chrome-a)" stroke="#020304" stroke-width="0.5" transform="rotate({{ $i * 36 }} 200 200)"/>
        @endforeach
    </g>
    {{-- コイルの間の金属の支え --}}
    <g>
        @foreach (range(0, 9) as $i)
            <use href="#{{ $rid }}-spoke" fill="url(#{{ $rid }}-chrome-part)" stroke="#020304" stroke-width="0.8" transform="rotate({{ $i * 36 }} 200 200)"/>
            <use href="#{{ $rid }}-spoke-groove" stroke="#020304" stroke-width="1" transform="rotate({{ $i * 36 }} 200 200)"/>
        @endforeach
    </g>
