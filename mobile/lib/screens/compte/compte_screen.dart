import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../core/outils.dart';
import '../../core/push.dart';
import '../../core/session.dart';
import '../../widgets/communs.dart';
import 'complices_screen.dart';
import 'fiches_screen.dart';
import 'messages_screen.dart';

/// Espace utilisateur (USR-01 à USR-04).
class CompteScreen extends StatelessWidget {
  const CompteScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: Session.instance,
      builder: (context, _) => Scaffold(
        appBar: const BarreTitre('Espace Utilisateur', actions: [SosAction()]),
        body: Session.instance.connecte ? const _Profil() : const ConnexionOtp(),
      ),
    );
  }
}

/// USR-01 : connexion par numéro de téléphone et code SMS.
class ConnexionOtp extends StatefulWidget {
  const ConnexionOtp({super.key});

  @override
  State<ConnexionOtp> createState() => _ConnexionOtpState();
}

class _ConnexionOtpState extends State<ConnexionOtp> {
  final _telephone = TextEditingController();
  final _code = TextEditingController();
  String? _numero;
  bool _envoi = false;
  int _attente = 0;
  Timer? _minuteur;

  @override
  void dispose() {
    _minuteur?.cancel();
    _telephone.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _demanderCode() async {
    setState(() => _envoi = true);
    try {
      final r = await Api.instance.post('/auth/otp', {'telephone': _telephone.text.trim()}) as Map;
      if (!mounted) return;
      setState(() {
        _numero = '${r['telephone']}';
        _attente = 60;
      });
      _minuteur?.cancel();
      _minuteur = Timer.periodic(const Duration(seconds: 1), (t) {
        if (_attente <= 1) t.cancel();
        if (mounted) setState(() => _attente--);
      });
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    } finally {
      if (mounted) setState(() => _envoi = false);
    }
  }

  Future<void> _verifier() async {
    setState(() => _envoi = true);
    try {
      final r = await Api.instance.post('/auth/verifier', {
        'telephone': _numero,
        'code': _code.text.trim(),
        'appareil': Platform.operatingSystem,
      }) as Map;
      await Session.instance.connecter('${r['token']}', Map<String, dynamic>.from(r['user'] as Map));
      Push.enregistrerToken();
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    } finally {
      if (mounted) setState(() => _envoi = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListView(padding: const EdgeInsets.fromLTRB(22, 8, 22, 28), children: [
      Center(
        child: Image.asset(
          'assets/images/logo-bravido.png',
          height: 92,
          errorBuilder: (_, __, ___) => const Icon(Icons.lock_person_outlined, size: 56, color: Couleurs.vert),
        ),
      ),
      const SizedBox(height: 14),
      Text(
        _numero == null ? 'Bienvenue !' : 'Vérifions votre numéro',
        textAlign: TextAlign.center,
        style: const TextStyle(fontSize: 23, fontWeight: FontWeight.w800, letterSpacing: -0.3),
      ),
      const SizedBox(height: 8),
      const Text(
        'Connectez-vous avec votre numéro de téléphone pour gérer vos complices, vos favoris et vos messages.',
        textAlign: TextAlign.center,
        style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 14.5, height: 1.4),
      ),
      const SizedBox(height: 24),
      if (_numero == null) ...[
        TextField(
          controller: _telephone,
          keyboardType: TextInputType.phone,
          autofillHints: const [AutofillHints.telephoneNumber],
          decoration: const InputDecoration(labelText: 'Numéro de téléphone', hintText: '07 07 12 34 56', prefixText: '+225 '),
        ),
        const SizedBox(height: 18),
        FilledButton.icon(
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          onPressed: _envoi ? null : _demanderCode,
          icon: const Icon(Icons.sms_outlined, size: 19),
          label: Text(_envoi ? 'Envoi…' : 'Recevoir le code par SMS'),
        ),
      ] else ...[
        Center(child: Pastille.verte('Code envoyé au $_numero')),
        const SizedBox(height: 16),
        TextField(
          controller: _code,
          keyboardType: TextInputType.number,
          maxLength: 6,
          autofillHints: const [AutofillHints.oneTimeCode],
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 24, letterSpacing: 8, fontWeight: FontWeight.w700),
          decoration: const InputDecoration(labelText: 'Code à 6 chiffres', counterText: ''),
        ),
        const SizedBox(height: 18),
        FilledButton(
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          onPressed: _envoi ? null : _verifier,
          child: const Text('Valider'),
        ),
        const SizedBox(height: 6),
        TextButton(
          onPressed: _attente > 0 || _envoi ? null : _demanderCode,
          child: Text(_attente > 0 ? 'Renvoyer le code dans $_attente s' : 'Renvoyer le code'),
        ),
        TextButton(onPressed: () => setState(() => _numero = null), child: const Text('Changer de numéro')),
      ],
      const SizedBox(height: 24),
      const Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(Icons.shield_outlined, size: 16, color: Couleurs.sarcelle),
        SizedBox(width: 8),
        Expanded(
          child: Text(
            'Vos données sont traitées conformément à la loi ivoirienne n° 2013-450 relative à la protection des données personnelles.',
            style: TextStyle(fontSize: 12, color: Couleurs.texteSecondaire, height: 1.4),
          ),
        ),
      ]),
    ]);
  }
}

