<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Journal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UtilisateurController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(User::ROLES)],
        ]);

        $query = User::with('ville')->latest();
        if (! empty($f['q'])) {
            $terme = $this->like($f['q']);
            $query->where(fn ($q) => $q->where('telephone', 'like', $terme)
                ->orWhere('nom', 'like', $terme)
                ->orWhere('email', 'like', $terme));
        }
        if (! empty($f['role'])) {
            $query->where('role', $f['role']);
        }

        return view('admin.utilisateurs.index', [
            'utilisateurs' => $query->paginate(30)->withQueryString(),
            'f' => $f,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(User::ROLES)],
            'statut' => ['required', 'in:actif,suspendu'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:10', 'max:100'],
        ]);

        if ($user->id === $request->user()->id && ($data['role'] !== 'admin' || $data['statut'] !== 'actif')) {
            return back()->withErrors(['role' => 'Vous ne pouvez pas retirer vos propres droits d\'administrateur.']);
        }

        if (in_array($data['role'], ['moderateur', 'admin'], true) && empty($data['email'] ?? $user->email)) {
            return back()->withErrors(['email' => 'Un compte du back-office doit avoir un e-mail et un mot de passe.']);
        }

        $avant = $user->only(['role', 'statut', 'email']);

        $user->role = $data['role'];
        $user->statut = $data['statut'];
        $user->email = $data['email'] ?? $user->email;
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        if ($user->statut === 'suspendu') {
            $user->tokens()->delete();
        }

        Journal::log('utilisateur.modification', $user, $avant, $user->only(['role', 'statut', 'email']));

        return back()->with('succes', 'Utilisateur mis à jour.');
    }
}
