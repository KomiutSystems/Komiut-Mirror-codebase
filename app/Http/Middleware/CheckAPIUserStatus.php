<?php

namespace App\Http\Middleware;

use App\Models\Sacco;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckAPIUserStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user == null) {
            // 'Unauthenticated.' is framework vocabulary; a driver whose 24-hour
            // shift token lapsed mid-shift cannot act on it. `message` keeps the
            // shape Laravel itself returns, so nothing matching on it breaks.
            return response()->json([
                'message' => 'Your session has ended. Sign in again.',
                'error' => 'Your session has ended. Sign in again.',
            ], 401);
        }
        if (! $user->status) {
            // `inactive` is kept because clients read it today, but it was the
            // ONLY key here — and nothing else in the API uses it, so any client
            // looking for `error` or `message` found neither and rendered a blank
            // failure on every request a suspended user made.
            return response()->json([
                'message' => 'Your account has been switched off. Ask your SACCO office to switch it back on.',
                'error' => 'Your account has been switched off. Ask your SACCO office to switch it back on.',
                'inactive' => 'Your account has been switched off. Ask your SACCO office to switch it back on.',
            ], 403);
        } else {
            $sacco = Sacco::where('id', $user->sacco_id)->first();

            if ($sacco != null) {
                if (! $sacco->status) {
                    $why = $sacco->name.' is switched off, so its accounts cannot be used. Your SACCO office needs to contact Komiut.';

                    return response()->json([
                        'message' => $why,
                        'error' => $why,
                        'inactive' => $why,
                    ], 403); // 403 Forbidden
                }
            }
        }

        return $next($request);
    }
}
