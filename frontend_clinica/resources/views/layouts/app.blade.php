<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#0b233f">
    <meta name="description" content="@yield('meta_description', config('clinic.identity.description'))">
    <title>@yield('title', config('clinic.identity.name'))</title>
    @fonts
    <script>document.documentElement.classList.add('js');</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="@yield('body_class')">
    <a class="skip-link" href="#contenido-principal">Saltar al contenido</a>
    @yield('content')
    @stack('scripts')
</body>
</html>
