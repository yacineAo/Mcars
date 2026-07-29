<!DOCTYPE html>
<html dir="ltr">
<head>
    <meta charset="utf-8">
    <title>Cash Session Audit</title>
    <style>
        body { font-family: sans-serif; font-size: 10pt; color: #1a1a1a; margin: 40px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #d97706; padding-bottom: 15px; }
        .header h1 { color: #d97706; margin: 0 0 5px; font-size: 20pt; }
        .header p { margin: 2px 0; color: #6b7280; font-size: 10pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 9pt; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #fffbeb; font-weight: 600; }
        .variance-pos { color: #16a34a; }
        .variance-neg { color: #dc2626; }
        .footer { margin-top: 40px; font-size: 8pt; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .money { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Cash Session Audit</h1>
        <p>{{ $from->format('d/m/Y') }} — {{ $to->format('d/m/Y') }}</p>
        <p>{{ $branchName }}</p>
    </div>

    @if(count($data) > 0)
    <table>
        <tr>
            <th>#</th>
            <th>Opened</th>
            <th>Closed</th>
            <th>Opened By</th>
            <th>Account</th>
            <th class="money">Float</th>
            <th class="money">Expected</th>
            <th class="money">Counted</th>
            <th class="money">Variance</th>
            <th>Status</th>
        </tr>
        @foreach($data as $session)
        <tr>
            <td>{{ $session['id'] }}</td>
            <td>{{ $session['opened_at'] ? \Carbon\CarbonImmutable::parse($session['opened_at'])->format('d/m/Y H:i') : '-' }}</td>
            <td>{{ $session['closed_at'] ? \Carbon\CarbonImmutable::parse($session['closed_at'])->format('d/m/Y H:i') : '-' }}</td>
            <td>{{ $session['opened_by'] }}</td>
            <td>{{ $session['account_name'] }}</td>
            <td class="money">{{ number_format($session['opening_float'], 2) }}</td>
            <td class="money">{{ number_format($session['expected'], 2) }}</td>
            <td class="money">{{ number_format($session['counted'], 2) }}</td>
            <td class="money {{ $session['variance'] >= 0 ? 'variance-pos' : 'variance-neg' }}">{{ number_format($session['variance'], 2) }}</td>
            <td>{{ $session['status'] }}</td>
        </tr>
        @endforeach
    </table>
    @else
    <p style="text-align: center; color: #9ca3af;">No cash sessions found in this period</p>
    @endif

    <div class="footer">
        Generated {{ $generatedAt->format('d/m/Y H:i') }} — Printed by {{ $user->name }}
    </div>
</body>
</html>
