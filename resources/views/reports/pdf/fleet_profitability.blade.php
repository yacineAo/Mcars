<!DOCTYPE html>
<html dir="ltr">
<head>
    <meta charset="utf-8">
    <title>Fleet Profitability Report</title>
    <style>
        body { font-family: sans-serif; font-size: 10pt; color: #1a1a1a; margin: 40px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #16a34a; padding-bottom: 15px; }
        .header h1 { color: #16a34a; margin: 0 0 5px; font-size: 20pt; }
        .header p { margin: 2px 0; color: #6b7280; font-size: 10pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 9pt; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f0fdf4; font-weight: 600; }
        .total { font-weight: 700; background: #dcfce7; }
        .footer { margin-top: 40px; font-size: 8pt; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .money { text-align: right; }
        .summary { margin-bottom: 20px; }
        .summary-item { display: inline-block; margin-right: 30px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Fleet Profitability</h1>
        <p>{{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}</p>
        <p>{{ $branchName }}</p>
    </div>

    @if(isset($data['total_revenue']))
    <div class="summary">
        <table>
            <tr><th>Total Revenue</th><td class="money">{{ number_format($data['total_revenue'], 2) }} DZD</td></tr>
            <tr><th>Total Expenses</th><td class="money">{{ number_format($data['total_expenses'], 2) }} DZD</td></tr>
            <tr class="total"><th>Net Profit</th><td class="money">{{ number_format($data['total_net_profit'], 2) }} DZD</td></tr>
            <tr><th>Avg Utilisation</th><td>{{ $data['avg_utilisation_pct'] }}%</td></tr>
        </table>
    </div>

    @if(isset($data['cars']) && count($data['cars']) > 0)
    <table>
        <tr>
            <th>Reg No</th>
            <th>Brand</th>
            <th>Model</th>
            <th class="money">Revenue</th>
            <th class="money">Expenses</th>
            <th class="money">Profit</th>
            <th class="money">Days</th>
            <th class="money">Util.</th>
        </tr>
        @foreach($data['cars'] as $car)
        <tr>
            <td>{{ $car['registration_number'] }}</td>
            <td>{{ $car['brand'] }}</td>
            <td>{{ $car['model'] }}</td>
            <td class="money">{{ number_format($car['revenue'], 2) }}</td>
            <td class="money">{{ number_format($car['expenses'], 2) }}</td>
            <td class="money">{{ number_format($car['net_profit'], 2) }}</td>
            <td class="money">{{ $car['rental_days'] }}</td>
            <td class="money">{{ $car['utilisation_pct'] }}%</td>
        </tr>
        @endforeach
    </table>
    @endif
    @else
    <table>
        <tr><th>Registration</th><th>Brand</th><th>Model</th><th class="money">Revenue</th><th class="money">Expenses</th><th class="money">Profit</th><th class="money">Days</th><th class="money">Util.</th></tr>
        <tr>
            <td>{{ $data['registration_number'] }}</td>
            <td>{{ $data['brand'] }}</td>
            <td>{{ $data['model'] }}</td>
            <td class="money">{{ number_format($data['revenue'], 2) }}</td>
            <td class="money">{{ number_format($data['expenses'], 2) }}</td>
            <td class="money">{{ number_format($data['net_profit'], 2) }}</td>
            <td class="money">{{ $data['rental_days'] }}</td>
            <td class="money">{{ $data['utilisation_pct'] }}%</td>
        </tr>
    </table>
    @endif

    <div class="footer">
        Generated {{ $generatedAt->format('d/m/Y H:i') }} — Printed by {{ $user->name }}
    </div>
</body>
</html>
