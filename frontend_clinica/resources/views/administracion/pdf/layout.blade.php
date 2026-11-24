<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>@yield('pdf_title')</title>
    <style>
        @page { margin: 108px 34px 44px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; line-height: 1.4; color: #172b4d; }
        body.profile-sheet { font-size: 12px; }
        header { position: fixed; top: -83px; left: 0; right: 0; padding-bottom: 9px; border-bottom: 2px solid #2563eb; }
        .clinic { font-size: 18px; font-weight: bold; margin: 0 0 2px; }
        h1 { font-size: 14px; font-weight: normal; margin: 0 0 5px; }
        h2 { font-size: 12px; margin: 16px 0 8px; }
        .meta { font-size: 9px; color: #526176; margin: 0; }
        footer { position: fixed; bottom: -27px; left: 0; right: 150px; font-size: 8px; color: #526176; }
        .scope { padding: 10px 0 12px; border-bottom: 1px solid #e2e8f0; margin-bottom: 12px; }
        .scope p { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { text-align: left; font-size: 9px; font-weight: normal; color: #526176; padding: 8px 6px; border-bottom: 1px solid #8492a6; }
        td { padding: 6px; vertical-align: top; border-bottom: 1px solid #e2e8f0; overflow-wrap: break-word; word-wrap: break-word; }
        small, .muted { font-size: 9px; color: #526176; }
        small { display: block; }
        .change { margin: 0 0 5px; }
        .empty { padding: 25px 0; color: #526176; }
        .profile td { padding: 10px 6px; }
        .profile td:first-child { width: 34%; color: #526176; }
        .note { white-space: pre-wrap; }
    </style>
</head>
<body class="@yield('pdf_body_class')">
    <header><p class="clinic">{{ config('clinic.identity.name') }}</p><h1>@yield('pdf_title')</h1><p class="meta">Generado: {{ $generatedAt }} (Bolivia) · Por: {{ $author }}</p></header>
    <footer>Documento de uso administrativo · {{ config('clinic.identity.short_name') }}</footer>
    @yield('pdf_content')
</body>
</html>
