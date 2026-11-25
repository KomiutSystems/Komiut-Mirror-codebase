<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Middleware\CallbackSourceAllowlist;
use App\Models\Mpesa;
use App\Models\RefusedCallback;
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
 * Every receiver de-duplicates by transaction id, so replaying a row twice
 * records the payment once. A row counts as done only when the replay is
 * VERIFIED -- for a payment, its transaction is then in mpesas -- because the
 * receivers answer 200 even when they record nothing. A failed row keeps
 * replayed_at empty, says why in replay_note, and can be replayed again.
 */
class ReplayRefusedCallbacks extends Command
{
    protected $signature = 'payments:replay-refused
        {ids?* : refused_callbacks ids to replay}
        {--ip= : replay every not-yet-replayed row from this address}
        {--dry-run : list what would be replayed, change nothing}';

    protected $description = 'Record payment notifications refused for their sender address, once the sender is confirmed genuine';

    public function handle(HttpKernel $kernel): int
    {
        $query = RefusedCallback::query()->whereNull('replayed_at')->orderBy('id');
        $ids = array_map('intval', (array) $this->argument('ids'));
        $ip = $this->option('ip');

        if ($ids === [] && ! $ip) {
            $this->error('Name the rows: ids, or --ip=<address>.');

            return self::INVALID;
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

            $problem = $this->problemWith($row, $response);
            $row->forceFill([
                'replay_status' => $response->getStatusCode(),
                'replay_note' => $problem === null ? 'recorded' : mb_substr($problem, 0, 500),
                'replayed_at' => $problem === null ? Carbon::now() : null,
            ])->save();

            if ($problem === null) {
                $this->line("#{$row->id} {$row->path}: recorded");
            } else {
                $failed++;
                $this->error("#{$row->id} {$row->path}: NOT recorded -- {$problem}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Why this replay did not record the payment, or null when it did. */
    private function problemWith(RefusedCallback $row, Response $response): ?string
    {
        $status = $response->getStatusCode();
        $content = (string) $response->getContent();
        if ($status < 200 || $status >= 300) {
            return "HTTP {$status}: ".mb_substr($content, 0, 200);
        }
        if (preg_match('/"ResultCode"\s*:\s*"?1/', $content) || $content === 'FAIL') {
            return 'the receiver declined it: '.mb_substr($content, 0, 200);
        }

        // Validation, pull-registration and billing callbacks record no M-Pesa
        // row of their own: an accepted answer is all there is to check.
        if (preg_match('#/(validation|pull/callback|billing/)#', $row->path)) {
            return null;
        }

        $ref = $this->transactionRef((string) $row->body);
        if ($ref === null) {
            return 'no transaction id in the kept body to verify against';
        }

        return Mpesa::withoutGlobalScopes()->where('TransID', $ref)->exists()
            ? null
            : "answered {$status} but no payment {$ref} was recorded (a bank debit, or a failure -- see the receiver's log)";
    }

    private function transactionRef(string $body): ?string
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            if (filled($json['TransID'] ?? null)) {
                return trim((string) $json['TransID']);
            }
            // Co-op: the receipt leads the narration ("UKP444A6K3~6624891~2547...").
            if (filled($json['Narration'] ?? null)) {
                return trim(explode('~', (string) $json['Narration'])[0]);
            }

            return null;
        }

        return preg_match('#<(?:\w+:)?TransID>([^<]+)<#i', $body, $m) ? trim($m[1]) : null;
    }

    /**
     * NCBA's confirmation_new receiver signs NCBA in with its username and
     * password, which were stripped before the row was kept. When the refused
     * post carried the right ones, put the configured ones back.
     */
    private function withCredentials(RefusedCallback $row): string
    {
        $body = (string) $row->body;
        // Only a post that carried NCBA's own credentials is signed back in.
        // Anything else is replayed as it came, and the receiver refuses it as
        // it always would have.
        if ($row->credentials_ok !== true || ! str_contains($row->path, 'confirmation_new')) {
            return $body;
        }

        $user = (string) config('services.ncba.username');
        $pass = (string) config('services.ncba.password');
        $json = json_decode($body, true);
        if (is_array($json)) {
            return (string) json_encode(['Username' => $user, 'Password' => $pass] + $json);
        }

        return (string) preg_replace_callback(
            '#<((?:\w+:)?(Username|Password))>\*\*\*</\1>#i',
            fn (array $m) => '<'.$m[1].'>'.htmlspecialchars(strcasecmp($m[2], 'Username') === 0 ? $user : $pass, ENT_XML1).'</'.$m[1].'>',
            $body,
        );
    }
}
