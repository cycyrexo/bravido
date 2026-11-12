<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user()->load('ville'));
    }

    public function update(Request $request): UserResource
    {
        $data = $request->validate([
            'nom' => ['nullable', 'string', 'max:150'],
            'ville_id' => ['nullable', 'integer', 'exists:villes,id'],
            'groupe_sanguin' => ['nullable', Rule::in(User::GROUPES_SANGUINS)],
        ]);

        $user = $request->user();
        $user->fill($data)->save();

        return new UserResource($user->load('ville'));
    }

    /** Suppression du compte (exigée par les stores) : complices, alertes et positions sont supprimés en cascade. */
    public function destroy(Request $request): Response
    {
        $user = $request->user();
        abort_if($user->isStaff(), 403, 'Un compte du back-office ne peut pas être supprimé depuis l\'application.');

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->noContent();
    }

    /** POST /me/device-token — jeton FCM du téléphone */
    public function deviceToken(Request $request): Response
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', Rule::in(['android', 'ios'])],
        ]);

        DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'] ?? null, 'last_used_at' => now()]
        );

        return response()->noContent();
    }
}
