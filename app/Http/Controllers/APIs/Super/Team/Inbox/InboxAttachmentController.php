<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Team\Inbox;

use App\Http\Controllers\Controller;
use App\Models\Team\TeamMessageAttachment;
use App\Services\Team\Inbox\AttachmentStore;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * GET /api/v1/team-inbox/attachments/{attachment}?expires=…&signature=…
 *
 * Outside the auth middleware because a browser following a link cannot send
 * the console's bearer token; the `signed` middleware is the permission. The
 * link comes only from an opened thread (InboxPresenter::downloadUrl) and
 * lasts minutes.
 *
 * Served so that nothing in the file can act inside a browser: always a
 * download (attachment, never inline), the type WE assigned from the
 * extension allowlist rather than the sender's, nosniff, and a CSP that
 * forbids everything should a browser render it anyway. There is no virus
 * scanner on the platform -- see AttachmentStore.
 */
class InboxAttachmentController extends Controller
{
    /**
     * The id is looked up here, not by route-model binding: binding runs
     * before the signature check, and would tell an unsigned caller which
     * ids exist (404 against 403).
     */
    public function __invoke(string $attachment, AttachmentStore $store): Response
    {
        $attachment = TeamMessageAttachment::query()->findOrFail((int) $attachment, ['id', 'name', 'mime', 'size']);
        $name = AttachmentStore::safeName($attachment->name);
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?: 'attachment';
        $ascii = str_replace('%', '_', $ascii);

        return response($store->bytes($attachment), 200, [
            'Content-Type' => $this->safeType($attachment->mime),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $ascii),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** Only a type from our own allowlist is ever echoed; anything else is bytes. */
    private function safeType(?string $mime): string
    {
        return in_array($mime, array_values((array) config('team_inbox.attachment_types')), true)
            ? (string) $mime
            : 'application/octet-stream';
    }
}
