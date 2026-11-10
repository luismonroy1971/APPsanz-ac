<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limita a los usuarios externos según los permisos acordados en la
 * "coordinación previa" (contrato): subir documentos, chat, ver informes.
 * El personal interno no se ve afectado.
 */
class ClientPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if ($user && $user->isExternal() && ! $user->hasPermissionTo($permission)) {
            abort(403, 'Su usuario no tiene habilitada esta funcionalidad. Coordine con SANZ & AC.');
        }

        return $next($request);
    }
}
