{{--
    履歴の画面の、最後の実行・記録の行（D-72-03。例：「最後の照合：2026-10-07 04:30:04（成功）」）。
    受け取る値：$label（例：最後の照合）・$at（日時。null なら出さない）・$result（結果の文。null なら括弧を出さない）
--}}
@if ($at)
    <p class="text-muted">{{ $label }}：{{ \App\Support\DisplayTime::format($at) }}{{ $result !== null ? '（' . $result . '）' : '' }}</p>
@endif
