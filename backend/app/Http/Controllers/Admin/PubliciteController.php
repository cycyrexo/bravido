<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Publicite;
use App\Models\Ville;
use App\Support\Journal;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** BO-06 : régie publicitaire (bannière d'accueil uniquement, jamais dans l'écran Alerte). */
class PubliciteController extends Controller
{
    public function index(): View
    {
        $publicites = Publicite::with('villes')
            ->addSelect([
                'impressions_total' => DB::table('pub_stats')->selectRaw('COALESCE(SUM(impressions), 0)')->whereColumn('publicite_id', 'publicites.id'),
                'clics_total' => DB::table('pub_stats')->selectRaw('COALESCE(SUM(clics), 0)')->whereColumn('publicite_id', 'publicites.id'),
            ])
            ->orderByDesc('date_fin')
            ->paginate(20);

        return view('admin.publicites.index', ['publicites' => $publicites]);
    }

    public function create(): View
    {
        return $this->formulaire(new Publicite([
            'actif' => true, 'ordre' => 0,
            'date_debut' => today(), 'date_fin' => today()->addMonth(),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->valider($request, true);
        $data['image'] = $request->file('image')->store('publicites', 'public');

        $publicite = Publicite::create($data);
        $publicite->villes()->sync($request->input('villes', []));
        Journal::log('publicite.creation', $publicite, null, $publicite->toArray());

        return redirect()->route('admin.publicites.index')->with('succes', 'Publicité créée.');
    }

    public function edit(Publicite $publicite): View
    {
        return $this->formulaire($publicite->load('villes'));
    }

    public function update(Request $request, Publicite $publicite): RedirectResponse
    {
        $data = $this->valider($request, false);
        $avant = $publicite->toArray();

        if ($request->hasFile('image')) {
            Storage::disk('public')->delete($publicite->image);
            $data['image'] = $request->file('image')->store('publicites', 'public');
        }

        $publicite->update($data);
        $publicite->villes()->sync($request->input('villes', []));
        Journal::log('publicite.modification', $publicite, $avant, $publicite->toArray());

        return redirect()->route('admin.publicites.index')->with('succes', 'Publicité mise à jour.');
    }

    public function destroy(Publicite $publicite): RedirectResponse
    {
        Journal::log('publicite.suppression', $publicite, $publicite->toArray());
        Storage::disk('public')->delete($publicite->image);
        $publicite->delete();

        return redirect()->route('admin.publicites.index')->with('succes', 'Publicité supprimée.');
    }

    private function formulaire(Publicite $publicite): View
    {
        return view('admin.publicites.form', [
            'publicite' => $publicite,
            'villes' => Ville::orderBy('nom')->get(),
        ]);
    }

    private function valider(Request $request, bool $creation): array
    {
        $data = $request->validate([
            'annonceur' => ['required', 'string', 'max:150'],
            'titre' => ['nullable', 'string', 'max:150'],
            'lien' => ['nullable', 'url:https,http', 'max:500'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
            'ordre' => ['nullable', 'integer', 'min:0', 'max:999'],
            'villes' => ['nullable', 'array'],
            'villes.*' => ['integer', 'exists:villes,id'],
            // Bannière panoramique : 1200 × 400 px conseillé
            'image' => [$creation ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        unset($data['villes'], $data['image']);
        $data['actif'] = $request->boolean('actif');
        $data['ordre'] = (int) ($data['ordre'] ?? 0);

        return $data;
    }
}
