<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpFoundation\Response;

/** Accept historical absolute links and host-independent campaign tracking links. */
final class ValidateCampaignSignature
{
    /** @param Closure(Request): (Response) $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasValidSignature() && ! $request->hasValidSignatureWhileIgnoring([], false)) {
            throw new InvalidSignatureException;
        }

        return $next($request);
    }
}
