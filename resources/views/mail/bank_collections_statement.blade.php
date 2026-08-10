{{-- Plain tables and inline styles: this lands in a bank's mail client, which
     is the least forgiving rendering target there is. --}}
<div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #111;">
    <h2 style="margin:0 0 4px;">Komiut collections statement</h2>
    <p style="margin:0 0 16px; color:#555;">{{ $bankLabel }} &middot; {{ $periodLabel }}</p>

    <table cellpadding="8" cellspacing="0" style="border-collapse:collapse; margin-bottom:18px;">
        <tr>
            <td style="border:1px solid #ddd;"><strong>Vehicles</strong><br>{{ number_format($totals['vehicles']) }}</td>
            <td style="border:1px solid #ddd;"><strong>Payments</strong><br>{{ number_format($totals['payments']) }}</td>
            <td style="border:1px solid #ddd;"><strong>Collected</strong><br>KES {{ number_format($totals['collected'], 2) }}</td>
        </tr>
    </table>

    <table cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%; font-size:13px;">
        <thead>
            <tr style="background:#f0f0f0;">
                <th align="left" style="border-bottom:1px solid #999;">Plate</th>
                <th align="left" style="border-bottom:1px solid #999;">SACCO</th>
                <th align="left" style="border-bottom:1px solid #999;">Till</th>
                <th align="right" style="border-bottom:1px solid #999;">Payments</th>
                <th align="right" style="border-bottom:1px solid #999;">Collected (KES)</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($rows as $r)
            <tr>
                <td style="border-bottom:1px solid #eee;">{{ $r['plate'] }}</td>
                <td style="border-bottom:1px solid #eee;">{{ $r['sacco'] }}</td>
                <td style="border-bottom:1px solid #eee;">{{ $r['bank_till'] ?: '—' }}</td>
                <td align="right" style="border-bottom:1px solid #eee;">{{ number_format($r['payments']) }}</td>
                <td align="right" style="border-bottom:1px solid #eee;">{{ number_format($r['collected'], 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @if (count($rows) === 0)
        <p style="color:#777;">No collections recorded for this period.</p>
    @endif

    <p style="margin-top:18px; color:#777; font-size:12px;">
        The full list is attached as CSV. Figures cover payments recorded by Komiut
        for vehicles financed by {{ $bankLabel }}.
    </p>
</div>
