<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Safetek team inbox (super console /super/inbox)
|--------------------------------------------------------------------------
|
| The shared mailboxes the team answers from. Everything that reaches the
| outside world is OFF until product has the addresses and DNS settled:
| sending (send_enabled) and the inbound webhook (inbound.enabled) are each
| switched on separately, so mail can be received and read before anyone can
| answer from the console, or the other way round.
|
| How mail gets IN is not decided yet. The ingestion core
| (App\Services\Team\Inbox\InboundMail) takes a raw RFC 5322 message and does
| not care how it arrived; the one entry point built today is AWS SES receipt
| -> SNS (HTTPS) with the email inline. Another transport (an IMAP poll of the
| Private Email mailbox, a forwarding service's webhook) is a new caller of
| InboundMail::ingest(), nothing more.
*/

$mailboxes = json_decode((string) env('TEAM_INBOX_MAILBOXES', '[]'), true);
// Read right after the decode: a typo in the JSON must stop the sync command,
// not look like "no mailboxes" and switch every one of them off.
$mailboxesValid = json_last_error() === JSON_ERROR_NONE && is_array($mailboxes) && array_is_list($mailboxes);

return [

    /*
    | The mailboxes, as a JSON list:
    |   TEAM_INBOX_MAILBOXES='[{"address":"support@komiut.com","name":"Komiut Support","brand":"komiut"}]'
    | brand: "komiut" | "safiri" | null (both). Applied to the database by
    | `php artisan team:inbox-sync-mailboxes`; a mailbox dropped from this list
    | is switched off there only with --deactivate-missing, never deleted --
    | its threads stay readable.
    */
    'mailboxes' => $mailboxesValid ? $mailboxes : [],

    // False when TEAM_INBOX_MAILBOXES is not a JSON list; the sync command refuses to run.
    'mailboxes_valid' => $mailboxesValid,

    /*
    | Replies and new emails from the console. While false the console's send
    | buttons answer 409 and nothing is stored.
    |
    | Production mail goes out through Namecheap Private Email over SMTP as
    | info@komiut.com. Sending as another address (support@…) needs that
    | address to exist as a mailbox or alias the SMTP login may send as --
    | Private Email refuses or rewrites a From it does not own -- and SPF/DKIM
    | for its domain. Check both before switching this on.
    */
    'send_enabled' => (bool) env('TEAM_INBOX_SEND_ENABLED', false),

    // Which config/mail.php mailer sends; null = mail.default.
    'mailer' => env('TEAM_INBOX_MAILER'),

    /*
    | Put the thread's token in our Reply-To (support+TOKEN@komiut.com), so an
    | answer whose mail client dropped In-Reply-To/References still files into
    | its thread. Only switch on once the receiving side delivers plus-addressed
    | mail to the base mailbox -- otherwise customers' replies bounce.
    */
    'reply_to_plus_addressing' => (bool) env('TEAM_INBOX_REPLY_TO_PLUS', false),

    // Per team member, per minute: replies and new emails together.
    'send_per_minute' => (int) env('TEAM_INBOX_SEND_PER_MINUTE', 30),

    // To + Cc on one email.
    'max_recipients' => 20,

    'inbound' => [
        // POST /api/v1/team-inbox/ses answers 404 until this is on.
        'enabled' => (bool) env('TEAM_INBOX_INBOUND_ENABLED', false),

        // SNS topics allowed to deliver mail (and to be subscribed to), comma separated.
        'sns_topic_arns' => array_values(array_filter(array_map('trim', explode(',', (string) env('TEAM_INBOX_SNS_TOPIC_ARNS', ''))))),

        // An SNS notification is at most 256 KB; anything bigger is not from SNS.
        'max_request_bytes' => 300 * 1024,

        /*
        | SES's virus scan is the only one there is (see ReceiptVerdicts), so
        | an attachment is kept only when SES says PASS -- ScanEnabled=true on
        | the receipt rule. False keeps attachments SES did not scan (still
        | never one it found a virus in); only for a rule that cannot scan.
        */
        'require_virus_pass' => (bool) env('TEAM_INBOX_REQUIRE_VIRUS_PASS', true),
    ],

    'limits' => [
        // Stored body, in characters. Longer bodies are cut with a note.
        'body_max_chars' => 100_000,
        'attachment_max_bytes' => (int) env('TEAM_INBOX_ATTACHMENT_MAX_BYTES', 5 * 1024 * 1024),
        'attachments_total_max_bytes' => (int) env('TEAM_INBOX_ATTACHMENTS_TOTAL_MAX_BYTES', 15 * 1024 * 1024),
        'attachments_max_count' => 10,
    ],

    /*
    | Attachment types kept, by file extension, with the Content-Type they are
    | served as. The sender's own Content-Type is never trusted or echoed back:
    | a download is always this type, as an attachment, with nosniff.
    | Anything else (executables, archives, HTML, SVG …) is skipped with a note
    | in the message body.
    */
    'attachment_types' => [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    ],

    // How long a signed attachment link from the console works.
    'download_url_minutes' => 10,
];
