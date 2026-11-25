<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Middleware\CallbackSourceAllowlist;
use App\Models\InvoicePayment;
use App\Models\Mpesa;
use App\Models\RefusedCallback;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Record payment notifications that were refused for their sender address.
 *
 * CallbackSourceAllowlist keeps every refused POST. When the console reports
 * one and a person has confirmed the sender is genuine (a new Safaricom or bank
 * address -- check with them, or against the till's statement), this sends the
 * kept request back through the same route and receiver it was meant for, past
 * the address check only. Then add the address to the sender's list
 * (SAFARICOM_SOURCE_IPS / COOP_SOURCE_IPS / NCBA_SOURCE_IPS).
 *
 * Every receiver de-duplicates by transaction id, so replaying a row again
 * records the payment once -- and a second run completes a payment whose first
 * run stopped half-way. A row counts as done only when the replay is VERIFIED
 * the way its receiver records: a fare has its mpesas row AND its transaction,
 * an invoice payment its invoice_payments row. The receivers answer 200 even
 * when they record nothing, so the answer alone proves nothing. A failed row
 * keeps replayed_at empty, says why in replay_note, and is picked up again.
 */
class ReplayRefusedCallbacks extends Command
{
    protected $signature = 'payments:replay-refused
        {ids?* : refused_callbacks ids to replay}
        {--ip= : replay every not-yet-done row from this address}
        {--again : include rows already marked done (named ids only)}
        {--dry-run : list what would be replayed, change nothing}';

    protected $description = 'Record payment notifications refused for their sender address, once the sender is confirmed genuine';

    public function handle(HttpKernel $kernel): int
    {
        $ids = array_map('intval', (array) $this->argument('ids'));
        $ip = $this->option('ip');

        if ($ids === [] && ! $ip) {
            $this->error('Name the rows: ids, or --ip=<address>.');

            return self::INVALID;
        }

        $query = RefusedCallback::query()->orderBy('id');
        if (! ($this->option('again') && $ids !== [])) {
            $query->whereNull('replayed_at');
        }
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        if ($ip) {
            $query->where('ip', $ip);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->info('Nothing to replay.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($rows as $row) {
            if ($this->option('dry-run')) {
                $this->line("#{$row->id} {$row->created_at} {$row->source} from {$row->ip}: {$row->method} {$row->path}");

                continue;
            }

            $request = Request::create(
                'https://'.($row->host ?: 'localhost').$row->path,
                $row->method,
                server: ['CONTENT_TYPE' => $row->content_type ?: 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                content: $this->withCredentials($row),
            );
            $request->attributes->set(CallbackSourceAllowlist::REPLAY, true);

            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            [$done, $note] = $this->outcome($row, $response);
            $row->forceFill([
                'replay_status' => $response->getStatusCode(),
                'replay_note' => mb_substr($note, 0, 500),
                'replayed_at' => $done ? Carbon::now() : null,
            ])->save();

            if ($done) {
                $this->line("#{$row->id} {$row->path}: {$note}");
            } else {
                $failed++;
                $this->error("#{$row->id} {$row->path}: NOT recorded -- {$note}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Did the replay do what the receiver does with a real delivery?
     *
     * @return array{0: bool, 1: string}
     */
    private function outcome(RefusedCallback $row, Response $response): array
    {
        $status = $response->getStatusCode();
        $content = (string) $response->getContent();
        if ($status < 200 || $status >= 300) {
            return [false, "HTTP {$status}: ".mb_substr($content, 0, 200)];
        }
        if (preg_match('/"ResultCode"\s*:\s*"?1/', $content) || trim($content) === 'FAIL') {
            return [false, 'the receiver declined it: '.mb_substr($content, 0, 200)];
        }

        $path = parse_url($row->path, PHP_URL_PATH) ?: $row->path;

        // These record nothing of their own that could be looked up: an
        // accepted answer is all there is.
        if (preg_match('#/(validation/\d+|pull/callback/\d+|billing/mpesa/validation|coop/stk/response)$#', $path)) {
            return [true, 'accepted'];
        }

        $fields = $this->fields((string) $row->body);

        // A Co-op account movement that is not a credit is the bank's own
        // debit: acknowledged and logged by the receiver, never a payment.
        if (preg_match('#/coop/#', $path) && strtoupper(trim((string) ($fields['EventType'] ?? 'CREDIT'))) !== 'CREDIT') {
            return [true, 'a bank debit: nothing to record'];
        }

        $ref = $this->transactionRef($path, $fields, (string) $row->body);
        if ($ref === null) {
            return [false, 'no transaction id in the kept body to verify against'];
        }

        if (str_contains($path, 'billing/mpesa/confirmation')) {
            return InvoicePayment::withoutGlobalScopes()->where('mpesa_receipt', $ref)->exists()
                ? [true, "invoice payment {$ref} recorded"]
                : [false, "answered {$status} but no invoice payment {$ref} was recorded (no matching invoice?)"];
        }

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', $ref)->first();
        if ($mpesa === null) {
            return [false, "answered {$status} but no payment {$ref} was recorded -- see the receiver's log"];
        }
        if (! Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->exists()) {
            return [false, "payment {$ref} was saved but not added to any takings -- run again to complete it"];
        }

        return [true, "payment {$ref} recorded"];
    }

    /** @return array<string, mixed> */
    private function fields(string $body): array
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            return $json;
        }
        parse_str($body, $form);

        return array_filter($form, fn ($k) => is_string($k) && ! str_contains($k, '<'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * The reference the receiver records the payment under, by its own rule.
     *
     * @param  array<string, mixed>  $fields
     */
    private function transactionRef(string $path, array $fields, string $body): ?string
    {
        if (preg_match('#/coop/#', $path) && filled($fields['Narration'] ?? null)) {
            // CoopRestPaymentsController: the receipt leads a fare's narration
            // ("UKP444A6K3~6624891~2547..."); a credit in prose is filed under
            // the bank's own id for the movement.
            $narration = array_map('trim', explode('~', (string) $fields['Narration']));

            return count($narration) > 1
                ? $narration[0]
                : (string) (($fields['TransactionId'] ?? null) ?: ($fields['PaymentRef'] ?? null) ?: $narration[0]);
        }
        if (filled($fields['TransID'] ?? null)) {
            return trim((string) $fields['TransID']);
        }
        if (filled($fields['receipt'] ?? null)) {
            return trim((string) $fields['receipt']); // billing's alternative spelling
        }

        $xml = html_entity_decode($body, ENT_QUOTES | ENT_XML1);

        return preg_match('#<(?:\w+:)?TransID\b[^>]*>\s*([^<\s]+)#i', $xml, $m) ? $m[1] : null;
    }

    /**
     * NCBA's confirmation_new receiver signs NCBA in with its username and
     * password, which were masked before the row was kept. When the refused
     * post carried the right ones, put the configured ones back. Anything else
     * is replayed as it came, and the receiver refuses it as it always would.
     */
    private function withCredentials(RefusedCallback $row): string
    {
        $body = (string) $row->body;
        if ($row->credentials_ok !== true) {
            return $body;
        }

        $user = (string) config('services.ncba.username');
        $pass = (string) config('services.ncba.password');
        $json = json_decode($body, true);
        if (is_array($json)) {
            return (string) json_encode(['Username' => $user, 'Password' => $pass] + $json, JSON_UNESCAPED_SLASHES);
        }

        $value = fn (string $name, bool $escaped) => strcasecmp($name, 'Username') === 0
            ? ($escaped ? htmlspecialchars(htmlspecialchars($user, ENT_XML1), ENT_XML1) : htmlspecialchars($user, ENT_XML1))
            : ($escaped ? htmlspecialchars(htmlspecialchars($pass, ENT_XML1), ENT_XML1) : htmlspecialchars($pass, ENT_XML1));

        $body = (string) preg_replace_callback('#<((?:\w+:)?(Username|Password))(\s[^>]*)?>\*\*\*</\1>#i',
            fn (array $m) => '<'.$m[1].($m[3] ?? '').'>'.$value($m[2], false).'</'.$m[1].'>', $body);
        $body = (string) preg_replace_callback('#&lt;((?:\w+:)?(Username|Password))(\s.*?)?&gt;\*\*\*&lt;/\1&gt;#i',
            fn (array $m) => '&lt;'.$m[1].($m[3] ?? '').'&gt;'.$value($m[2], true).'&lt;/'.$m[1].'&gt;', $body);

        return (string) preg_replace_callback('#(^|&)(Username|Password)=\*\*\*#i',
            fn (array $m) => $m[1].$m[2].'='.urlencode(strcasecmp($m[2], 'Username') === 0 ? $user : $pass), $body);
    }
}