class _Profil extends StatelessWidget {
  const _Profil();

  @override
  Widget build(BuildContext context) {
    final session = Session.instance;
    final user = session.user ?? const {};

    void ouvrir(Widget ecran) => Navigator.push(context, MaterialPageRoute(builder: (_) => ecran));

    return ListView(padding: const EdgeInsets.fromLTRB(16, 6, 16, 28), children: [
      Card(
        child: InkWell(
          onTap: () => _modifierProfil(context),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(children: [
              Avatar(user['nom'] as String?, taille: 56),
              const SizedBox(width: 14),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${user['nom'] ?? 'Complétez votre profil'}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
                  const SizedBox(height: 3),
                  Text(
                    [
                      user['ville'] ?? session.nomVille,
                      'connecté par code SMS',
                      if (user['groupe_sanguin'] != null) 'groupe ${user['groupe_sanguin']}',
                    ].whereType<String>().join(' · '),
                    style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 13, height: 1.3),
                  ),
                ]),
              ),
              const SizedBox(width: 8),
              Container(
                width: 36,
                height: 36,
                decoration: const BoxDecoration(color: Couleurs.vertClair, shape: BoxShape.circle),
                child: const Icon(Icons.edit_outlined, size: 18, color: Couleurs.vert),
              ),
            ]),
          ),
        ),
      ),
      LigneMenu(
        badge: const Icon(Icons.mail_outline_rounded, size: 20, color: Couleurs.vert),
        couleurBadge: Couleurs.vertClair,
        titre: 'Observations, suggestions, préoccupations',
        sousTitre: 'Écrire au service BRAVIDO',
        onTap: () => ouvrir(const MessagesScreen()),
      ),
      LigneMenu(
        badge: const Icon(Icons.call_rounded, size: 20, color: Couleurs.bleu),
        couleurBadge: Couleurs.bleuClair,
        titre: 'Call center',
        sousTitre: session.callCenter.isEmpty ? 'Numéro vert BRAVIDO' : 'Numéro vert BRAVIDO · ${session.callCenter}',
        onTap: session.callCenter.isEmpty
            ? () => afficherMessage(context, 'Le numéro vert sera communiqué au lancement.')
            : () => appeler(session.callCenter),
      ),
      LigneMenu(
        badge: const Icon(Icons.description_outlined, size: 20, color: Color(0xFFB98900)),
        couleurBadge: const Color(0xFFFFF4CC),
        titre: 'Fiche de souscription / correction',
        sousTitre: 'Pour les responsables d\'établissement',
        onTap: () => ouvrir(const FichesScreen()),
      ),
      LigneMenu(
        badge: const Icon(Icons.group_outlined, size: 20, color: Couleurs.rouge),
        couleurBadge: Couleurs.rougeClair,
        titre: 'Mes complices',
        sousTitre: 'Les personnes prévenues en cas d\'alerte',
        onTap: () => ouvrir(const ComplicesScreen()),
      ),
      const TitreSection('Mes messages'),
      const _DerniersMessages(),
      const SizedBox(height: 18),
      OutlinedButton.icon(
        style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48), backgroundColor: Colors.white),
        onPressed: session.deconnecter,
        icon: const Icon(Icons.logout_rounded, size: 18),
        label: const Text('Se déconnecter'),
      ),
      const SizedBox(height: 4),
      TextButton(
        style: TextButton.styleFrom(foregroundColor: Couleurs.rougeFonce, minimumSize: const Size.fromHeight(46)),
        onPressed: () => _supprimerCompte(context),
        child: const Text('Supprimer mon compte'),
      ),
    ]);
  }

  Future<void> _modifierProfil(BuildContext context) async {
    final session = Session.instance;
    final user = session.user ?? const {};
    final nom = TextEditingController(text: '${user['nom'] ?? ''}');
    String? groupe = user['groupe_sanguin'] as String?;
    int? villeId = (user['ville_id'] as int?) ?? session.villeId;

    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, setState) => AlertDialog(
          title: const Text('Mon profil'),
          content: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: nom, decoration: const InputDecoration(labelText: 'Nom et prénoms')),
              const SizedBox(height: 12),
              DropdownButtonFormField<int?>(
                initialValue: villeId,
                decoration: const InputDecoration(labelText: 'Ville'),
                items: [for (final v in session.villes) DropdownMenuItem(value: v['id'] as int, child: Text('${v['nom']}'))],
                onChanged: (v) => setState(() => villeId = v),
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<String?>(
                initialValue: groupe,
                decoration: const InputDecoration(labelText: 'Groupe sanguin (facultatif)'),
                items: [
                  const DropdownMenuItem(value: null, child: Text('Non renseigné')),
                  for (final g in const ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']) DropdownMenuItem(value: g, child: Text(g)),
                ],
                onChanged: (v) => setState(() => groupe = v),
              ),
              const SizedBox(height: 8),
              const Text('Le groupe sanguin est montré à vos complices pendant une alerte.', style: TextStyle(fontSize: 12, color: Couleurs.texteSecondaire)),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Annuler')),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Enregistrer')),
          ],
        ),
      ),
    );
    if (ok != true) return;

    try {
      final r = await Api.instance.put('/me', {'nom': nom.text.trim(), 'ville_id': villeId, 'groupe_sanguin': groupe}) as Map;
      await session.majUtilisateur(Map<String, dynamic>.from(r['data'] as Map));
      if (villeId != null) await session.choisirVille(villeId!);
    } on ApiException catch (e) {
      if (context.mounted) afficherMessage(context, e.message);
    }
  }

  Future<void> _supprimerCompte(BuildContext context) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Supprimer mon compte ?'),
        content: const Text('Vos complices, vos alertes et vos messages seront définitivement supprimés.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Annuler')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Couleurs.rouge),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Supprimer'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await Api.instance.delete('/me');
      await Session.instance.deconnecterLocalement();
    } on ApiException catch (e) {
      if (context.mounted) afficherMessage(context, e.message);
    }
  }
}

