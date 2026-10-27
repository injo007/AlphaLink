<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                throw new AuthenticationException('This account is disabled. Contact your administrator.');
            }

            return redirect()->route('login')->withErrors([
                'email' => 'This account is disabled. Contact your administrator.',
            ]);
        }

        if ($user !== null && ($user->seen_at === null || $user->seen_at->lt(now()->subMinute()))) {
            $user->timestamps = false;
            $user->forceFill(['seen_at' => now()])->save();
            $user->timestamps = true;
        }

        return $next($request);
    }
}
