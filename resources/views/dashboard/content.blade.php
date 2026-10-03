{{--
    トップページの中身（お知らせと各画面への入口。D-16-01・D-49）

    どのテーマでも同じ中身を使う。テーマで並べ方・飾りを変える場合は、
    resources/views/themes/{テーマ名}/dashboard/index.blade.php から、この部品（または dashboard/notices・dashboard/links）を読み込む。
    表示するデータは DashboardController が渡す。
--}}

@include('dashboard.notices')

@if ($blogs->isNotEmpty())

    @include('dashboard.links')

@else

    {{-- ブログが1件も登録されていない場合は、ブログ登録へ誘導する（DEVELOPMENT_RULES 15章） --}}
    <p>登録されているブログがありません。</p>
    <p><a href="{{ route('blogs.create') }}">ブログを登録する</a></p>

@endif

<form method="POST" action="{{ route('logout') }}" style="display:inline;">
    @csrf
    <button class="btn-secondary" type="submit">ログアウト</button>
</form>
