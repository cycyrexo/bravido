<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageSupport;
use App\Services\Notifier;
use App\Support\Journal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** BO-05 : messagerie support. */
class MessageController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate([
            'statut' => ['nullable', 'in:nouveau,en_cours,traite'],
            'type' => ['nullable', 'in:'.implode(',', MessageSupport::TYPES)],
        ]);

        $query = MessageSupport::with(['user', 'etablissement'])->latest();
        if (! empty($f['statut'])) {
            $query->where('statut', $f['statut']);
        } else {
            $query->where('statut', '!=', 'traite');
        }
        if (! empty($f['type'])) {
            $query->where('type', $f['type']);
        }

        return view('admin.messages.index', [
            'messages' => $query->paginate(25)->withQueryString(),
            'f' => $f,
        ]);
    }

    public function show(MessageSupport $message): View
    {
        if ($message->statut === 'nouveau') {
            $message->forceFill(['statut' => 'en_cours'])->save();
        }

        return view('admin.messages.show', ['message' => $message->load(['user', 'etablissement', 'reponduPar'])]);
    }

    public function repondre(Request $request, MessageSupport $message, Notifier $notifier): RedirectResponse
    {
        $data = $request->validate([
            'reponse' => ['required', 'string', 'max:2000'],
            'statut' => ['required', 'in:en_cours,traite'],
        ]);

        $message->forceFill([
            'reponse' => $data['reponse'],
            'statut' => $data['statut'],
            'repondu_par' => $request->user()->id,
            'repondu_at' => now(),
        ])->save();

        Journal::log('message.reponse', $message);
        $notifier->push($message->user, 'BRAVIDO vous a répondu', mb_strimwidth($data['reponse'], 0, 120, '…'), [
            'type' => 'message', 'message_id' => $message->id,
        ]);

        return redirect()->route('admin.messages.index')->with('succes', 'Réponse envoyée.');
    }
}
