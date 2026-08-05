<?php

declare(strict_types=1);

namespace App\Services\Super\Fraud;

use App\Models\DriverBankLead;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Where a partner bank signs in from. With one shared key and no accounts, the
 * source IP is the only accountability the portal has, so a successful auth from
 * an origin not seen for this partner in the last 30 days is worth flagging — a
 * leaked key surfaces first as a login from somewhere new.
 *
 * Seen origins live in partner_access_origins (partner + IP, refreshed on every
 * hit). PII-free: only the partner key and the source IP.
 */
final class PartnerAccessOrigins
{
    private const UNSEEN_DAYS = 30;

    public function __construct(private readonly PlatformNotifier $notifier) {}

    /**
     * @param  array{key: string, brand: string, label: string}  $partner
     */
    public function record(array $partner, ?string $ip): void
    {
        if ($ip === null || $ip === '') {
            return;
        }

        $now = now();
        $existing = DB::table('partner_access_origins')
            ->where('partner', $partner['key'])
            ->where('ip', $ip)
            ->first();

        $isNewOrigin = $existing === null
            || $existing->last_seen_at === null
            || Carbon::parse($existing->last_seen_at)->lt($now->copy()->subDays(self::UNSEEN_DAYS));

        if ($existing === null) {
            DB::table('partner_access_origins')->insert([
                'partner' => $partner['key'],
                'ip' => $ip,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        } else {
            DB::table('partner_access_origins')
                ->where('id', $existing->id)
                ->update(['last_seen_at' => $now]);
        }

        if (! $isNewOrigin) {
            return;
        }

        $recordCount = (int) DriverBankLead::withoutGlobalScopes()
            ->where('brand', $partner['brand'])
            ->count();

        $this->notifier->dispatch(new PlatformEvent(
            event: 'partner.access.new_origin',
            severity: 'critical',
            class: 'alert',
            title: 'Partner '.$partner['label'].' signed in from a new origin',
            summary: $partner['label'].' authenticated from a new IP with access to '.$recordCount.' leads.',
            brand: $partner['brand'],
            actor: ['type' => 'partner', 'id' => $partner['key'], 'label' => $partner['label']],
            data: [
                'partner' => $partner['label'],
                'ip' => $ip,
                'recordCount' => $recordCount,
            ],
            dedupeKey: 'partner.access.new_origin:'.$partner['key'].':'.$ip,
            windowMinutes: 24 * 60,
        ));
    }
}
