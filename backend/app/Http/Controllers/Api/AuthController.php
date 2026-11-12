<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AlerteDestinataire;
use App\Models\Complice;
use App\Models\DeviceToken;
use App\Models\User;
use App\Rules\TelephoneValide;
use App\Services\OtpService;
use App\Support\Telephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** POST /auth/otp */
    public function demanderOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate(['telephone' => ['required', 'string', new TelephoneValide]]);
        $telephone = Telephone::normaliser($data['telephone']);

        $otp->envoyer($telephone, $request->ip());

        return response()->json([
            'message' => 'Un code vous a été envoyé par SMS.',
            'telephone' => $telephone,
            'validite_minutes' => config('bravido.otp.validite_minutes'),
        ]);
    }

    /** POST /auth/verifier */
    public function verifierOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'telephone' => ['required', 'string', new TelephoneValide],
            'code' => ['required', 'digits_between:4,8'],
            'appareil' => ['nullable', 'string', 'max:100'],
        ]);
        $telephone = Telephone::normaliser($data['telephone']);

        if (! $otp->verifier($telephone, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Code invalide ou expiré.']);
        }

        $user = User::firstOrCreate(['telephone' => $telephone]);
        if ($user->statut === 'suspendu') {
            abort(403, 'Votre compte est suspendu. Contactez le service client BRAVIDO.');
        }
        if (! $user->telephone_verified_at) {
            $user->forceFill(['telephone_verified_at' => now()])->save();
        }

        // Rattache les invitations et alertes reçues avant l'inscription
        Complice::where('telephone', $telephone)->whereNull('complice_user_id')->update(['complice_user_id' => $user->id]);
        AlerteDestinataire::where('telephone', $telephone)->whereNull('destinataire_user_id')->update(['destinataire_user_id' => $user->id]);

        $token = $user->createToken($data['appareil'] ?? 'mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'nouveau' => $user->wasRecentlyCreated,
            'user' => new UserResource($user->load('ville')),
        ]);
    }

    /** POST /auth/deconnexion */
    public function deconnexion(Request $request): Response
    {
        $request->validate(['device_token' => ['nullable', 'string', 'max:255']]);

        if ($request->filled('device_token')) {
            DeviceToken::where('user_id', $request->user()->id)->where('token', $request->input('device_token'))->delete();
        }
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
