<!DOCTYPE html>
<html dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>Expense Breakdown</title>
    <style>
        body { font-family: sans-serif; font-size: 12pt; color: #1a1a1a; margin: 40px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #d97706; padding-bottom: 15px; }
        .header h1 { color: #d97706; margin: 0 0 5px; font-size: 20pt; }
        .header p { margin: 2px 0; color: #6b7280; font-size: 10pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #fef3c7; font-weight: 600; }
        .total { font-weight: 700; background: #fef3c7; }
        .footer { margin-top: 40px; font-size: 8pt; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .money { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Expense Breakdown</h1>
        <p>{{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}</p>
        <p>{{ $branchName }}</p>
    </div>

    <table>
        <tr>
            <th style="width: 60%">Category</th>
            <th class="money">Amount</th>
        </tr>
        @forelse($data as $category => $amount)
        <tr>
            <td>{{ $category }}</td>
            <td class="money">{{ number_format($amount, 2) }} DZD</td>
        </tr>
        @empty
        <tr><td colspan="2" style="text-align: center; color: #9ca3af;">No expenses recorded</td></tr>
        @endforelse
        <tr class="total">
            <td><strong>Total</strong></td>
            <td class="money"><strong>{{ number_format(array_sum($data), 2) }} DZD</strong></td>
        </tr>
    </table>

    <div class="footer">
        Generated {{ $generatedAt->format('d/m/Y H:i') }} — Printed by {{ $user->name }}
    </div>
</body>
</html>
