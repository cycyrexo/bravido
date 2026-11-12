<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bloque les comptes suspendus sur l'API. */
class EnsureCompteActif
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->statut === 'suspendu') {
            abort(403, 'Votre compte est suspendu. Contactez le service client BRAVIDO.');
        }

        return $next($request);
    }
}
