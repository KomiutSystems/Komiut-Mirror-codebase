<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The monthly collections statement sent to a financier bank.
 *
 * The bank finances these matatus and reconciles repayments against what each
 * one collected, so the figures here are the basis of a money decision — the
 * rows are attached as CSV rather than only rendered, because a bank works from
 * a spreadsheet, not an email body.
 */
class BankCollectionsStatement extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<string,mixed>  $totals
     */
    public function __construct(
        public string $bankLabel,
        public string $periodLabel,
        public array $rows,
        public array $totals,
        public string $csv,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Komiut collections statement — {$this->bankLabel} — {$this->periodLabel}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.bank_collections_statement');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $name = 'komiut-collections-'.str_replace(' ', '-', strtolower($this->bankLabel)).'-'.$this->periodLabel.'.csv';

        return [
            Attachment::fromData(fn () => $this->csv, $name)->withMime('text/csv'),
        ];
    }
}
