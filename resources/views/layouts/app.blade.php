@php

use App\Services\ThemeService;

@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="{{ ThemeService::css() }}">
    <title>BlogOS</title>
</head>

<body>

    @yield('content')

</body>

</html>
