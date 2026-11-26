<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

use App\Enums\SaccoClaimStatus;
use App\Models\Sacco;
use App\Models\Vehicle;
use App\Services\Mpesa\TillRegistrar;
use App\Services\Ncba\NcbaStkClient;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the platform knows about the SACCO an email is from, right now -- shown
 * beside the thread so whoever answers sees "KDY 614G · needs a head office"
 * before asking the customer what is wrong.
 *
 * Read from the till registration status written on every bus
 * (TillRegistrar), so this says exactly what the SACCO's own screen says.
 * Two small queries per opened thread, bounded by one SACCO's fleet: a count
 * per status, and the plates of at most a few failing buses.
 */
final class ThreadContext
{
    /** Up to this many failing tills are named one by one; more are summed. */
    private const NAMED = 3;

    /**
     * A till in one of these states is not delivering payments here and
     * needs someone to act. Short labels for a chip, keyed by the registrar's
     * own statuses; its MESSAGES are the long form the SACCO sees.
     */
    private const FAILING = [
        TillRegistrar::NEEDS_HEAD_OFFICE => ['needs a head office', 'danger'],
        TillRegistrar::CONFIRM_HEAD_OFFICE => ['head office to confirm', 'warning'],
        TillRegistrar::REFUSED => ['refused by Safaricom', 'danger'],
        TillRegistrar::BAD_KEYS => ['M-Pesa keys rejected', 'danger'],
        TillRegistrar::NO_KEYS => ['head office has no M-Pesa keys', 'danger'],
        TillRegistrar::NOT_LIVE => ['head office not live', 'warning'],
        TillRegistrar::GAVE_UP => ['Safaricom unreachable, gave up', 'danger'],
        TillRegistrar::NO_SHORT_CODE => ['no store number', 'warning'],
        // Not yet asked: a till saved before registration was automatic.
        'blank' => ['till not registered', 'warning'],
    ];

    /** Still in hand: worth knowing, nothing to do yet. */
    private const IN_PROGRESS = [
        TillRegistrar::PENDING => 'registering',
        TillRegistrar::UNREACHABLE => 'Safaricom unreachable, retrying',
    ];

    /** @return array{issues: list<array{label:string, tone:string}>}|null null when no SACCO is matched */
    public function forSacco(?int $saccoId): ?array
    {
        if ($saccoId === null) {
            return null;
        }

        $sacco = Sacco::withoutGlobalScopes()->find($saccoId, ['id', 'name', 'status', 'claim_status']);
        if ($sacco === null) {
            return null;
        }

        $issues = [];

        if (! (bool) $sacco->status) {
            $issues[] = ['label' => 'SACCO is inactive', 'tone' => 'warning'];
        }
        if ($sacco->claim_status === SaccoClaimStatus::PendingReview) {
            $issues[] = ['label' => 'Directory claim waiting for review', 'tone' => 'info'];
        }

        return ['issues' => [...$issues, ...$this->tills($saccoId)]];
    }

    /** @return list<array{label:string, tone:string}> */
    private function tills(int $saccoId): array
    {
        $status = "COALESCE(NULLIF(till_registration_status, ''), 'blank')";

        $counts = $this->ours($saccoId)
            ->selectRaw("{$status} AS s, COUNT(*) AS n")
            ->groupByRaw($status)
            ->pluck('n', 's')
            ->map(fn ($n) => (int) $n);

        $failing = $counts->only(array_keys(self::FAILING))->filter();
        $total = $failing->sum();
        $issues = [];

        if ($total > 0 && $total <= self::NAMED) {
            $buses = $this->ours($saccoId)
                ->whereRaw("{$status} IN (".implode(',', array_fill(0, $failing->count(), '?')).')', $failing->keys()->all())
                ->orderBy('plate')
                ->get(['plate', 'till_registration_status']);

            foreach ($buses as $bus) {
                [$label, $tone] = self::FAILING[$bus->till_registration_status ?: 'blank'];
                $issues[] = ['label' => $bus->plate.' · '.$label, 'tone' => $tone];
            }
        } elseif ($total > self::NAMED) {
            $issues[] = ['label' => $total.' tills unregistered', 'tone' => 'danger'];
            foreach ($failing->sortDesc() as $key => $n) {
                [$label, $tone] = self::FAILING[$key];
                $issues[] = ['label' => $n.' · '.$label, 'tone' => $tone];
            }
        }

        foreach (self::IN_PROGRESS as $key => $label) {
            if (($n = $counts->get($key, 0)) > 0) {
                $issues[] = ['label' => $n.' '.($n === 1 ? 'till' : 'tills').' '.$label, 'tone' => 'info'];
            }
        }

        return $issues;
    }

    /**
     * The SACCO's buses whose till WE register: a bus the bank delivers for
     * (NCBA paybill, a bank collection account on record, a bank-issued till)
     * is left out, as TillRegistrar::bankDelivers() leaves it alone -- and so
     * is a bus with no till at all.
     */
    private function ours(int $saccoId): Builder
    {
        return Vehicle::withoutGlobalScopes()
            ->where('sacco_id', $saccoId)
            ->where(fn (Builder $q) => $q->whereNotNull('merchant_short_code')->where('merchant_short_code', '!=', '')
                ->orWhere(fn (Builder $t) => $t->whereNotNull('till_number')->where('till_number', '!=', '')))
            ->where(fn (Builder $q) => $q->whereNull('merchant_short_code')->orWhere('merchant_short_code', '!=', app(NcbaStkClient::class)->paybill()))
            ->where(fn (Builder $q) => $q->whereNull('coop_till')->orWhere('coop_till', ''))
            ->where(fn (Builder $q) => $q->whereNull('ncba_till')->orWhere('ncba_till', ''))
            ->where(fn (Builder $q) => $q->whereNull('till_registration_status')
                ->orWhereNotIn('till_registration_status', [TillRegistrar::NOT_OURS, TillRegistrar::BANK_ISSUED]));
    }
}
