<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Middleware\CallbackSourceAllowlist;
use App\Models\RefusedCallback;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
 * records the payment once.
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

            $row->forceFill(['replayed_at' => Carbon::now(), 'replay_status' => $response->getStatusCode()])->save();
            $this->line("#{$row->id} {$row->path} -> {$response->getStatusCode()} ".mb_substr((string) $response->getContent(), 0, 160));
        }

        return self::SUCCESS;
    }

    /**
     * NCBA's confirmation_new receiver signs NCBA in with its username and
     * password, which were stripped before the row was kept. Put the
     * configured ones back so the receiver accepts the replay.
     */
    private function withCredentials(RefusedCallback $row): string
    {
        $body = (string) $row->body;
        if (! str_contains($row->source, 'ncba') || ! str_contains($row->path, 'confirmation_new')) {
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
