<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? $pageTitle ?? 'Venda de ingressos' }}</title>

        @if (config('app.env') != 'production')
            <meta name="robots" content="noindex">
        @endif
        <link rel="icon" href="https://media.gototem.com.br/237/files/cm7r8tkfjrrvhxs5d6bd.png" type="image/x-icon">
        <link href="{{ asset('css/assets/bootstrap.min.css') }}" rel="stylesheet" />
        <link href="{{ asset('css/app.css') }}?v=1" rel="stylesheet" />
        
        <script src="{{ asset('js/assets/axios.min.js') }}"></script>
        <script src="{{ asset('js/assets/jquery-3.7.1.slim.min.js') }}"></script>
    </head>
    <body class="font-sans antialiased d-flex flex-column min-vh-100">
        @yield('main')

        <script src="{{ asset('js/assets/bootstrap.bundle.min.js') }}"></script>
        @stack('bodyscripts')
    </body>
</html>