/// Section « MES MESSAGES » de la maquette : 3 derniers messages et leur réponse.
class _DerniersMessages extends StatefulWidget {
  const _DerniersMessages();

  @override
  State<_DerniersMessages> createState() => _DerniersMessagesState();
}

class _DerniersMessagesState extends State<_DerniersMessages> {
  List<Map<String, dynamic>>? _messages;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    try {
      final r = await Api.instance.get('/messages') as Map;
      if (!mounted) return;
      setState(() => _messages = (r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).take(3).toList());
    } catch (_) {
      if (mounted) setState(() => _messages = const []);
    }
  }

  @override
  Widget build(BuildContext context) {
    final messages = _messages;
    if (messages == null) return const Chargement();
    if (messages.isEmpty) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 16),
        child: Center(child: Text('Aucun message envoyé.', style: TextStyle(color: Couleurs.texteSecondaire))),
      );
    }
    return Column(children: [
      for (final m in messages)
        Card(
          child: InkWell(
            onTap: () async {
              await Navigator.push(context, MaterialPageRoute(builder: (_) => const MessagesScreen()));
              _charger();
            },
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text('${m['texte']}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600))),
                  const SizedBox(width: 6),
                  m['reponse'] != null ? const Pastille.verte('Répondu') : const Pastille.orange('En attente'),
                ]),
                if (m['reponse'] != null)
                  Text('${m['reponse']}', maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 13)),
              ]),
            ),
          ),
        ),
    ]);
  }
}
