{{--
    表で出す一覧の、表示件数の行（全画面で共通。表の上に出す。D-72-02・D-98-02）。
    受け取る値：$paginator（ページに分けた一覧。App\Support\ListPage）・$order（任意。並び順。省略時は「新しい順」）
    全体が50件未満なら「表示件数：○件（新しい順）」、50件以上なら最大の件数と全体の件数も出す。
--}}
@if ($paginator->total() < $paginator->perPage())
<p>表示件数：{{ number_format($paginator->count()) }}件（{{ $order ?? '新しい順' }}）</p>
@else
<p>表示件数：{{ number_format($paginator->count()) }}件（{{ $order ?? '新しい順' }}、最大{{ $paginator->perPage() }}件。全{{ number_format($paginator->total()) }}件をページに分けて表示）</p>
@endif
