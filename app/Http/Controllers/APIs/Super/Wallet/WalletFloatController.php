<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Wallet;

use App\Enums\Financier;
use App\Enums\WalletEntryKind;
use App\Enums\WalletStatus;
use App\Enums\WalletTopUpStatus;
use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletSettlement;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletFloat;
use App\Services\Wallet\WalletSettlementService;
use App\Support\BusinessDay;
use App\Support\Shillings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The float, for whoever is answerable for it.
 *
 * THE ONE QUESTION THIS SCREEN EXISTS TO ANSWER: how much of other people's
 * money are we holding, and does it add up? Everything else here is in service
 * of that. A platform holding customer funds has to be able to answer it on
 * demand -- to a passenger, to a SACCO, to an auditor, eventually to CBK -- and
 * "let me run a command" is not an answer.
 *
 * SO IT LEADS WITH THE IDENTITY, not with a vanity total:
 *
 *     received + returned - spent - settled  ==  held
 *
 * If that does not balance, the number beside it is wrong and the screen says
 * so in those terms rather than showing a confident figure. It is the same
 * arithmetic `wallet:reconcile` asserts nightly, deliberately -- two places
 * computing the float differently would mean neither could be checked against
 * the other.
 *
 * AND IT LEADS WITH THE LIABILITY, split into the two states that need
 * different actions: money sitting in the payout queue (approve it, pay it) and
 * money not yet accrued (normal for today, a bug for a closed day).
 */
class WalletFloatController extends Controller
{
    public function __construct(
        private WalletSettlementService $settlements,
        private WalletFloat $float,
    ) {}

    /**
     * Float overview
     *
     * The headline screen: what is held, what is owed, whether it reconciles,
     * and whether the rails are even switched on.
     *
     * @authenticated
     */
    public function index(): JsonResponse
    {
        $received = $this->sumOfKinds([WalletEntryKind::TopUp], positiveOnly: true);
        $returned = $this->sumOfKinds(
            [WalletEntryKind::Refund, WalletEntryKind::Reversal, WalletEntryKind::Adjustment],
            positiveOnly: true,
        );
        $spent = $this->spentCents();
        $settled = $this->settlements->settledCents();
        $held = (int) Wallet::sum('balance_cents');

        $expected = $received + $returned - $spent - $settled;

        [$dayStart] = BusinessDay::windowFor(Carbon::now());

        return response()->json(['float' => [
            // THE IDENTITY FIRST. `balances` is what we say we hold; `expected`
            // is what the movements say we should. A gap is the headline, not a
            // footnote.
            'identity' => [
                'received' => Shillings::toShillings($received),
                'returned' => Shillings::toShillings($returned),
                'spent' => Shillings::toShillings($spent),
                'settled' => Shillings::toShillings($settled),
                'expected_held' => Shillings::toShillings($expected),
                'actually_held' => Shillings::toShillings($held),
                'balances' => $expected === $held,
                'gap' => Shillings::toShillings(abs($expected - $held)),
            ],

            // WHAT WE OWE, split by what to do about it.
            'owed_to_saccos' => [
                'in_payout_queue' => Shillings::toShillings($this->settlements->outstandingCents()),
                'not_yet_accrued' => Shillings::toShillings($this->settlements->unaccruedCents()),
                // Today's fares are deliberately unaccrued until the business
                // day closes, so this distinguishes "normal" from "the sweep
                // has stopped running".
                'unaccrued_on_closed_days' => Shillings::toShillings($this->unaccruedOnClosedDays($dayStart)),
            ],

            // Which bank is holding which passengers' money. Null float_bank
            // cannot be settled to anybody, so it is called out rather than
            // folded into a total.
            'held_by_bank' => $this->heldByBank(),

            'wallets' => [
                'total' => Wallet::count(),
                'holding_money' => Wallet::where('balance_cents', '>', 0)->count(),
                'frozen' => Wallet::where('status', WalletStatus::Frozen->value)->count(),
                'with_pin' => Wallet::whereNotNull('pin_hash')->count(),
                'negative' => Wallet::where('balance_cents', '<', 0)->count(),
            ],

            'today' => [
                'topped_up' => Shillings::toShillings($this->sinceCents($dayStart, WalletEntryKind::TopUp)),
                'spent' => Shillings::toShillings(abs($this->sinceCents($dayStart, WalletEntryKind::Fare))),
                'refunded' => Shillings::toShillings($this->sinceCents($dayStart, WalletEntryKind::Refund)),
            ],

            'top_ups' => [
                'pending' => WalletTopUp::where('status', WalletTopUpStatus::Pending->value)->count(),
                // Pending AND past its window: the recovery sweep should be
                // picking these up, so a growing number means it is not.
                'stuck' => WalletTopUp::where('status', WalletTopUpStatus::TimedOut->value)
                    ->whereNull('wallet_transaction_id')->count(),
                'failed_today' => WalletTopUp::where('status', WalletTopUpStatus::Failed->value)
                    ->where('created_at', '>=', $dayStart)->count(),
            ],

            // Whether anything can move at all. The honest answer today is no,
            // and the screen should say that rather than showing a dead UI.
            'rails' => [
                'float_account_configured' => $this->float->shortCode() !== null,
                'sources' => $this->float->sources(),
                'payout_rail' => (string) config('wallet.payout.rail'),
                'payout_is_manual' => ((string) config('wallet.payout.rail')) === 'manual',
            ],
        ]]);
    }

    /**
     * @param  array<int, WalletEntryKind>  $kinds
     */
    private function sumOfKinds(array $kinds, bool $positiveOnly = false): int
    {
        $query = WalletTransaction::whereIn('kind', array_map(fn (WalletEntryKind $k) => $k->value, $kinds));

        if ($positiveOnly) {
            $query->where('amount_cents', '>', 0);
        }

        return (int) $query->sum('amount_cents');
    }

