{{--
    説明のツールチップ（D-61-02）。項目の横に「?」を出し、マウスを乗せる・押す・Tab で選ぶと、説明を出す。
    説明の表示は public/js/blogos.js（data-tip。改行は、そのまま改行で出す）。受け取る値：$tip（説明の文）
--}}
<span class="tip" role="button" tabindex="0" data-tip="{{ $tip }}" aria-label="説明：{{ $tip }}">?</span>
