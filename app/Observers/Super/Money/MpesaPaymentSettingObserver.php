<?php

declare(strict_types=1);

namespace App\Observers\Super\Money;

use App\Models\MpesaPaymentSetting;
use App\Models\Sacco;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use Throwable;

/**
 * Event 1 (SACCO-credential arm) — vehicle.payment_details.changed.
 *
 * A SACCO's shared M-Pesa credentials or business_short_code changing is the same
 * class of money-redirect as a vehicle's till moving. AUDIT-FIRST, never throttled.
 *
 * Bound to `saved`: `wasChanged()` is false on an insert (performInsert never
 * syncs changes), so a first-time credential set is silent and only a genuine
 * CHANGE surfaces. Credential values are secret — they are recorded as short
 * one-way hashes in both the audit and the notification, never in cleartext.
 */
final class MpesaPaymentSettingObserver
{
    /** @var array<int,string> */
    private const FIELDS = ['consumer_key', 'consumer_secret', 'pass_key', 'business_short_code'];

    /** @var array<int,string> */
    private const SECRET_FIELDS = ['consumer_key', 'consumer_secret', 'pass_key'];

    public function saved(MpesaPaymentSetting $setting): void
    {
        // Guarded: a console-alert failure must never block a credentials save.
        try {
            $this->emit($setting);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function emit(MpesaPaymentSetting $setting): void
    {
        $changed = array_values(array_filter(
            self::FIELDS,
            static fn (string $field): bool => $setting->wasChanged($field),
        ));

        if ($changed === []) {
            return;
        }

        // withoutGlobalScopes: brand context may be absent on this write path.
        $brand = $setting->sacco_id !== null
            ? Sacco::withoutGlobalScopes()->whereKey($setting->sacco_id)->value('brand')
            : null;
        $brand = $brand !== null ? (string) $brand : null;

        foreach ($changed as $field) {
            $secret = in_array($field, self::SECRET_FIELDS, true);

            $data = [
                'settingId' => (int) $setting->id,
                'saccoId' => $setting->sacco_id !== null ? (int) $setting->sacco_id : null,
                'field' => $field,
                'from' => $this->render($setting->getOriginal($field), $secret),
                'to' => $this->render($setting->getAttribute($field), $secret),
            ];

            $audit = AuditLogger::record(
                'vehicle.payment_details.changed',
                $data,
                null,
                ['type' => 'mpesa_payment_setting', 'id' => (string) $setting->id],
                $brand,
            );

            app(PlatformNotifier::class)->dispatch(new PlatformEvent(
                event: 'vehicle.payment_details.changed',
                severity: 'critical',
                class: 'alert',
                title: 'M-Pesa credentials changed',
                summary: 'SACCO M-Pesa '.$field.' was changed.',
                brand: $brand,
                actor: ['type' => $audit->actor_type, 'id' => $audit->actor_id, 'label' => $audit->actor_label],
                subject: ['type' => 'mpesa_payment_setting', 'id' => (string) $setting->id],
                data: $data,
                windowMinutes: 0,
                auditId: $audit->id,
            ));
        }
    }

    /** Secret fields never leave as cleartext — a short one-way hash proves change. */
    private function render(mixed $value, bool $secret): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($secret) {
            return substr(hash('sha256', (string) $value), 0, 12);
        }

        return (string) $value;
    }
}
