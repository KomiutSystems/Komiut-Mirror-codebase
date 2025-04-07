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
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Check if the user is inactive and exists in the banned table
        if ($user && $user->status == 0) {
            $sacco = Sacco::where('id', $user->sacco_id)->first();

            if ($sacco != null) {
                if(!$sacco->status){
                return response()->json([
                    'inactive' => "Your account has been linked to inactive sacco ".$sacco->name.". Contact your administrator for assistance",
                ], 403); // 403 Forbidden
            }
            }else{
                return response()->json([
                    'inactive' => 'Your account has been deactivated. Please contact support for assistance.',
                ], status: 403); // 403 Forbidden
            }
        }
        return $next($request);
    }
}
