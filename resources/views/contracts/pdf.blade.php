<!DOCTYPE html>
<html dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>{{ $contract->contract_number }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12pt; color: #1a1a1a; margin: 40px; }
        h1 { color: #1a56db; font-size: 18pt; margin: 0 0 20px; }
        h3 { margin: 20px 0 4px; }
        p { margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 8px 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #f3f4f6; }
        .total { font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $contract->contract_number }}</h1>
    {!! $documentHtml !!}
</body>
</html>
