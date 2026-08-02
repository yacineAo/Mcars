<!DOCTYPE html>
<html dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>Receivables Ageing</title>
    <style>
        body { font-family: sans-serif; font-size: 12pt; color: #1a1a1a; margin: 40px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #dc2626; padding-bottom: 15px; }
        .header h1 { color: #dc2626; margin: 0 0 5px; font-size: 20pt; }
        .header p { margin: 2px 0; color: #6b7280; font-size: 10pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #fef2f2; font-weight: 600; }
        .total { font-weight: 700; background: #fef2f2; }
        .footer { margin-top: 40px; font-size: 8pt; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .money { text-align: right; }
        .danger { color: #dc2626; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Receivables Ageing</h1>
        <p>{{ $branchName }}</p>
        <p>As of {{ $generatedAt->format('d/m/Y') }}</p>
    </div>

    <table>
        <tr>
            <th style="width: 60%">Bucket</th>
            <th class="money">Amount</th>
        </tr>
        <tr>
            <td>0–30 days</td>
            <td class="money">{{ number_format($data['0_30'], 2) }} DZD</td>
        </tr>
        <tr>
            <td>31–60 days</td>
            <td class="money">{{ number_format($data['31_60'], 2) }} DZD</td>
        </tr>
        <tr>
            <td>61–90 days</td>
            <td class="money {{ $data['61_90'] > 0 ? 'danger' : '' }}">{{ number_format($data['61_90'], 2) }} DZD</td>
        </tr>
        <tr>
            <td class="danger">90+ days</td>
            <td class="money danger">{{ number_format($data['90_plus'], 2) }} DZD</td>
        </tr>
        <tr class="total">
            <td>Total Outstanding</td>
            <td class="money">{{ number_format($data['0_30'] + $data['31_60'] + $data['61_90'] + $data['90_plus'], 2) }} DZD</td>
        </tr>
    </table>

    <div class="footer">
        Generated {{ $generatedAt->format('d/m/Y H:i') }} — Printed by {{ $user->name }}
    </div>
</body>
</html>
