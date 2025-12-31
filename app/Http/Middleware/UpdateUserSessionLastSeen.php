<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\UserSession;
use Illuminate\Http\Request;

class UpdateUserSessionLastSeen
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
     public function handle(Request $request, Closure $next)
    {
        if ($user = $request->user()) {

            $tokenId = $user->currentAccessToken()?->id;

            if ($tokenId) {
                UserSession::where('access_token_id', $tokenId)
                    ->update([
                        'last_seen_at' => now(),
                        'status'       => 'active',
                    ]);
            }
        }

        return $next($request);
    }
}
