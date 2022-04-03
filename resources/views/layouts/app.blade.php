<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>@yield('title')</title>

    {{-- this is a pure speed test so don't need to make it pretty
    <link href="{{ mix('css/app.css') }}" rel="stylesheet">
    <script defer src="{{ mix('js/app.js') }}"></script> --}}
</head>

<body>
    @yield('content')
</body>

</html>
