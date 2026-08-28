{{-- PDF report for the transactions screen. Deliberately plain, like the
     summaries report beside it: dompdf supports a narrow CSS subset and
     silently ignores flex and grid, so this is tables and inline styles.

     This is the TRANSACTION-LEVEL report — one row per payment. The summaries
     report is one row per bus per day; when a SACCO is reconciling a single
     matatu's takings against the conductor's book, that is the wrong grain. --}}
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
        .muted { color: #777; }
    </style>
</head>
<body>
    <h1>Transactions</h1>
    <div class="meta">
        {{ $from->toDateString() }} to {{ $to->toDateString() }}
        &middot; {{ $rows->count() }} payment(s)
        &middot; generated {{ now()->toDayDateTimeString() }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Payer</th>
                <th>Phone</th>
                <th>Plate</th>
                <th>SACCO</th>
                <th>Method</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $t)
                <tr>
                    <td>{{ optional($t->trans_date)->toDateTimeString() }}</td>
                    <td>{{ $controller->reference($t) ?: '—' }}</td>
                    <td>{{ $controller->payer($t) ?: '—' }}</td>
                    <td>{{ $controller->payerPhone($t) ?: '—' }}</td>
                    <td>{{ optional($t->vehicle)->plate ?: '—' }}</td>
                    <td>{{ optional(optional($t->vehicle)->sacco)->name ?: '—' }}</td>
                    <td>{{ $t->mpesa_id ? 'M-Pesa' : ($t->cash_id ? 'Cash' : '—') }}</td>
                    <td class="num">{{ number_format((float) $t->amount, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="muted">No transactions in this range.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="total">
                <td colspan="7">TOTAL</td>
                <td class="num">{{ number_format((float) $total, 2) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
