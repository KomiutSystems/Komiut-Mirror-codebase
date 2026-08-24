<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;

/**
 * One shape for a rejected request, and one place to change it.
 *
 * Two things were wrong with what this replaces. The envelope carried only
 * `errors`, a map of field to messages, which a client has to know to dig into
 * — so a form that renders a generic banner had nothing to put in it and showed
 * the user an empty screen. And every message inside was a Laravel default:
 * "The phone has already been taken." is accurate and useless, because it does
 * not tell a person what to do next.
 *
 * The envelope now carries BOTH:
 *
 *     {
 *       "message": "This phone number is already registered. Sign in instead, or use a different number.",
 *       "errors":  { "phone": ["This phone number is already registered. ..."] }
 *     }
 *
 * `errors` keeps its exact old shape and key, so nothing that reads it breaks.
 * `message` is additive: it is the first field error, ready to drop straight
 * into a banner or a toast without the client knowing any field names. This is
 * also the shape Laravel itself returns for an unhandled ValidationException,
 * so a client written against the framework's convention already understands it.
 *
 * STATUS: the codebase returns 400 for validation in 56 places. 422 is the
 * conventional code and what Laravel would send on its own, but changing it is
 * a contract change for every existing client at once — so it lives here as a
 * single constant rather than being scattered, and can be flipped in one edit
 * once the clients are known to handle it.
 */
trait ExplainsFailures
{
    /**
     * The status returned when a request fails validation.
     *
     * Deliberately still 400, matching the other 55 sites. Flip to 422 here and
     * every endpoint using this trait moves together.
     */
    private static int $validationStatus = 400;

    /** A rejected request: the same `errors` map as before, plus a readable summary. */
    protected function invalid(Validator $validator): JsonResponse
    {
        $errors = $validator->messages();

        return response()->json([
            'message' => $this->firstMessage($errors->toArray()),
            'errors' => $errors,
        ], self::$validationStatus);
    }

    /**
     * A single-field rejection raised by hand rather than by the validator —
     * a phone that will not normalise, say. Same envelope, so a client cannot
     * tell the two apart and does not need to.
     */
    protected function invalidField(string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], self::$validationStatus);
    }

    /**
     * The first message, for clients that show one line.
     *
     * Field order follows the rules as declared, so the summary names the first
     * thing wrong going down the form rather than an arbitrary one.
     */
    private function firstMessage(array $errors): string
    {
        foreach ($errors as $messages) {
            foreach ((array) $messages as $message) {
                if (is_string($message) && $message !== '') {
                    return $message;
                }
            }
        }

        return 'Some of the details you entered need fixing.';
    }
}
