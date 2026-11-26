<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

use App\Models\Team\TeamMessageAttachment;
use Illuminate\Support\Facades\DB;

/**
 * Which attachments are kept, and their bytes in and out of the database.
 *
 * IN THE DATABASE, NOT ON DISK OR S3. Two app hosts serve the API and neither
 * can read the other's disk; the configured S3 disk cannot be used (no AWS SDK
 * or Flysystem S3 adapter is installed). So the bytes go in
 * team_message_attachments.content, which every host reads -- capped per file
 * and per message so the table cannot be grown without bound by one sender.
 *
 * NO VIRUS SCANNER OF OUR OWN. Nothing on the platform scans files (no
 * ClamAV, no scanning service). The scan is the receiving mail service's:
 * on the SES path InboundMail keeps attachments only when SES's virusVerdict
 * is PASS (ReceiptVerdicts -- ops must set ScanEnabled=true on the receipt
 * rule). A transport that reports no verdict gets no scan, and the defence
 * there is only what is kept and how it is served: the document and image
 * types in config('team_inbox.attachment_types'), judged by extension AND by
 * what the bytes actually are (a .pdf that is a Windows executable is
 * refused); and a download is always Content-Disposition: attachment with
 * the type we choose and nosniff, so a browser never runs or renders a file
 * inside the console. A scanner of our own would belong in accept(), before
 * anything is stored.
 */
final class AttachmentStore
{
    /**
     * What the bytes are, as libmagic sees them, that is never kept whatever
     * the file calls itself.
     */
    private const DANGEROUS = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml',
        'application/javascript', 'text/javascript', 'application/x-dosexec', 'application/x-msdownload',
        'application/x-executable', 'application/x-sharedlib', 'application/x-mach-binary',
        'application/x-elf', 'application/vnd.microsoft.portable-executable', 'application/x-sh',
        'text/x-shellscript', 'application/java-archive', 'application/x-msi',
    ];

    /**
     * Decide on each candidate in order, within the per-file, per-message and
     * count limits. Each file left out gets one line saying why, for the
     * message body.
     *
     * @param  list<array{name:string, bytes:string}>  $candidates
     * @return array{keep: list<array{name:string, mime:string, bytes:string}>, skipped: list<string>}
     */
    public function accept(array $candidates): array
    {
        $max = (int) config('team_inbox.limits.attachment_max_bytes');
        $totalMax = (int) config('team_inbox.limits.attachments_total_max_bytes');
        $maxCount = (int) config('team_inbox.limits.attachments_max_count');

        $keep = [];
        $skipped = [];
        $total = 0;

        foreach ($candidates as $file) {
            $name = self::safeName($file['name']);
            $size = strlen($file['bytes']);
            $mime = $this->typeFor($name, $file['bytes']);

            $reason = match (true) {
                $mime === null => 'file type not accepted',
                $size === 0 => 'empty file',
                $size > $max => 'larger than '.self::human($max),
                $total + $size > $totalMax => 'attachments over '.self::human($totalMax).' in total',
                count($keep) >= $maxCount => 'more than '.$maxCount.' attachments',
                default => null,
            };

            if ($reason !== null) {
                $skipped[] = $name.' ('.self::human($size).'): '.$reason;

                continue;
            }

            $total += $size;
            $keep[] = ['name' => $name, 'mime' => $mime, 'bytes' => $file['bytes']];
        }

        return ['keep' => $keep, 'skipped' => $skipped];
    }

    /**
     * The type this file is served as, or null when it is not kept.
     * By extension, then checked against what the bytes really are.
     */
    public function typeFor(string $name, string $bytes): ?string
    {
        $types = (array) config('team_inbox.attachment_types');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = $types[$ext] ?? null;

        if ($mime === null || $bytes === '') {
            return $mime;
        }

        $actual = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        if (in_array($actual, self::DANGEROUS, true)) {
            return null;
        }

        // An image must be an image: a browser that ignored nosniff would
        // render whatever it really is.
        if (str_starts_with($mime, 'image/') && $ext !== 'heic' && ! str_starts_with($actual, 'image/')) {
            return null;
        }

        return $mime;
    }

    /** Store one file against a message. */
    public function store(int $messageId, string $name, string $mime, string $bytes): int
    {
        // bytea through PDO: bound as text, raw bytes would be cut at the
        // first NUL and refused when they are not valid UTF-8. Base64 in,
        // decode() on the server, is lossless for any file. (Postgres only,
        // like the rest of production.)
        $row = DB::selectOne(
            "INSERT INTO team_message_attachments (message_id, name, mime, size, sha256, content, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, decode(?, 'base64'), ?, ?) RETURNING id",
            [$messageId, $name, $mime, strlen($bytes), hash('sha256', $bytes), base64_encode($bytes), now(), now()],
        );

        return (int) $row->id;
    }

    /** The bytes of one attachment. */
    public function bytes(TeamMessageAttachment $attachment): string
    {
        $row = DB::selectOne("SELECT encode(content, 'base64') AS b64 FROM team_message_attachments WHERE id = ?", [$attachment->id]);

        return base64_decode((string) ($row->b64 ?? ''), true) ?: '';
    }

    /** A file name with no path, no control characters, nothing a header could split on. */
    public static function safeName(?string $name): string
    {
        $name = basename(str_replace('\\', '/', (string) $name));
        $name = preg_replace('/[\x00-\x1F\x7F"\/\\\\]+/u', '', $name) ?? '';
        $name = trim($name, " .\t");

        if ($name === '') {
            return 'attachment';
        }

        // Keep the extension when shortening: it decides how the file is kept.
        if (mb_strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 190).($ext !== '' ? '.'.mb_substr($ext, 0, 8) : '');
        }

        return $name;
    }

    public static function human(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 * 1024 => round($bytes / 1024 / 1024, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' bytes',
        };
    }
}
