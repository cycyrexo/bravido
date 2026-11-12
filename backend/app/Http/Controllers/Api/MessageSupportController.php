<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** USR-02 et « Signaler une erreur » (HOP-03). */
class MessageSupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $messages = MessageSupport::where('user_id', $request->user()->id)
            ->with('etablissement:id,nom')
            ->latest()
            ->paginate(20);

        return response()->json($messages->through(fn (MessageSupport $m) => $this->format($m)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(MessageSupport::TYPES)],
            'texte' => ['required', 'string', 'min:5', 'max:2000'],
            'etablissement_id' => ['nullable', 'required_if:type,signalement', 'integer', 'exists:etablissements,id'],
        ]);

        $message = new MessageSupport($data);
        $message->user_id = $request->user()->id;
        $message->save();

        return response()->json(['data' => $this->format($message->load('etablissement:id,nom'))], 201);
    }

    public function show(Request $request, MessageSupport $message): JsonResponse
    {
        abort_unless($message->user_id === $request->user()->id, 404);

        return response()->json(['data' => $this->format($message->load('etablissement:id,nom'))]);
    }

    private function format(MessageSupport $m): array
    {
        return [
            'id' => $m->id,
            'type' => $m->type,
            'texte' => $m->texte,
            'etablissement' => $m->etablissement?->only(['id', 'nom']),
            'statut' => $m->statut,
            'reponse' => $m->reponse,
            'repondu_at' => $m->repondu_at?->toIso8601String(),
            'created_at' => $m->created_at->toIso8601String(),
        ];
    }
}
