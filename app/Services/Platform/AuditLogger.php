<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * Writes the immutable audit row that precedes a sensitive notification. Call
 * this FIRST for anything touching money, PII, or privilege, then pass the
 * returned id as the notification's audit reference.
 *
 * Payloads carry counts / ids / hashes — never names, phones, or ID numbers.
 */
class AuditLogger
{
    /**
     * @param  array<string,mixed>  $data  before/after, counts, refs — no PII
     * @param  array{type?:string,id?:string,label?:string}|null  $actor
     * @param  array{type?:string,id?:string}|null  $subject
     */
    public static function record(
        string $action,
        array $data = [],
        ?array $actor = null,
        ?array $subject = null,
        ?string $brand = null,
    ): AuditLog {
        $request = request();
        $actor ??= self::actorFromAuth();

        return AuditLog::create([
            'action' => $action,
            'actor_type' => $actor['type'] ?? null,
            'actor_id' => isset($actor['id']) ? (string) $actor['id'] : null,
            'actor_label' => $actor['label'] ?? null,
            'subject_type' => $subject['type'] ?? null,
            'subject_id' => isset($subject['id']) ? (string) $subject['id'] : null,
            'brand' => $brand ?? (Context::has('brand') ? (string) Context::get('brand') : null),
            'data' => $data,
            'ip' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 512) ?: null,
            'created_at' => now(),
        ]);
    }

    /** @return array{type:string,id:?string,label:?string} */
    private static function actorFromAuth(): array
    {
        $user = Auth::user();
        if ($user === null) {
            return ['type' => 'system', 'id' => null, 'label' => null];
        }

        return [
            'type' => 'user',
            'id' => (string) $user->getAuthIdentifier(),
            'label' => trim(($user->firstname ?? '').' '.($user->lastname ?? '')) ?: null,
        ];
    }
}
