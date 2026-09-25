{{--
    行単位の差分（App\Support\LineDiff::compact の結果）

    受け取る値：$diff（null の場合は、大きすぎて比べられなかったことを表示する）
--}}
@if ($diff === null)
    <p>本文が長すぎるため、差分を表示できません。</p>
@elseif ($diff === [])
    <p>変更はありません。</p>
@else
    <pre style="white-space:pre-wrap; word-break:break-all; max-height:600px; overflow:auto; border:1px solid #ccc; padding:6px; font-size:13px;">@foreach ($diff as $row)<span style="display:block; {{ match ($row['type']) { 'added' => 'background:#e6ffec;', 'removed' => 'background:#ffebe9;', 'skip' => 'color:#888;', default => '' } }}">{{ match ($row['type']) { 'added' => '+ ', 'removed' => '- ', 'skip' => '  ', default => '  ' } }}{{ $row['line'] }}</span>@endforeach</pre>
@endif
