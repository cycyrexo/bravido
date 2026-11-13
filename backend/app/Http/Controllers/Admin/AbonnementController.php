<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Abonnement;
use App\Models\Paiement;
use App\Models\Plan;
use App\Models\User;
use App\Support\Journal;
use App\Support\Telephone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** F19 : back-office des abonnements famille (administrateurs uniquement). */
class AbonnementController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate([
            'onglet' => ['nullable', 'in:abonnements,paiements,plans'],
            'statut' => ['nullable', 'string', 'max:20'],
            'plan' => ['nullable', 'integer'],
            'fournisseur' => ['nullable', 'in:sandbox,orange_money,mtn_momo,wave'],
            'q' => ['nullable', 'string', 'max:30'],
        ]);
        $onglet = $f['onglet'] ?? 'abonnements';
        $tel = ! empty($f['q']) ? (Telephone::normaliser($f['q']) ?? $f['q']) : null;

        $abonnements = $onglet === 'abonnements'
            ? Abonnement::with(['proprietaire:id,nom,telephone', 'plan:id,nom'])->withCount('membres')
                ->when($f['statut'] ?? null, fn ($q, $s) => in_array($s, ['actif', 'expire', 'annule']) ? $q->where('statut', $s) : $q)
                ->when($f['plan'] ?? null, fn ($q, $p) => $q->where('plan_id', $p))
                ->when($tel, fn ($q) => $q->whereHas('proprietaire', fn ($u) => $u->where('telephone', 'like', '%'.$tel.'%')))
                ->latest('id')->paginate(25)->withQueryString()
            : null;

        $paiements = $onglet === 'paiements'
            ? Paiement::with(['user:id,nom,telephone', 'plan:id,nom'])
                ->when($f['statut'] ?? null, fn ($q, $s) => in_array($s, ['en_attente', 'reussi', 'echoue']) ? $q->where('statut', $s) : $q)
                ->when($f['fournisseur'] ?? null, fn ($q, $v) => $q->where('fournisseur', $v))
                ->when($f['plan'] ?? null, fn ($q, $p) => $q->where('plan_id', $p))
                ->when($tel, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('telephone', 'like', '%'.$tel.'%')))
                ->latest('id')->paginate(25)->withQueryString()
            : null;

        return view('admin.abonnements.index', [
            'onglet' => $onglet, 'f' => $f, 'plans' => Plan::orderBy('prix_xof')->get(),
            'abonnements' => $abonnements, 'paiements' => $paiements,
            'edition' => $request->integer('edit') ? Plan::find($request->integer('edit')) : null,
        ]);
    }

    public function planEnregistrer(Request $request, ?Plan $plan = null): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'nom' => ['required', 'string', 'max:100'],
            'prix_xof' => ['required', 'integer', 'min:0', 'max:10000000'],
            'duree_jours' => ['required', 'integer', 'min:1', 'max:3660'],
            'profils_max' => ['required', 'integer', 'min:1', 'max:20'],
        ]);
        $data['actif'] = $request->boolean('actif');
        $data['a_confirmer'] = $request->boolean('a_confirmer');

        $avant = $plan?->toArray();
        $plan = $plan ?? new Plan;
        $plan->fill($data)->save();
        Journal::log($avant ? 'plan.modification' : 'plan.creation', $plan, $avant, $plan->toArray());

        return redirect()->route('admin.abonnements.index', ['onglet' => 'plans'])->with('succes', 'Plan enregistré.');
    }

    /** Octroi manuel (geste commercial, test) : crée un abonnement sans paiement. */
    public function accorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'telephone' => ['required', 'string', 'max:30'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'jours' => ['nullable', 'integer', 'min:1', 'max:3660'],
        ]);
        $user = User::where('telephone', Telephone::normaliser($data['telephone']) ?? $data['telephone'])->first();
        if (! $user) {
            return back()->withErrors(['telephone' => 'Aucun utilisateur avec ce numéro.'])->withInput();
        }
        $plan = Plan::findOrFail($data['plan_id']);

        $a = new Abonnement;
        $a->user_id = $user->id;
        $a->plan_id = $plan->id;
        $a->statut = 'actif';
        $a->origine = 'manuel';
        $a->debut = now();
        $a->fin = now()->addDays($data['jours'] ?? $plan->duree_jours);
        $a->save();
        Journal::log('abonnement.octroi', $a, null, ['user_id' => $user->id, 'plan' => $plan->code, 'fin' => $a->fin->toDateString()]);

        return redirect()->route('admin.abonnements.index')->with('succes', 'Abonnement accordé.');
    }

    public function annuler(Abonnement $abonnement): RedirectResponse
    {
        abort_unless($abonnement->statut === 'actif', 409, 'Cet abonnement n\'est plus actif.');
        $avant = ['statut' => $abonnement->statut, 'fin' => $abonnement->fin->toDateString()];
        $abonnement->forceFill(['statut' => 'annule', 'fin' => now()])->save();
        Journal::log('abonnement.annulation', $abonnement, $avant, ['statut' => 'annule']);

        return back()->with('succes', 'Abonnement annulé. Les alertes de l\'utilisateur ne sont pas affectées.');
    }
}
