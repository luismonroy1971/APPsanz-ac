<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternal
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isInternal(), 403, 'Acceso reservado al personal de SANZ & AC.');

        return $next($request);
    }
}
