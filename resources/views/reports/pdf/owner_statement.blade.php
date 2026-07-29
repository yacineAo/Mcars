<!DOCTYPE html>
<html dir="ltr">
<head>
    <meta charset="utf-8">
    <title>Owner Statement</title>
    <style>
        body { font-family: sans-serif; font-size: 12pt; color: #1a1a1a; margin: 40px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #6b7280; padding-bottom: 15px; }
        .header h1 { color: #374151; margin: 0 0 5px; font-size: 20pt; }
        .header p { margin: 2px 0; color: #6b7280; font-size: 10pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-weight: 600; }
        .total { font-weight: 700; background: #f0fdf4; }
        .footer { margin-top: 40px; font-size: 8pt; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .money { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Owner Statement</h1>
        <p>{{ $data['owner_name'] ?? '' }}</p>
        <p>{{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}</p>
        <p>{{ $branchName }}</p>
    </div>

    @if(isset($data['installments']) && count($data['installments']) > 0)
    <table>
        <tr>
            <th>Period</th>
            <th>Due Date</th>
            <th class="money">Amount Due</th>
            <th class="money">Amount Paid</th>
            <th>Status</th>
        </tr>
        @foreach($data['installments'] as $inst)
        <tr>
            <td>{{ $inst['period'] }}</td>
            <td>{{ $inst['due_date'] }}</td>
            <td class="money">{{ number_format($inst['amount_due'], 2) }} DZD</td>
            <td class="money">{{ number_format($inst['amount_paid'], 2) }} DZD</td>
            <td>{{ $inst['status'] }}</td>
        </tr>
        @endforeach
    </table>
    @else
    <p style="color: #9ca3af; text-align: center;">No installments recorded in this period</p>
    @endif

    <table>
        <tr><th>Summary</th><th class="money">Amount</th></tr>
        <tr><td>Total Due</td><td class="money">{{ number_format($data['total_due'] ?? 0, 2) }} DZD</td></tr>
        <tr><td>Total Paid</td><td class="money">{{ number_format($data['total_paid'] ?? 0, 2) }} DZD</td></tr>
        <tr class="total"><td>Balance</td><td class="money">{{ number_format($data['balance'] ?? 0, 2) }} DZD</td></tr>
    </table>

    <div class="footer">
        Generated {{ $generatedAt->format('d/m/Y H:i') }} — Printed by {{ $user->name }}
    </div>
</body>
</html>
