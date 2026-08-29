<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\CarbonCredits;

use App\Enums\RedemptionStatus;
use App\Enums\RewardPartner;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\CarbonCreditAccount;
use App\Models\CarbonCreditRedemption;
use App\Models\CarbonCreditReward;
use App\Models\CarbonCreditTransaction;
use App\Services\CarbonCredits\CarbonCreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The platform side of carbon credits: the catalogue, and the queue of claims
 * waiting to be honoured.
 *
 * Platform-only by design — a SACCO does not fund or run this scheme, so it has
 * no write here. A SACCO-funded free ride is a catalogue row with a sacco_id,
 * created by the platform once the SACCO agrees to it.
 */
class CarbonCreditAdminController extends Controller
{
    use PaginatesResults;

    public function __construct(private CarbonCreditService $credits)
    {
        $this->middleware('auth:sanctum');
    }

    /** Scheme-wide position: what is outstanding and what it would cost to honour. */
    public function overview()
    {
        $issued = (int) CarbonCreditTransaction::where('credits', '>', 0)->sum('credits');
        $spent = (int) abs((int) CarbonCreditTransaction::where('credits', '<', 0)->sum('credits'));

        return response()->json(['carbon_credits' => [
            'credits_issued' => $issued,
            'credits_spent' => $spent,
            // What the platform still owes its passengers.
            'credits_outstanding' => (int) CarbonCreditAccount::sum('credits'),
            'holders' => CarbonCreditAccount::where('credits', '>', 0)->count(),
            'travel_rewarded_ksh' => round(((int) CarbonCreditAccount::sum('lifetime_spend_cents')) / 100, 2),
            'redemptions_pending' => CarbonCreditRedemption::where('status', RedemptionStatus::Pending)->count(),
            'ksh_per_credit' => (int) config('carbon_credits.ksh_per_credit', 1000),
        ]]);
    }

    public function rewards(Request $request)
    {
        $rewards = CarbonCreditReward::with('sacco:id,name')
            ->withCount('redemptions')
            ->orderBy('credits_required');
        $__meta = $this->pageMeta($rewards, $request, 20);

        return response()->json(array_merge([
            'rewards' => $rewards->skip((max((int) $request->input('page', 1), 1) - 1) * 20)->take(20)->get(),
        ], $__meta));
    }

    /** Create or update a catalogue entry. */
    public function saveReward(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'nullable|integer|min:1|exists:carbon_credit_rewards,id',
            'name' => 'required|string|max:120',
            'partner' => ['required', Rule::in(array_column(RewardPartner::cases(), 'value'))],
            'description' => 'nullable|string|max:1000',
            'credits_required' => 'required|integer|min:1',
            'sacco_id' => 'nullable|integer|min:1|exists:saccos,id',
            'stock' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        // A free ride is honoured by a SACCO's bus, so it must name one; the
        // other partners are settled by the platform and must not.
        $isSaccoRide = $request->partner === RewardPartner::Sacco->value;
        if ($isSaccoRide && ! $request->filled('sacco_id')) {
            return response()->json(['error' => 'A SACCO free ride must name the SACCO honouring it.'], 400);
        }

        $reward = $request->filled('id')
            ? CarbonCreditReward::findOrFail((int) $request->id)
            : new CarbonCreditReward;

        $reward->fill([
            'name' => $request->name,
            'partner' => $request->partner,
            'description' => $request->description,
            'credits_required' => (int) $request->credits_required,
            'sacco_id' => $isSaccoRide ? (int) $request->sacco_id : null,
            'stock' => $request->filled('stock') ? (int) $request->stock : null,
            'is_active' => $request->has('is_active') ? (bool) $request->is_active : true,
        ])->save();

        return response()->json(['success' => 'Reward saved.', 'reward' => $reward]);
    }

    /** Claims waiting to be honoured, oldest first — this is a work queue. */
    public function redemptions(Request $request)
    {
        $status = $request->input('status', RedemptionStatus::Pending->value);

        $query = CarbonCreditRedemption::with(['reward:id,name,partner', 'user:id,firstname,lastname,phone'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status));

        $__meta = $this->pageMeta($query, $request, 20);

        return response()->json(array_merge([
            'redemptions' => $query->orderBy('created_at')->orderBy('id')
                ->skip((max((int) $request->input('page', 1), 1) - 1) * 20)->take(20)->get(),
        ], $__meta));
    }

    /** Record that a partner delivered — or that they could not. */
    public function settleRedemption(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|min:1|exists:carbon_credit_redemptions,id',
            'action' => ['required', Rule::in(['fulfil', 'cancel'])],
            'reference' => 'nullable|string|max:120',
            'reason' => 'nullable|string|max:200',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $redemption = CarbonCreditRedemption::findOrFail((int) $request->id);

        $result = $request->action === 'fulfil'
            ? $this->credits->fulfil($redemption, $request->reference)
            : $this->credits->cancel($redemption, $request->reason);

        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], $result['status']);
        }

        return response()->json([
            'success' => $request->action === 'fulfil' ? 'Marked delivered.' : 'Cancelled — credits returned.',
            'redemption' => $result['redemption'],
        ]);
    }
}
