{{--
    一覧の画面の表示件数の行（D-72-02）。受け取る値：$paginator（ページに分けた記録）・$order（任意。並び順。省略時は「新しい順」）
--}}
<p>表示件数：{{ $paginator->count() }}件（{{ $order ?? '新しい順' }}、最大{{ $paginator->perPage() }}件。全{{ number_format($paginator->total()) }}件をページに分けて表示）</p>
