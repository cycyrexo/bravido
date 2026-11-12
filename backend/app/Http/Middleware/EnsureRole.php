<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Usage : ->middleware('role:admin,moderateur') */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || $user->statut !== 'actif' || ! in_array($user->role, $roles, true)) {
            if (! $request->expectsJson() && $user && ! $user->isStaff()) {
                Auth::guard('web')->logout();

                return redirect()->route('admin.login')->withErrors(['email' => 'Accès réservé à l\'équipe BRAVIDO.']);
            }
            abort(403, 'Accès refusé.');
        }

        return $next($request);
    }
}
