<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComplicesResource;
use App\Models\Complice;
use App\Rules\TelephoneValide;
use App\Services\ComplicesService;
use App\Support\Telephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** URG-01 : gestion du cercle de complices. */
class ComplicesController extends Controller
{
    public function __construct(private readonly ComplicesService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ComplicesResource::collection($request->user()->complices()->get())
            ->additional(['max' => config('bravido.complices_max')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'telephone' => ['required', 'string', new TelephoneValide],
        ]);

        $complice = $this->service->inviter(
            $request->user(),
            $data['nom'],
            Telephone::normaliser($data['telephone'])
        );

        return (new ComplicesResource($complice))->response()->setStatusCode(201);
    }

    public function update(Request $request, Complice $complice): ComplicesResource
    {
        abort_unless($complice->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'nom' => ['sometimes', 'string', 'max:150'],
            'ordre' => ['sometimes', 'integer', 'min:1', 'max:'.config('bravido.complices_max')],
        ]);
        $complice->fill($data)->save();

        return new ComplicesResource($complice);
    }

    public function destroy(Request $request, Complice $complice): Response
    {
        abort_unless($complice->user_id === $request->user()->id, 404);
        $complice->delete();

        return response()->noContent();
    }

    /** Invitations reçues : les cercles dont je fais (ou ferai) partie. */
    public function invitations(Request $request): AnonymousResourceCollection
    {
        $invitations = Complice::where('complice_user_id', $request->user()->id)
            ->with('user')
            ->latest()
            ->get();

        return ComplicesResource::collection($invitations);
    }

    public function accepter(Request $request, Complice $complice): ComplicesResource
    {
        abort_unless($complice->complice_user_id === $request->user()->id, 404);

        return new ComplicesResource($this->service->repondre($complice, true, $request->user())->load('user'));
    }

    /** Refuser une invitation, ou quitter un cercle déjà accepté. */
    public function refuser(Request $request, Complice $complice): ComplicesResource
    {
        abort_unless($complice->complice_user_id === $request->user()->id, 404);

        return new ComplicesResource($this->service->repondre($complice, false, $request->user())->load('user'));
    }
}
