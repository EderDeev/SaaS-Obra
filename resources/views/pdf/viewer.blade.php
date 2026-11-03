<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/sigworks-mark.svg') }}?v={{ hash_file('sha256', public_path('brand/sigworks-mark.svg')) }}">
    <style>
        html, body { margin: 0; width: 100%; height: 100%; background: #323639; }
        iframe { display: block; width: 100%; height: 100%; border: 0; }
    </style>
</head>
<body>
    <iframe src="{{ $pdfUrl }}" title="{{ $title }}" allow="fullscreen"></iframe>
</body>
</html>
