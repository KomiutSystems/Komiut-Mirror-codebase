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
 * A REFUSED POST IS NOT LOST. Refusing real money is the risk of an address
 * list, so the request is kept in refused_callbacks exactly as it came (only
 * credentials masked), a critical alert goes to the console, and
 * `payments:replay-refused` records it once a person has confirmed the sender.
 * Anything that looks like a payment is kept however many arrive -- a real
 * sender on a new address posts thousands an hour; past a very high ceiling,
 * or if the row cannot be written, the log line carries the body instead.
 *
 * The address is `$request->ip()`: the rightmost X-Forwarded-For entry that is
 * not a trusted proxy, which the ALB appends and a caller cannot forge.
 */
final class CallbackSourceAllowlist
{
    /** Set only by payments:replay-refused, never from anything a caller sends. */
    public const REPLAY = 'callback_source_replay';

    /** Refused payment-shaped posts kept per sender and address per hour: far above any real peak. */
    private const PAYMENTS_PER_HOUR = 20000;

    /** Refused posts that do not look like a payment, kept per sender and address per hour. */
    private const JUNK_PER_HOUR = 200;

    /** Longest body kept. A payment notification is a few hundred bytes; SOAP a few KB. */
    private const MAX_BODY = 16384;

    /** Fields a sender authenticates with: masked wherever they appear. */
    private const CREDENTIALS = 'Username|Password';

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
        $body = '';
        $refused = null;

        // Guarded: a failure to keep or announce the refusal must not turn the
        // 403 into a 500.
        try {
            $body = $this->mask((string) $request->getContent());
            $isPayment = $this->looksLikePayment($request);
            [$limit, $bucket] = $isPayment ? [self::PAYMENTS_PER_HOUR, 'pay'] : [self::JUNK_PER_HOUR, 'junk'];

            if (RateLimiter::attempt("callback-refused:{$bucket}:{$source}:{$ip}", $limit, fn () => true, 3600)) {
                $query = $request->getQueryString();
                $refused = RefusedCallback::create([
                    'source' => $source,
                    'ip' => $ip,
                    'method' => $request->getMethod(),
                    'host' => $request->getHost(),
                    'path' => mb_strcut('/'.ltrim($request->path(), '/').($query ? '?'.$this->mask($query) : ''), 0, 512),
                    'content_type' => $request->headers->get('Content-Type'),
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
            'body' => $refused === null ? mb_strcut($body, 0, 4096) : null,
        ]);

        try {
            app(PlatformNotifier::class)->dispatch(new PlatformEvent(
                event: 'payment.callback.refused',
                severity: 'critical',
                class: 'alert',
                title: 'Payment notification refused: unknown sender address',
                summary: "A {$source} payment notification came from {$ip}, which is not on its list. If the sender "
                    .'is genuine, add the address and run payments:replay-refused so the payment is recorded.',
                subject: ['type' => 'callback_source', 'id' => $source],
                data: ['ip' => $ip, 'path' => $request->path(), 'refused_callback_id' => $refused?->id],
                dedupeKey: 'payment.callback.refused:'.$source.':'.$ip,
                windowMinutes: 60,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The body as it came, with any credential masked: a JSON key, an XML
     * element (plain, namespaced or entity-escaped inside a SOAP string) or a
     * form field. Kept as text in its own format, so a replay sends what
     * arrived. Invalid UTF-8 is scrubbed: the column is text.
     */
    private function mask(string $raw): string
    {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            array_walk_recursive($json, function (&$value, $key): void {
                if (is_string($key) && preg_match('/^(?:'.self::CREDENTIALS.')$/i', $key)) {
                    $value = '***';
                }
            });

            return (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $c = self::CREDENTIALS;
        $masked = preg_replace([
            '#<((?:\w+:)?(?:'.$c.'))(\s[^>]*)?>.*?</\1>#is',              // <Password>x</Password>
            '#&lt;((?:\w+:)?(?:'.$c.'))(\s.*?)?&gt;.*?&lt;/\1&gt;#is',    // escaped inside a SOAP string
            '#(^|&)((?:'.$c.'))=[^&]*#i',                                  // form field
        ], ['<$1$2>***</$1>', '&lt;$1$2&gt;***&lt;/$1&gt;', '$1$2=***'], $raw);

        return mb_scrub((string) ($masked ?? ''), 'UTF-8');
    }

    /**
     * Does this carry a transaction reference in any sender's shape? Safaricom
     * and NCBA: TransID (JSON, form, XML -- with attributes or entity-escaped).
     * Co-op: a narration or the bank's transaction id.
     */
    private function looksLikePayment(Request $request): bool
    {
        foreach (['TransID', 'Narration', 'TransactionId'] as $key) {
            if (filled($request->input($key))) {
                return true;
            }
        }

        $raw = html_entity_decode((string) $request->getContent(), ENT_QUOTES | ENT_XML1);

        return (bool) preg_match('#<(?:\w+:)?TransID\b[^>]*>\s*[^<\s]#i', $raw);
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

        $given = [(string) $request->input('Username', ''), (string) $request->input('Password', '')];
        if ($given === ['', '']) {
            $raw = html_entity_decode((string) $request->getContent(), ENT_QUOTES | ENT_XML1);
            if (preg_match('#<(?:\w+:)?Username\b[^>]*>(.*?)<#is', $raw, $mu)
                && preg_match('#<(?:\w+:)?Password\b[^>]*>(.*?)<#is', $raw, $mp)) {
                $given = [trim($mu[1]), trim($mp[1])];
            }
        }

        return $u !== '' && $p !== '' && hash_equals($u, $given[0]) && hash_equals($p, $given[1]);
    }
}
