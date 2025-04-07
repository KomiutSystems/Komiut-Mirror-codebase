<?php

namespace App\Http\Middleware;

use App\Models\Sacco;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (!$user->status) {
            return redirect('dashboard/status');
        } else {
            $sacco = Sacco::where('id', $user->sacco_id)->first();
            if ($sacco != null) {
                if (!$sacco->status) {
                    return redirect('dashboard/status');
                }
            }
        }
        return $next($request);
    }
}
