<!DOCTYPE html>
<html dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>Profit & Loss Report</title>
    <style>
        body { font-family: sans-serif; font-size: 12pt; color: #1a1a1a; margin: 40px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #2563eb; padding-bottom: 15px; }
        .header h1 { color: #2563eb; margin: 0 0 5px; font-size: 20pt; }
        .header p { margin: 2px 0; color: #6b7280; font-size: 10pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-weight: 600; }
        .total { font-weight: 700; background: #f0fdf4; }
        .footer { margin-top: 40px; font-size: 8pt; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .money { text-align: right; font-variant-numeric: tabular-nums; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Profit & Loss Statement</h1>
        <p>{{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}</p>
        <p>{{ $branchName }}</p>
    </div>

    <table>
        <tr>
            <th style="width: 60%">Metric</th>
            <th class="money">Amount</th>
        </tr>
        <tr>
            <td>Revenue</td>
            <td class="money">{{ number_format($data['revenue'], 2) }} DZD</td>
        </tr>
        <tr>
            <td>Expenses</td>
            <td class="money">{{ number_format($data['expenses'], 2) }} DZD</td>
        </tr>
        <tr class="total">
            <td>Net Profit</td>
            <td class="money">{{ number_format($data['net_profit'], 2) }} DZD</td>
        </tr>
    </table>

    <div class="footer">
        Generated {{ $generatedAt->format('d/m/Y H:i') }} — Printed by {{ $user->name }}
    </div>
</body>
</html>
