<!DOCTYPE html>
<html dir="ltr">
<head>
    <meta charset="utf-8">
    <title>Customer Report</title>
    <style>
        body { font-family: sans-serif; font-size: 12pt; color: #1a1a1a; margin: 40px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #0891b2; padding-bottom: 15px; }
        .header h1 { color: #0891b2; margin: 0 0 5px; font-size: 20pt; }
        .header p { margin: 2px 0; color: #6b7280; font-size: 10pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #ecfeff; font-weight: 600; }
        .total { font-weight: 700; background: #f0fdf4; }
        .footer { margin-top: 40px; font-size: 8pt; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .money { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Customer Report</h1>
        <p>{{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}</p>
        <p>{{ $branchName }}</p>
    </div>

    @if(isset($data['invoiced']))
    <table>
        <tr>
            <th style="width: 60%">Metric</th>
            <th class="money">Value</th>
        </tr>
        <tr><td>Invoiced</td><td class="money">{{ number_format($data['invoiced'], 2) }} DZD</td></tr>
        <tr><td>Paid</td><td class="money">{{ number_format($data['paid'], 2) }} DZD</td></tr>
        <tr><td>Owed</td><td class="money">{{ number_format($data['owed'], 2) }} DZD</td></tr>
        <tr><td>Deposits Held</td><td class="money">{{ number_format($data['deposits_held'], 2) }} DZD</td></tr>
        <tr><td>Active Fines</td><td class="money">{{ $data['active_fines_count'] ?? 0 }}</td></tr>
    </table>
    @elseif(isset($data[0]['revenue']))
    <table>
        <tr>
            <th>Code</th>
            <th>Name</th>
            <th>Phone</th>
            <th class="money">Revenue</th>
        </tr>
        @foreach($data as $customer)
        <tr>
            <td>{{ $customer['code'] }}</td>
            <td>{{ $customer['name'] }}</td>
            <td>{{ $customer['phone'] }}</td>
            <td class="money">{{ number_format($customer['revenue'], 2) }} DZD</td>
        </tr>
        @endforeach
    </table>
    @endif

    <div class="footer">
        Generated {{ $generatedAt->format('d/m/Y H:i') }} — Printed by {{ $user->name }}
    </div>
</body>
</html>
