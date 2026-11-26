<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

use App\Models\Team\TeamMailbox;
use Illuminate\Support\Collection;

/**
 * Email addresses as the inbox compares and writes them.
 *
 * Compared lowercased (see App\Support\Email for why the whole platform does),
 * and with any plus-tag removed when the question is "which mailbox": a reply
 * to support+TOKEN@komiut.com was sent to support@komiut.com.
 */
final class Addresses
{
    /** The comparable form, or null for anything that is not an address. */
    public static function normalise(?string $address): ?string
    {
        $address = mb_strtolower(trim((string) $address, " \t\r\n<>\"'"));

        return self::valid($address) ? $address : null;
    }

    /**
     * A single, plain address safe to put in a header: RFC-valid and free of
     * anything that could start a new header line.
     */
    public static function valid(?string $address): bool
    {
        $address = (string) $address;

        return $address !== ''
            && strlen($address) <= 254
            && ! preg_match('/[\r\n\0,;<>"]/', $address)
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** support+abc@komiut.com -> support@komiut.com */
    public static function base(string $address): string
    {
        return preg_replace('/^([^@+]+)\+[^@]*@/', '$1@', mb_strtolower($address)) ?? $address;
    }

    /** The plus-tag of support+abc@komiut.com ("abc"), or null. */
    public static function tag(string $address): ?string
    {
        return preg_match('/^[^@+]+\+([^@]+)@/', mb_strtolower($address), $m) === 1 ? $m[1] : null;
    }

    public static function domain(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? 'localhost' : mb_strtolower(substr($address, $at + 1));
    }

    /** The mailbox with a thread's token in its local part: support+TOKEN@komiut.com. */
    public static function plus(string $mailbox, string $token): string
    {
        [$local, $domain] = explode('@', $mailbox, 2) + [1 => 'localhost'];

        return $local.'+'.$token.'@'.$domain;
    }

    /**
     * The first active mailbox any of these addresses was sent to.
     *
     * @param  iterable<?string>  $addresses  in order of preference
     */
    public static function mailboxFor(iterable $addresses): ?TeamMailbox
    {
        $wanted = collect($addresses)->map(fn ($a) => self::normalise($a))->filter()->map(fn ($a) => self::base($a))->unique()->values();
        if ($wanted->isEmpty()) {
            return null;
        }

        /** @var Collection<string, TeamMailbox> $active */
        $active = TeamMailbox::query()->where('is_active', true)->get()
            ->keyBy(fn (TeamMailbox $m) => mb_strtolower($m->address));

        foreach ($wanted as $address) {
            if ($active->has($address)) {
                return $active->get($address);
            }
        }

        // name@<receiving domain> is the forwarded copy of the mailbox called
        // name@ -- if exactly one active mailbox has that name.
        $receiving = (array) config('team_inbox.inbound.receiving_domains', []);
        foreach ($wanted as $address) {
            if (! in_array(self::domain($address), $receiving, true)) {
                continue;
            }
            $local = strstr($address, '@', true);
            $named = $active->filter(fn (TeamMailbox $m, string $a) => strstr($a, '@', true) === $local);
            if ($named->count() === 1) {
                return $named->first();
            }
        }

        return null;
    }

    /** One header-safe line: no CR/LF/NUL, trimmed, capped. */
    public static function line(?string $value, int $max): string
    {
        return mb_substr(trim(preg_replace('/[\r\n\0]+/', ' ', (string) $value) ?? ''), 0, $max);
    }
}
