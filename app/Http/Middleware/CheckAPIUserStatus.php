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
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }
        if (! $user->status) {
            return response()->json([
                'inactive' => 'Your account is currently inactive. Contact your administrator for assistance',
            ], 403);
        } else {
            $sacco = Sacco::where('id', $user->sacco_id)->first();

            if ($sacco != null) {
                if (! $sacco->status) {
                    return response()->json([
                        'inactive' => 'Your account has been linked to inactive sacco '.$sacco->name.'. Contact your administrator for assistance',
                    ], 403); // 403 Forbidden
                }
            }
        }

        return $next($request);
    }
}
