<?php

declare(strict_types=1);

use App\Enums\RewardPartner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put something in the carbon credit catalogue, so the scheme has an exit.
 *
 * `carbon_credit_rewards` was empty in production. Earning, balances, history,
 * the redeem endpoint, stock, refunds and fulfilment were all built and all
 * correct — and none of it could ever complete, because there was nothing to
 * spend a credit on. redeem() cannot succeed against an empty catalogue whatever
 * the passenger's balance, so the loop had no end.
 *
 * THESE ARE PLACEHOLDERS AND SHOULD BE PRICED BY SOMEBODY WHO OWNS THE
 * COMMERCIALS. At the configured 300 KSh per credit, 10 credits is 3,000 KSh of
 * travel and 40 credits is 12,000 — roughly a month of daily commuting. Stock is
 * deliberately small: a reward is a real obligation, fulfilment is MANUAL (an
 * admin sends the airtime and records the reference via
 * POST super/carbon-credits/redemptions/settle — there is no partner API behind
 * RewardPartner), and an unbounded catalogue would let the platform promise more
 * than anyone can hand out. Raise the stock, or change the prices, from the
 * admin endpoint; nothing here needs another migration.
 *
 * NO SACCO-FUNDED FREE RIDE HERE, deliberately. The loyalty scheme already does
 * free rides properly — points redeem against a specific booking and settle it
 * as paid, atomically (LoyaltyService::redeemForBooking). A carbon "free ride"
 * would duplicate that with a manual fulfilment step and commit a SACCO to an
 * obligation it has not agreed to. Carbon credits are the PLATFORM's reward; the
 * platform funds what is offered here.
 *
 * Idempotent: skips any reward whose name already exists, so re-running never
 * duplicates the catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rewards = [
            [
                'name' => 'KSh 50 airtime',
                'partner' => RewardPartner::Safaricom->value,
                'description' => 'Airtime sent to the number on your account.',
                'credits_required' => 10,
                'stock' => 100,
            ],
            [
                'name' => '1GB data bundle',
                'partner' => RewardPartner::Safaricom->value,
                'description' => 'A 1GB bundle, valid for 7 days, sent to the number on your account.',
                'credits_required' => 20,
                'stock' => 100,
            ],
            [
                'name' => 'KSh 200 shopping voucher',
                'partner' => RewardPartner::Supermarket->value,
                'description' => 'A voucher code to spend with a partner supermarket.',
                'credits_required' => 40,
                'stock' => 50,
            ],
        ];

        foreach ($rewards as $reward) {
            if (DB::table('carbon_credit_rewards')->where('name', $reward['name'])->exists()) {
                continue;
            }

            DB::table('carbon_credit_rewards')->insert($reward + [
                // Platform-funded, so no SACCO is on the hook for it.
                'sacco_id' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Removes only the untouched placeholders.
     *
     * A reward somebody has already claimed is referenced by a redemption row
     * and by a passenger's spent credits; deleting it would strand both. Rolling
     * back a migration should never destroy a claim someone is waiting on.
     */
    public function down(): void
    {
        $names = ['KSh 50 airtime', '1GB data bundle', 'KSh 200 shopping voucher'];

        DB::table('carbon_credit_rewards')
            ->whereIn('name', $names)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('carbon_credit_redemptions')
                    ->whereColumn('carbon_credit_redemptions.carbon_credit_reward_id', 'carbon_credit_rewards.id');
            })
            ->delete();
    }
};
