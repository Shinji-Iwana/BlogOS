{{--
    履歴の画面の表示件数の行（D-72-02）。受け取る値：$paginator（ページに分けた記録）
--}}
<p>表示件数：{{ $paginator->count() }}件（新しい順、最大{{ $paginator->perPage() }}件。全{{ number_format($paginator->total()) }}件をページに分けて表示）</p>
