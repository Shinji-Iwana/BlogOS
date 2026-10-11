{{--
    観点の適合度のバーと割合（D-47。品質評価の詳細の「観点ごとの適合度」と、編集案の「指摘と対応」で共通）。
    受け取る値：$value（適合度。0〜100。null なら「-」）。90以上・70以上・70未満で、バーの色を変える
--}}
@if ($value === null)
    -
@else
    <span class="bar-track" style="display:inline-block; width:120px; vertical-align:middle;"><span class="{{ $value >= 90 ? 'bar-good' : ($value >= 70 ? 'bar-mid' : 'bar-bad') }}" style="display:block; height:10px; width:{{ max(0, min(100, $value)) }}%;"></span></span>
    {{ number_format($value, 1) }}%
@endif
