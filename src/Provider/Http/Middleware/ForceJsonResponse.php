<?php

namespace Jma\Connect\Provider\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hosts that never registered routes/api.php may not render auth failures
 * as JSON for our paths; forcing the Accept header makes the framework do so.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
