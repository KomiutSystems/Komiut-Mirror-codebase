<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

/**
 * What the receiving mail service concluded about one email before handing
 * it over: virus scan, spam, and whether the sender is who the From says
 * (SPF, DKIM, DMARC). Values as SES writes them -- PASS, FAIL, GRAY,
 * PROCESSING_FAILED, DISABLED -- or null when the transport gives none.
 *
 * SES IS THE VIRUS SCANNER. The platform runs none of its own (see
 * AttachmentStore), so on the SES path an attachment is kept only when SES
 * scanned the email and found it clean; that needs ScanEnabled=true on the
 * receipt rule, or every attachment is left out with a note. A transport
 * that reports no verdicts at all (fromSes() was never called) keeps
 * attachments under AttachmentStore's type checks alone -- the documented
 * limitation for anything but SES.
 */
final class ReceiptVerdicts
{
    public function __construct(
        public readonly ?string $virus = null,
        public readonly ?string $spam = null,
        public readonly ?string $spf = null,
        public readonly ?string $dkim = null,
        public readonly ?string $dmarc = null,
        /** True when the transport scans for viruses, so a missing PASS means "not clean". */
        public readonly bool $scanned = false,
    ) {}

    /** @param  array<string, mixed>  $receipt  an SES "Received" notification's receipt */
    public static function fromSes(array $receipt): self
    {
        $status = fn (string $key): ?string => is_string($receipt[$key]['status'] ?? null) ? strtoupper($receipt[$key]['status']) : null;

        return new self(
            virus: $status('virusVerdict'),
            spam: $status('spamVerdict'),
            spf: $status('spfVerdict'),
            dkim: $status('dkimVerdict'),
            dmarc: $status('dmarcVerdict'),
            scanned: true,
        );
    }

    /**
     * Why attachments must not be kept, or null when they may be. On a
     * scanning transport only a clean PASS keeps them; config
     * team_inbox.inbound.require_virus_pass=false relaxes that to "not FAIL"
     * for a receipt rule that cannot scan.
     */
    public function attachmentsRefused(): ?string
    {
        if (! $this->scanned) {
            return null;
        }

        if ($this->virus === 'FAIL') {
            return 'the mail service found a virus in this email';
        }

        if ($this->virus !== 'PASS' && config('team_inbox.inbound.require_virus_pass', true)) {
            return 'not scanned for viruses ('.($this->virus ?? 'no verdict').')';
        }

        return null;
    }

    /**
     * Did the sender's own domain vouch for the From address? Only a DMARC
     * PASS says so: it needs SPF or DKIM to pass AND be aligned with the From
     * domain. SPF on its own checks the envelope sender, which a forger
     * chooses, and DKIM on its own may be the forger's domain -- so a domain
     * with no DMARC policy (GRAY) proves nothing either way. A transport with
     * no verdicts at all (not SES) is taken as it comes.
     */
    public function senderVerified(): bool
    {
        // A failure anywhere outranks a PASS: verdicts that disagree are not proof.
        return ! $this->scanned || ($this->dmarc === 'PASS' && ! $this->senderFailed());
    }

    /** The sender checks actively failed: the From is likely forged. */
    public function senderFailed(): bool
    {
        return $this->scanned && ($this->dmarc === 'FAIL' || ($this->spf === 'FAIL' && $this->dkim === 'FAIL'));
    }

    /** Anyone can put a SACCO admin's address in From; this is "we cannot say it is them". */
    public function senderUnverified(): bool
    {
        return ! $this->senderVerified();
    }

    public function spam(): bool
    {
        return $this->spam === 'FAIL';
    }

    /** @return array<string, ?string> for log lines */
    public function toLog(): array
    {
        return ['virus' => $this->virus, 'spam' => $this->spam, 'spf' => $this->spf, 'dkim' => $this->dkim, 'dmarc' => $this->dmarc];
    }
}
