{{-- PDF report for the summaries screen. Deliberately plain: dompdf supports a
     narrow CSS subset, so this uses tables and inline styles rather than flex
     or grid, which it silently ignores. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .meta { font-size: 9px; color: #555; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f0f0f0; text-align: left; padding: 5px; border-bottom: 1px solid #999; font-size: 9px; }
        td { padding: 4px 5px; border-bottom: 1px solid #eee; }
        .num { text-align: right; }
        tr.total td { font-weight: bold; border-top: 2px solid #999; background: #fafafa; }
        .cards td { border: 1px solid #ddd; padding: 6px; width: 25%; }
        .cards .label { font-size: 8px; color: #666; text-transform: uppercase; }
        .cards .value { font-size: 13px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Vehicle Collections</h1>
    <div class="meta">
        {{ $sacco }} &middot; {{ $from }} to {{ $to }} &middot;
        generated {{ $generatedAt }} by {{ $generatedBy }}
    </div>

    <table class="cards" style="margin-bottom:12px">
        <tr>
            <td><div class="label">Collections</div><div class="value">Ksh {{ number_format($totals['collections'], 2) }}</div></td>
            <td><div class="label">M-Pesa</div><div class="value">Ksh {{ number_format($totals['mpesa_amount'], 2) }}</div></td>
            <td><div class="label">Cash</div><div class="value">Ksh {{ number_format($totals['cash_amount'], 2) }}</div></td>
            <td><div class="label">Net after expenses</div><div class="value">Ksh {{ number_format($totals['net_amount'], 2) }}</div></td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Plate</th><th>SACCO</th>
                <th class="num">M-Pesa</th><th class="num">Txns</th>
                <th class="num">Cash</th><th class="num">Txns</th>
                <th class="num">Collections</th><th class="num">Expenses</th><th class="num">Net</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td>{{ $r->plate }}</td>
                <td>{{ $r->sacco_name }}</td>
                <td class="num">{{ number_format((float) $r->mpesa_amount, 2) }}</td>
                <td class="num">{{ (int) $r->mpesa_txn }}</td>
                <td class="num">{{ number_format((float) $r->cash_amount, 2) }}</td>
                <td class="num">{{ (int) $r->cash_txn }}</td>
                <td class="num">{{ number_format((float) $r->totals, 2) }}</td>
                <td class="num">{{ number_format((float) $r->expense_fee_amount, 2) }}</td>
                <td class="num">{{ number_format((float) $r->totals - (float) $r->expense_fee_amount, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="9" style="padding:12px;color:#777">No collections recorded for this period.</td></tr>
        @endforelse
        </tbody>
        <tfoot>
            <tr class="total">
                <td>TOTAL</td>
                <td>{{ $totals['vehicles'] }} vehicle(s)</td>
                <td class="num">{{ number_format($totals['mpesa_amount'], 2) }}</td>
                <td class="num">{{ $totals['mpesa_txn'] }}</td>
                <td class="num">{{ number_format($totals['cash_amount'], 2) }}</td>
                <td class="num">{{ $totals['cash_txn'] }}</td>
                <td class="num">{{ number_format($totals['collections'], 2) }}</td>
                <td class="num">{{ number_format($totals['expense_fee_amount'], 2) }}</td>
                <td class="num">{{ number_format($totals['net_amount'], 2) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
