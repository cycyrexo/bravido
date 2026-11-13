import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../core/langue.dart';
import '../../widgets/communs.dart';

String prixLisible(int xof) => '${xof.toString().replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ' ')} FCFA';

/// F19 : abonnement famille. Les alertes d'urgence restent gratuites et identiques pour tous.
class AbonnementScreen extends StatefulWidget {
  const AbonnementScreen({super.key});

  @override
  State<AbonnementScreen> createState() => _AbonnementScreenState();
}

class _AbonnementScreenState extends State<AbonnementScreen> with WidgetsBindingObserver {
  Map<String, dynamic>? _etat;
  List<Map<String, dynamic>> _plans = [];
  List<Map<String, dynamic>> _invitations = [];
  String? _erreur;
  bool _occupe = false;
  final _telephone = TextEditingController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _charger();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _telephone.dispose();
    super.dispose();
  }

  /// Retour du navigateur de paiement : on relit l'état de l'abonnement.
  @override
  void didChangeAppLifecycleState(AppLifecycleState etat) {
    if (etat == AppLifecycleState.resumed) _charger();
  }

  Future<void> _charger() async {
    try {
      final r = await Api.instance.get('/abonnement') as Map;
      final p = await Api.instance.get('/plans') as Map;
      final i = await Api.instance.get('/abonnement/invitations') as Map;
      if (!mounted) return;
      setState(() {
        _etat = Map<String, dynamic>.from(r['data'] as Map);
        _plans = (p['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _invitations = (i['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _erreur = null;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    }
  }

  Future<void> _souscrire(Map<String, dynamic> plan) async {
    final fournisseurs = ((_etat?['fournisseurs'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final choix = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (c) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
            child: Text(tr('Payer avec', 'Pay with'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
          ),
          for (final f in fournisseurs)
            ListTile(
              enabled: f['disponible'] == true,
              leading: Icon(f['code'] == 'sandbox' ? Icons.science_outlined : Icons.phone_android_rounded),
              title: Text('${f['libelle']}'),
              subtitle: f['disponible'] == true ? null : Text(tr('Bientôt disponible', 'Coming soon')),
              onTap: f['disponible'] == true ? () => Navigator.pop(c, '${f['code']}') : null,
            ),
        ]),
      ),
    );
    if (choix == null || !mounted) return;
    setState(() => _occupe = true);
    try {
      final r = await Api.instance.post('/abonnement/souscrire', {'plan': plan['code'], 'fournisseur': choix}) as Map;
      final url = Uri.parse('${(r['data'] as Map)['url']}');
      if (!await launchUrl(url, mode: LaunchMode.inAppBrowserView)) {
        await launchUrl(url, mode: LaunchMode.externalApplication);
      }
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    } catch (_) {
      if (mounted) afficherMessage(context, tr('Impossible d\'ouvrir la page de paiement.', 'Could not open the payment page.'));
    } finally {
      if (mounted) setState(() => _occupe = false);
    }
  }

  Future<void> _inviter() async {
    final tel = _telephone.text.trim();
    if (tel.isEmpty) return;
    setState(() => _occupe = true);
    try {
      await Api.instance.post('/abonnement/membres', {'telephone': tel});
      _telephone.clear();
      if (mounted) afficherMessage(context, tr('Invitation envoyée.', 'Invitation sent.'));
      await _charger();
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    } finally {
      if (mounted) setState(() => _occupe = false);
    }
  }

  Future<void> _action(Future<void> Function() f) async {
    try {
      await f();
      await _charger();
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final etat = _etat;
    return Scaffold(
      appBar: BarreTitre(tr('Abonnement famille', 'Family plan')),
      body: etat == null
          ? (_erreur != null ? MessageVide(_erreur!, icone: Icons.cloud_off, onReessayer: _charger) : const Chargement())
          : RefreshIndicator(
              onRefresh: _charger,
              child: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 32), children: [
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(color: Couleurs.vertClair, borderRadius: BorderRadius.circular(14)),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Icon(Icons.verified_user_rounded, color: Couleurs.encreVerte),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        tr('Les alertes d\'urgence restent gratuites et identiques pour tous : SOS, notifications, SMS de secours, partage de position et trajets ne dépendent jamais d\'un abonnement.',
                            'Emergency alerts stay free and identical for everyone: SOS, notifications, fallback SMS, location sharing and trips never depend on a subscription.'),
                        style: TextStyle(color: Couleurs.encreVerte, fontWeight: FontWeight.w600, height: 1.35),
                      ),
                    ),
                  ]),
                ),
                if (_invitations.isNotEmpty) ...[
                  TitreSection(tr('Invitations reçues', 'Invitations received')),
                  for (final i in _invitations)
                    Card(
                      child: ListTile(
                        title: Text(tr('${i['de']} vous invite à rejoindre son abonnement famille', '${i['de']} invites you to join their family plan')),
                        trailing: FilledButton(onPressed: () => _action(() async => Api.instance.post('/abonnement/invitations/${i['id']}/accepter')), child: Text(tr('Accepter', 'Accept'))),
                      ),
                    ),
                ],
                if (etat['actif'] == true) ..._abonnement(etat, theme) else ..._offres(theme),
                if (((etat['paiements'] as List?) ?? const []).isNotEmpty) ...[
                  TitreSection(tr('Derniers paiements', 'Recent payments')),
                  for (final p in (etat['paiements'] as List).cast<Map>())
                    ListTile(
                      dense: true,
                      title: Text('${p['plan']} · ${prixLisible((p['montant'] as num).toInt())}'),
                      subtitle: Text('${p['date']} · ${p['fournisseur']}'),
                      trailing: Text(switch (p['statut']) { 'reussi' => tr('Réussi', 'Paid'), 'echoue' => tr('Échoué', 'Failed'), _ => tr('En attente', 'Pending') }),
                    ),
                ],
              ]),
            ),
    );
  }

  List<Widget> _offres(ThemeData theme) => [
        TitreSection(tr('Choisir un plan', 'Choose a plan')),
        if (_plans.isEmpty) MessageVide(tr('Aucun plan disponible pour le moment.', 'No plan available right now.'), icone: Icons.inbox_outlined),
        for (final p in _plans)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text('${p['nom']}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17))),
                  if (p['a_confirmer'] == true) Pastille.orange(tr('Prix à confirmer', 'Price to be confirmed')),
                ]),
                const SizedBox(height: 4),
                Text('${prixLisible((p['prix_xof'] as num).toInt())} / ${p['duree_jours']} ${tr('jours', 'days')}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 20)),
                const SizedBox(height: 8),
                Text(tr('Jusqu\'à ${p['profils_max']} profils (vous compris) · historique étendu · support prioritaire',
                    'Up to ${p['profils_max']} profiles (including you) · extended history · priority support'),
                    style: TextStyle(color: theme.colorScheme.onSurfaceVariant, height: 1.35)),
                const SizedBox(height: 12),
                FilledButton(
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                  onPressed: _occupe ? null : () => _souscrire(p),
                  child: Text(tr('Souscrire', 'Subscribe')),
                ),
              ]),
            ),
          ),
      ];

  List<Widget> _abonnement(Map<String, dynamic> etat, ThemeData theme) {
    final a = Map<String, dynamic>.from(etat['abonnement'] as Map);
    final proprio = a['role'] == 'proprietaire';
    final plan = Map<String, dynamic>.from(a['plan'] as Map);
    final membres = ((etat['membres'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final complet = (a['places_utilisees'] as num) >= (a['places_max'] as num);
    return [
      TitreSection(tr('Mon abonnement', 'My plan')),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text('${plan['nom']}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17))),
              Pastille.verte(tr('Actif', 'Active')),
            ]),
            const SizedBox(height: 4),
            Text(tr('Valable jusqu\'au ${a['fin']}', 'Valid until ${a['fin']}')),
            Text(tr('${a['places_utilisees']} profil(s) sur ${a['places_max']}', '${a['places_utilisees']} of ${a['places_max']} profiles'), style: TextStyle(color: theme.colorScheme.onSurfaceVariant)),
            if (!proprio) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('Vous bénéficiez de cet abonnement en tant que membre.', 'You benefit from this plan as a member.'))),
          ]),
        ),
      ),
      TitreSection(tr('Membres de la famille', 'Family members')),
      if (membres.isEmpty) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(tr('Aucun membre pour l\'instant.', 'No members yet.'), style: TextStyle(color: theme.colorScheme.onSurfaceVariant))),
      for (final m in membres)
        Card(
          child: ListTile(
            title: Text('${m['nom']}'),
            subtitle: Text(m['statut'] == 'accepte' ? tr('Membre', 'Member') : tr('Invitation en attente', 'Invitation pending')),
            trailing: proprio
                ? IconButton(tooltip: tr('Retirer', 'Remove'), icon: const Icon(Icons.person_remove_outlined), onPressed: () => _action(() async => Api.instance.delete('/abonnement/membres/${m['id']}')))
                : null,
          ),
        ),
      if (proprio) ...[
        const SizedBox(height: 8),
        TextField(
          controller: _telephone,
          keyboardType: TextInputType.phone,
          enabled: !complet,
          decoration: InputDecoration(labelText: tr('Numéro du proche (déjà inscrit à BRAVIDO)', 'Relative\'s number (already on BRAVIDO)')),
        ),
        const SizedBox(height: 8),
        FilledButton.icon(
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
          onPressed: complet || _occupe ? null : _inviter,
          icon: const Icon(Icons.person_add_alt_1_rounded),
          label: Text(complet ? tr('Limite de profils atteinte', 'Profile limit reached') : tr('Inviter', 'Invite')),
        ),
      ],
    ];
  }
}
