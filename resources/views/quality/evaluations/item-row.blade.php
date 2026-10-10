{{--
    品質評価の詳細の、評価項目の行（D-79。必須条件・採点項目と指摘の表で共通）。
    評価項目・判定（・得点）の行を押すと、すぐ下に、判定の基準（あるとき）・理由・指摘の表が開く（開く動きは quality/evaluations/show の JavaScript）。

    受け取る値：
    ・$key：項目のキー（例：req.title_match・intent.main）
    ・$label：項目の名前（必須条件は条件）
    ・$detail：判定の記録（ArticleEvaluationDetail。判定していなければ null）
    ・$criteria：判定の基準（「○ …… ／ △ ……」。null なら「-」。false なら行を出さない（必須条件））
    ・$withPoints：得点の列を出すか
    ・$typeRequired：記事の型の必須（★）か
--}}
@php
    $detailId = 'evaluation-item-' . preg_replace('/[^a-z0-9_-]/i', '-', $key);
    $columns = $withPoints ? 3 : 2;
@endphp
<tr class="evaluation-item @if ($detail && $detail->judgment !== \App\Enums\Judgment::Good) row-attention @endif" tabindex="0" role="button" aria-expanded="false" aria-controls="{{ $detailId }}">
    <td>
        {{ $label }}@if ($typeRequired ?? false)<strong>（★必須）</strong>@endif <code style="font-size:80%;">{{ $key }}</code>
    </td>
    <td style="white-space:nowrap;">{{ $detail?->judgment->label() ?? '-' }}</td>
    @if ($withPoints)
        <td style="white-space:nowrap;">{{ $detail?->points !== null ? rtrim(rtrim(number_format($detail->points, 1), '0'), '.') . '点 / ' . $detail->max_points . '点' : '-' }}</td>
    @endif
</tr>
<tr class="evaluation-item-detail" id="{{ $detailId }}" hidden>
    <td colspan="{{ $columns }}">
        <table class="data evaluation-item-table">
            <colgroup><col class="evaluation-item-table-label"><col></colgroup>
            <tbody>
                @if ($criteria !== false)
                    <tr>
                        <th style="text-align:left;">判定の基準</th>
                        <td>
                            {{-- 「○ …… ／ △ ……」を、判定ごとに「判定」「補足」の行の表にする（D-79-02） --}}
                            @php
                                $criteriaRows = \App\Support\QualityCriteria::split($criteria);
                            @endphp
                            @if ($criteriaRows === [])
                                -
                            @else
                                <table class="data evaluation-criteria">
                                    <colgroup><col class="evaluation-criteria-mark"><col></colgroup>
                                    <tbody>
                                        @foreach ($criteriaRows as [$mark, $note])
                                            <tr><th>{{ $mark }}</th><td>{{ $note }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </td>
                    </tr>
                @endif
                <tr><th style="text-align:left;">理由</th><td>{{ $detail?->comment ?: '-' }}</td></tr>
                <tr>
                    <th style="text-align:left;">指摘</th>
                    <td>
                        @if ($detail && ($detail->location || $detail->problem || $detail->fix))
                            @if ($detail->location)<strong>どこが：</strong>{{ $detail->location }}<br>@endif
                            @if ($detail->problem)<strong>何が足りないか：</strong>{{ $detail->problem }}<br>@endif
                            @if ($detail->fix)<strong>どう直すか：</strong>{{ $detail->fix }}@endif
                        @else
                            -
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
    </td>
</tr>
