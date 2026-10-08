{{--
    お知らせの種類を表すアークリアクターの小さな絵（お知らせ履歴の一覧。D-74-06）

    動かない絵。アークリアクター（themes/ironman/components/reactor）を簡単にし、光の部分は一番明るいときの色にする。
    1つの画面で何度も使うため、形（symbol）をここで1回だけ置き、各行は <svg><use href="#notice-reactor"/></svg> で使う。
    光の色は、使う側の svg の color（currentColor）。金属の色は変えない。
--}}
<svg width="0" height="0" style="position:absolute;" aria-hidden="true" focusable="false">
    <symbol id="notice-reactor" viewBox="0 0 40 40">
        {{-- 外枠の金属 --}}
        <circle cx="20" cy="20" r="19" fill="#0d1115" stroke="#8994a0" stroke-width="1.2"/>
        <circle cx="20" cy="20" r="17.3" fill="none" stroke="currentColor" stroke-width="0.8" opacity="0.7"/>
        {{-- 10個のコイル（光る窓） --}}
        <circle cx="20" cy="20" r="13.2" fill="none" stroke="currentColor" stroke-width="5.2" stroke-dasharray="6.2 2.09" opacity="0.95"/>
        {{-- コアを囲む金属 --}}
        <circle cx="20" cy="20" r="9.6" fill="#05080b" stroke="#a7b3bd" stroke-width="1.3"/>
        {{-- 三角のコア（下向き）と光 --}}
        <path d="M20 28 L13.07 16 L26.93 16 Z M20 24.5 L16.1 17.75 L23.9 17.75 Z" fill="currentColor" fill-rule="evenodd"/>
        <circle cx="20" cy="20" r="3.4" fill="currentColor" opacity="0.85"/>
        <circle cx="20" cy="20" r="2.1" fill="#ffffff"/>
    </symbol>
</svg>
