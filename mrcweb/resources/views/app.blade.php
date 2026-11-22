<!DOCTYPE html>
<html lang="tr" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="status-bar-style" content="#191E2C">
    <title>MRC — Ön Muhasebe ve İş Yönetimi</title>
    <meta name="description" content="MRC: Ön muhasebe, stok, satış, borç-alacak ve raporlar tek platformda.">

    {{-- PWA: mobil ana ekrana "uygulama olarak" kurulum --}}
    <link rel="manifest" href="/manifest.webmanifest?v=2">
    <link rel="icon" type="image/png" href="/icons/icon-192.png?v=2">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png?v=2">
    <meta name="theme-color" content="#191E2C">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="MRC">
    <meta name="application-name" content="MRC">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
    <div id="app"></div>
</body>
</html>
