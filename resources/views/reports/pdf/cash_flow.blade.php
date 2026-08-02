<!DOCTYPE html>
<html dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>Cash Flow Report</title>
    <style>
        body { font-family: sans-serif; font-size: 12pt; color: #1a1a1a; margin: 40px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #059669; padding-bottom: 15px; }
        .header h1 { color: #059669; margin: 0 0 5px; font-size: 20pt; }
        .header p { margin: 2px 0; color: #6b7280; font-size: 10pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #ecfdf5; font-weight: 600; }
        .positive { color: #16a34a; font-weight: 600; }
        .negative { color: #dc2626; font-weight: 600; }
        .total { font-weight: 700; background: #f0fdf4; }
        .footer { margin-top: 40px; font-size: 8pt; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .money { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Cash Flow Statement</h1>
        <p>{{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}</p>
        <p>{{ $branchName }}</p>
    </div>

    <table>
        <tr>
            <th style="width: 60%">Metric</th>
            <th class="money">Amount</th>
        </tr>
        <tr>
            <td>Cash Inflow</td>
            <td class="money positive">{{ number_format($data['cash_in'], 2) }} DZD</td>
        </tr>
        <tr>
            <td>Cash Outflow</td>
            <td class="money negative">{{ number_format($data['cash_out'], 2) }} DZD</td>
        </tr>
        <tr class="total">
            <td>Net Cash Flow</td>
            <td class="money {{ $data['net_cash_flow'] >= 0 ? 'positive' : 'negative' }}">{{ number_format($data['net_cash_flow'], 2) }} DZD</td>
        </tr>
    </table>

    <div class="footer">
        Generated {{ $generatedAt->format('d/m/Y H:i') }} — Printed by {{ $user->name }}
    </div>
</body>
</html>
