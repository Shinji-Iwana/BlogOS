{{--
    トップページの、各画面への入口（blank の形：種類ごとに「種類：リンク・リンク」の1行。D-49-07）

    入口の一覧は App\Support\DashboardLinks（DashboardController が $linkGroups で渡す）。
--}}

@foreach ($linkGroups as $group)
    <p>
        {{ $group['label'] }}：@foreach ($group['links'] as $link)@if (! $loop->first)・@endif<a href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach
    </p>
@endforeach
