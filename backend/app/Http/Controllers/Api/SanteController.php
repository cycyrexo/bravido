<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SanteSysteme;
use Illuminate\Http\JsonResponse;

/** GET /sante — public, minimal (booléens seulement) : sert à la supervision externe et au script surveillance.ps1. */
class SanteController extends Controller
{
    public function __invoke(SanteSysteme $sante): JsonResponse
    {
        $r = $sante->resume();

        return response()->json($r, $r['db'] ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
