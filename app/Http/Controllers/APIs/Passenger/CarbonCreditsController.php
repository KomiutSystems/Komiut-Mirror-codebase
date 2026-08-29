<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Passenger;

use App\Enums\RedemptionStatus;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\CarbonCreditRedemption;
use App\Models\CarbonCreditReward;
use App\Models\CarbonCreditTransaction;
use App\Services\CarbonCredits\CarbonCreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * A passenger's own carbon credits: what they have, how they got there, and
 * what they can spend it on.
 *
 * Everything here is scoped to auth()->id() with no permission branch that could
 * widen it — a passenger holds no permissions, so a gated endpoint would return
 * an empty screen, the same trap PassengerPaymentsController exists to avoid.
 */
class CarbonCreditsController extends Controller
{
    use PaginatesResults;

    public function __construct(private CarbonCreditService $credits)
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Balance and progress toward the next credit
     *
     * @authenticated
     */
    public function summary()
    {
        $account = $this->credits->accountFor((int) auth()->id());
        $perCredit = (int) config('carbon_credits.ksh_per_credit', 1000);

        return response()->json(['carbon_credits' => [
            'credits' => $account->credits,
            // The progress bar the app draws. Without it the screen reads
            // "0 credits" for a fortnight and looks broken.
            'progress_ksh' => round($account->progress_cents / 100, 2),
            'ksh_per_credit' => $perCredit,
            'ksh_to_next_credit' => $account->shillingsToNextCredit(),
            'lifetime_spend_ksh' => round($account->lifetime_spend_cents / 100, 2),
        ]]);
    }

    /**
     * The ledger behind the balance
     *
     * @authenticated
     */
    public function history(Request $request)
    {
        $ledger = CarbonCreditTransaction::where('user_id', auth()->id());
        $__meta = $this->pageMeta($ledger, $request, 20);

        $rows = $ledger->orderByDesc('created_at')->orderByDesc('id')
            ->skip((max((int) $request->input('page', 1), 1) - 1) * 20)->take(20)->get()
            ->map(fn (CarbonCreditTransaction $t) => [
                'id' => $t->id,
                'credits' => $t->credits,
                'type' => $t->type->value,
                'label' => $t->type->label(),
                'description' => $t->description,
                'spend_ksh' => round($t->spend_cents / 100, 2),
                'created_at' => $t->created_at,
            ]);

        return response()->json(array_merge(['history' => $rows], $__meta));
    }

    /**
     * What credits can be exchanged for
     *
     * @authenticated
     */
    public function rewards()
    {
        $account = $this->credits->accountFor((int) auth()->id());

        $rewards = CarbonCreditReward::where('is_active', true)
            ->with('sacco:id,name')
            ->orderBy('credits_required')
            ->get()
            ->map(fn (CarbonCreditReward $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'partner' => $r->partner->value,
                'partner_label' => $r->partner->label(),
                'description' => $r->description,
                'credits_required' => $r->credits_required,
                'sacco' => $r->sacco?->only(['id', 'name']),
                'sold_out' => $r->stock !== null && $r->stock <= 0,
                'affordable' => $account->credits >= $r->credits_required,
            ]);

        return response()->json(['rewards' => $rewards, 'credits' => $account->credits]);
    }

    /**
     * Claim a reward
     *
     * @authenticated
     *
     * @bodyParam reward_id integer required The reward to claim. Example: 3
     */
    public function redeem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reward_id' => 'required|integer|min:1|exists:carbon_credit_rewards,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $reward = CarbonCreditReward::findOrFail((int) $request->reward_id);
        $result = $this->credits->redeem(auth()->user(), $reward);

        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], $result['status']);
        }

        return response()->json([
            'success' => 'Claimed. We will send it through shortly.',
            'redemption' => $result['redemption'],
        ]);
    }

    /**
     * The passenger's own claims
     *
     * @authenticated
     */
    public function redemptions()
    {
        $rows = CarbonCreditRedemption::where('user_id', auth()->id())
            ->with('reward:id,name,partner')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(50)->get()
            ->map(fn (CarbonCreditRedemption $r) => [
                'id' => $r->id,
                'reward' => $r->reward?->name,
                'credits_spent' => $r->credits_spent,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'reference' => $r->status === RedemptionStatus::Fulfilled ? $r->reference : null,
                'created_at' => $r->created_at,
            ]);

        return response()->json(['redemptions' => $rows]);
    }
}
