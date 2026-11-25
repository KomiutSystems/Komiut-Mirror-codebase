<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\RefusedCallback;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Exception\ConflictingHeadersException;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Only the sender may post a payment notification.
 *
 * Safaricom, Co-op and NCBA tell us about payments by POSTing to a URL. None of
 * them signs the POST (NCBA's password has sat in plain text in mpesa_logs),
 * and whatever the body says becomes a bus's takings, a paid invoice or a
 * wallet balance. Anyone who learns the URL -- they have been in Blade views,
 * bank contracts and proxy logs -- could write money that never arrived. The
 * one fact that separates the sender from everyone else is where the POST
 * comes from, so that is what is checked here.
 *
 * Usage: `callback.source:safaricom` or several, `callback.source:ncba,safaricom`
 * (any of them may post). Each source's list is `services.{source}.source_ips`
 * (CIDR allowed). An EMPTY list switches that source's check off; if every
 * named source is empty the route is open, as these routes used to be.
 *
 * A REFUSED POST IS NEVER LOST. Refusing real money is the risk of an address
 * list, so the whole request is kept in refused_callbacks (credential fields
 * stripped), a critical alert goes to the console, and
 * `payments:replay-refused` records it once a person has confirmed the sender.
 *
 * The address is `$request->ip()`: the rightmost X-Forwarded-For entry that is
 * not a trusted proxy, which the ALB appends and a caller cannot forge.
 */
final class CallbackSourceAllowlist
{
    /** Set only by payments:replay-refused, never from anything a caller sends. */
    public const REPLAY = 'callback_source_replay';

    /**
     * Refusals kept per sender and address per hour, for bodies that do NOT
     * look like a payment. Anything that does is always kept: a real sender
     * on a new address can post thousands an hour, and each one is a fare.
     */
    private const JUNK_PER_HOUR = 200;

    /** Longest body kept. A payment notification is a few hundred bytes. */
    private const MAX_BODY = 65535;

    /** Fields a sender authenticates with: never stored. */
    private const CREDENTIALS = ['Username', 'Password', 'username', 'password'];

    public function handle(Request $request, Closure $next, string ...$sources): Response
    {
        if ($request->attributes->get(self::REPLAY) === true) {
            return $next($request);
        }

        $allowed = [];
        foreach ($sources as $source) {
            $allowed = [...$allowed, ...(array) config("services.{$source}.source_ips", [])];
        }

        $ip = $this->senderAddress($request);
        if ($allowed === [] || IpUtils::checkIp($ip, $allowed)) {
            return $next($request);
        }

        $this->keep($request, $sources, $ip);

        return response()->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
    }

    /**
     * The address the load balancer saw. A `Forwarded` header that disagrees
     * with X-Forwarded-For makes Symfony refuse to choose; the ALB sets only
     * X-Forwarded-For, so on that conflict it is the one believed.
     */
    private function senderAddress(Request $request): string
    {
        try {
            return (string) $request->ip();
        } catch (ConflictingHeadersException) {
            $request->headers->remove('FORWARDED');

            return (string) $request->ip();
        }
    }

    /** @param  list<string>  $sources */
    private function keep(Request $request, array $sources, string $ip): void
    {
        $source = implode(',', $sources);

        // Guarded: a failure to keep or announce the refusal must not turn the
        // 403 into a 500.
        $refused = null;
        try {
            [$body, $contentType] = $this->bodyWithoutCredentials($request);
            $isPayment = $this->looksLikePayment($body);

            if ($isPayment || RateLimiter::attempt("callback-refused:{$source}:{$ip}", self::JUNK_PER_HOUR, fn () => true, 3600)) {
                $refused = RefusedCallback::create([
                    'source' => $source,
                    'ip' => $ip,
                    'method' => $request->getMethod(),
                    'host' => $request->getHost(),
                    'path' => '/'.ltrim($request->path(), '/'),
                    'content_type' => $contentType,
                    'body' => mb_strcut($body, 0, self::MAX_BODY),
                    'credentials_ok' => $this->credentialsOk($request, $sources),
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }

        // When no row could be kept, the log line carries the body instead, so
        // the payment can still be rebuilt from it.
        Log::warning('payment callback refused: not from its sender', [
            'source' => $source,
            'ip' => $ip,
            'path' => $request->path(),
            'refused_callback_id' => $refused?->id,
            'body' => $refused === null ? mb_strcut($body ?? (string) $request->getContent(), 0, 4096) : null,
        ]);

        try {
            app(PlatformNotifier::class)->dispatch(new PlatformEvent(
                event: 'payment.callback.refused',
                severity: 'critical',
                class: 'alert',
                title: 'Payment notification refused: unknown sender address',
                summary: "A {$source} payment notification came from ".$ip
                    .', which is not on its list. If the sender is genuine, add the address and run '
                    .'payments:replay-refused so the payment is recorded.',
                subject: ['type' => 'callback_source', 'id' => $source],
                data: ['ip' => $ip, 'path' => $request->path(), 'refused_callback_id' => $refused?->id],
                dedupeKey: 'payment.callback.refused:'.$source.':'.$ip,
                windowMinutes: 60,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** A transaction reference in any sender's shape: Safaricom/NCBA TransID, Co-op's narration. */
    private function looksLikePayment(string $body): bool
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            return filled($json['TransID'] ?? null) || filled($json['Narration'] ?? null) || filled($json['TransactionId'] ?? null);
        }

        return (bool) preg_match('#<(?:\w+:)?TransID>[^<]+<#i', $body);
    }

    /**
     * NCBA signs in on its webhook: did THIS post carry the right username and
     * password? Kept so a replay never signs a forged post in.
     *
     * @param  list<string>  $sources
     */
    private function credentialsOk(Request $request, array $sources): ?bool
    {
        if (! in_array('ncba', $sources, true)) {
            return null;
        }
        $u = (string) config('services.ncba.username');
        $p = (string) config('services.ncba.password');
        $raw = (string) $request->getContent();
        $given = [(string) $request->input('Username', ''), (string) $request->input('Password', '')];
        if ($given === ['', ''] && preg_match('#<(?:\w+:)?Username>([^<]*)<#i', $raw, $mu) && preg_match('#<(?:\w+:)?Password>([^<]*)<#i', $raw, $mp)) {
            $given = [html_entity_decode($mu[1], ENT_XML1), html_entity_decode($mp[1], ENT_XML1)];
        }

        return $u !== '' && $p !== '' && hash_equals($u, $given[0]) && hash_equals($p, $given[1]);
    }

    /**
     * The body as it can be replayed, minus anything a sender signs in with.
     *
     * @return array{0: string, 1: ?string} body and its content type
     */
    private function bodyWithoutCredentials(Request $request): array
    {
        $raw = (string) $request->getContent();
        $type = $request->headers->get('Content-Type');
        $strip = fn (array $fields): string => (string) json_encode(array_diff_key($fields, array_flip(self::CREDENTIALS)));

        $json = json_decode($raw, true);
        if (is_array($json)) {
            return [$strip($json), 'application/json'];
        }
        // A form post: kept as JSON, which every receiver reads the same way.
        if ($request->request->count() > 0) {
            return [$strip($request->request->all()), 'application/json'];
        }

        // XML (NCBA's SOAP form) or anything else: mask the credential elements.
        return [(string) preg_replace('#<((?:\w+:)?(?:Username|Password))>[^<]*</\1>#i', '<$1>***</$1>', $raw), $type];
    }
}