    /** Fares plus any negative correction -- everything that left a wallet. */
    private function spentCents(): int
    {
        return abs((int) WalletTransaction::where(function ($q) {
            $q->where('kind', WalletEntryKind::Fare->value)
                ->orWhere(fn ($inner) => $inner->whereIn('kind', [
                    WalletEntryKind::Reversal->value,
                    WalletEntryKind::Adjustment->value,
                ])->where('amount_cents', '<', 0));
        })->sum('amount_cents'));
    }

    private function sinceCents(Carbon $from, WalletEntryKind $kind): int
    {
        return (int) WalletTransaction::where('kind', $kind->value)
            ->where('created_at', '>=', $from)
            ->sum('amount_cents');
    }

    private function unaccruedOnClosedDays(Carbon $dayStart): int
    {
        return abs((int) WalletTransaction::where('kind', WalletEntryKind::Fare->value)
            ->whereNull('wallet_settlement_id')
            ->whereNotNull('sacco_id')
            ->where('created_at', '<', $dayStart)
            ->sum('amount_cents'));
    }

    /** @return array<int, array<string, mixed>> */
    private function heldByBank(): array
    {
        return Wallet::selectRaw("coalesce(float_bank, 'unattributed') AS bank, count(*) AS wallets, coalesce(sum(balance_cents), 0) AS cents")
            ->groupBy('bank')
            ->orderByDesc('cents')
            ->get()
            ->map(fn ($row) => [
                'bank' => (string) $row->bank,
                'label' => match ((string) $row->bank) {
                    'ncba' => Financier::Ncba->label(),
                    'coop' => Financier::Coop->label(),
                    default => 'Unattributed — cannot be settled',
                },
                'wallets' => (int) $row->wallets,
                'held' => Shillings::toShillings((int) $row->cents),
            ])
            ->all();
    }

    /**
     * Settlements
     *
     * The payout queue. Filterable by status, SACCO and bank, because paying is
     * done per bank -- the two reconcile separately.
     *
     * @authenticated
     */
    public function settlements(): JsonResponse
    {
        $status = request()->input('status');
        $saccoId = request()->input('sacco_id');
        $financier = request()->input('financier');

        $rows = WalletSettlement::with('sacco:id,name,brand')
            ->when($status !== null, fn ($q) => $q->where('status', (string) $status))
            ->when($saccoId !== null, fn ($q) => $q->where('sacco_id', (int) $saccoId))
            ->when($financier !== null, fn ($q) => $q->where('financier', (string) $financier))
            ->orderByDesc('period_start')
            ->orderBy('sacco_id')
            ->limit(500)
            ->get();

        return response()->json([
            'settlements' => $rows->map(fn (WalletSettlement $s) => $s->toWire())->values(),
            // Totals for whatever filter is applied, so the person approving a
            // batch can see what they are about to commit to.
            'totals' => [
                'count' => $rows->count(),
                'gross' => Shillings::toShillings((int) $rows->sum('gross_cents')),
                'by_status' => $rows->groupBy(fn (WalletSettlement $s) => $s->status->value)
                    ->map(fn ($group) => [
                        'count' => $group->count(),
                        'gross' => Shillings::toShillings((int) $group->sum('gross_cents')),
                    ]),
            ],
        ]);
    }

    /**
     * The fares behind one settlement
     *
     * So an approver can see what they are paying for rather than trusting a
     * total. This is the audit trail a SACCO would ask for if they disputed a
     * figure.
     *
     * @authenticated
     */
    public function settlementFares(int $id): JsonResponse
    {
        $settlement = WalletSettlement::with('sacco:id,name')->findOrFail($id);

        $fares = WalletTransaction::where('wallet_settlement_id', $settlement->id)
            ->orderBy('id')
            ->limit(2000)
            ->get();

        return response()->json([
            'settlement' => $settlement->toWire(),
            'fares' => $fares->map(fn (WalletTransaction $f) => [
                'id' => $f->id,
                'amount' => $f->amountShillings(),
                'booking_id' => $f->booking_id,
                'vehicle_id' => $f->vehicle_id,
                'reference' => $f->reference,
                'at' => $f->created_at,
            ])->values(),
            // Recomputed from the rows rather than echoed from the settlement,
            // so a disagreement between the two is visible here.
            'recomputed_gross' => Shillings::toShillings(
                abs((int) $fares->sum(fn (WalletTransaction $f) => abs((int) $f->amount_cents)))
            ),
        ]);
    }

    /**
     * Payments on our float shortcode that match no wallet
     *
     * Real money with nowhere to go -- someone mistyped their wallet number.
     * It needs a human, so it needs a screen; otherwise it sits in the float
     * forever with no passenger attached and quietly breaks the identity.
     *
     * @authenticated
     */
    public function unmatched(): JsonResponse
    {
        $shortCode = $this->float->shortCode();

        if ($shortCode === null) {
            return response()->json(['unmatched' => [], 'note' => 'No float account is configured, so nothing can arrive on it.']);
        }

        $rows = DB::table('mpesas')
            ->where('BusinessShortCode', $shortCode)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('wallet_transactions')
                ->whereColumn('wallet_transactions.mpesa_id', 'mpesas.id'))
            ->orderByDesc('TransTime')
            ->limit(200)
            ->get(['id', 'TransID', 'TransAmount', 'TransTime', 'MSISDN', 'FirstName', 'BillRefNumber']);

        return response()->json([
            'unmatched' => $rows,
            'total' => Shillings::toShillings((int) round($rows->sum(fn ($r) => (float) $r->TransAmount * 100))),
        ]);
    }
}
