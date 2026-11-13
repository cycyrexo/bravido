<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\NotifierNouvelAudio;
use App\Models\Alerte;
use App\Models\AlerteAudio;
use App\Models\AlerteEvenement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Message vocal ajouté VOLONTAIREMENT par la personne en alerte (bouton explicite côté app).
 * Fichiers sur le disque privé, jamais servis directement : lecture via l'API, avec contrôle d'accès.
 */
class AlerteAudioController extends Controller
{
    public const MIMES = [
        'audio/aac', 'audio/x-aac', 'audio/x-hx-aac-adts', 'audio/mp4', 'audio/x-m4a', 'audio/m4a',
        'audio/ogg', 'application/ogg', 'audio/webm', 'video/webm', 'video/mp4',
    ];

    public const TAILLE_MAX_KO = 1536; // 1,5 Mo

    public const DUREE_MAX_S = 30;

    public const MAX_PAR_ALERTE = 5;

    /** POST /alertes/{alerte}/audio (multipart : fichier, duree_s) */
    public function store(Request $request, Alerte $alerte): JsonResponse
    {
        abort_unless($alerte->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'fichier' => ['required', 'file', 'mimetypes:'.implode(',', self::MIMES), 'max:'.self::TAILLE_MAX_KO],
            'duree_s' => ['nullable', 'integer', 'min:1', 'max:'.self::DUREE_MAX_S],
        ]);

        if (! $alerte->estActive()) {
            return response()->json(['message' => 'Cette alerte est terminée.'], 409);
        }
        if ($alerte->audios()->count() >= self::MAX_PAR_ALERTE) {
            return response()->json(['message' => 'Nombre maximal de messages vocaux atteint pour cette alerte.'], 422);
        }

        $fichier = $request->file('fichier');
        $mime = (string) $fichier->getMimeType();
        $extension = match (true) {
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'aac') => 'aac',
            default => 'm4a',
        };
        $chemin = $fichier->storeAs("alertes-audio/{$alerte->id}", Str::uuid().".{$extension}", 'local');

        $audio = new AlerteAudio([
            'chemin' => $chemin,
            'mime' => $mime,
            'taille' => $fichier->getSize(),
            'duree_s' => $data['duree_s'] ?? null,
        ]);
        $audio->alerte()->associate($alerte);
        $audio->user_id = $request->user()->id;
        $audio->save();

        AlerteEvenement::noter($alerte->id, 'audio', null, 'app');
        NotifierNouvelAudio::dispatch($audio->id)->onQueue('alertes');

        return response()->json(['data' => [
            'id' => $audio->id,
            'duree_s' => $audio->duree_s,
            'created_at' => $audio->created_at->toIso8601String(),
        ]], 201);
    }

    /** GET /alertes/{alerte}/audio/{audio} — propriétaire ou destinataire accepté uniquement */
    public function show(Request $request, Alerte $alerte, AlerteAudio $audio): StreamedResponse
    {
        abort_unless($audio->alerte_id === $alerte->id, 404);
        $user = $request->user();
        abort_unless($alerte->user_id === $user->id || $alerte->estDestinataireAccepte($user), 404);
        abort_unless(Storage::disk('local')->exists($audio->chemin), 404);

        return Storage::disk('local')->response($audio->chemin, null, [
            'Content-Type' => $audio->mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
