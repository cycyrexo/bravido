import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../core/outils.dart';
import '../../core/push.dart';
import '../../core/session.dart';
import '../../widgets/communs.dart';
import '../alerte/faux_appel_screen.dart';
import '../alerte/trajet_screen.dart';
import '../../core/langue.dart';
import 'complices_screen.dart';
import 'rappels_screen.dart';
import '../reglages_screen.dart';
import 'fiche_medicale_screen.dart';
import 'fiches_screen.dart';
import 'mon_etablissement_screen.dart';
import 'messages_screen.dart';
import 'mes_avis_screen.dart';
import 'abonnement_screen.dart';

/// Espace utilisateur (USR-01 à USR-04).
class CompteScreen extends StatelessWidget {
  const CompteScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: Session.instance,
      builder: (context, _) => Scaffold(
        appBar: BarreTitre(tr('Espace Utilisateur', 'My Account'), actions: const [SosAction()]),
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
          errorBuilder: (_, __, ___) => Icon(Icons.lock_person_outlined, size: 56, color: Couleurs.accent),
        ),
      ),
      const SizedBox(height: 14),
      Text(
        _numero == null ? tr('Bienvenue !', 'Welcome!') : tr('Vérifions votre numéro', 'Let\'s verify your number'),
        textAlign: TextAlign.center,
        style: const TextStyle(fontSize: 23, fontWeight: FontWeight.w800, letterSpacing: -0.3),
      ),
      const SizedBox(height: 8),
      Text(
        tr('Connectez-vous avec votre numéro de téléphone pour gérer vos complices, vos favoris et vos messages.', 'Sign in with your phone number to manage your accomplices, favourites and messages.'),
        textAlign: TextAlign.center,
        style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 14.5, height: 1.4),
      ),
      const SizedBox(height: 24),
      if (_numero == null) ...[
        TextField(
          controller: _telephone,
          keyboardType: TextInputType.phone,
          autofillHints: const [AutofillHints.telephoneNumber],
          decoration: InputDecoration(labelText: tr('Numéro de téléphone', 'Phone number'), hintText: '07 07 12 34 56', prefixText: '+225 '),
        ),
        const SizedBox(height: 18),
        FilledButton.icon(
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          onPressed: _envoi ? null : _demanderCode,
          icon: const Icon(Icons.sms_outlined, size: 19),
          label: Text(_envoi ? tr('Envoi…', 'Sending…') : tr('Recevoir le code par SMS', 'Get the code by SMS')),
        ),
      ] else ...[
        Center(child: Pastille.verte(tr('Code envoyé au $_numero', 'Code sent to $_numero'))),
        const SizedBox(height: 16),
        TextField(
          controller: _code,
          keyboardType: TextInputType.number,
          maxLength: 6,
          autofillHints: const [AutofillHints.oneTimeCode],
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 24, letterSpacing: 8, fontWeight: FontWeight.w700),
          decoration: InputDecoration(labelText: tr('Code à 6 chiffres', '6-digit code'), counterText: ''),
        ),
        const SizedBox(height: 18),
        FilledButton(
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          onPressed: _envoi ? null : _verifier,
          child: Text(tr('Valider', 'Confirm')),
        ),
        const SizedBox(height: 6),
        TextButton(
          onPressed: _attente > 0 || _envoi ? null : _demanderCode,
          child: Text(_attente > 0 ? tr('Renvoyer le code dans $_attente s', 'Resend the code in $_attente s') : tr('Renvoyer le code', 'Resend the code')),
        ),
        TextButton(onPressed: () => setState(() => _numero = null), child: Text(tr('Changer de numéro', 'Change number'))),
      ],
      const SizedBox(height: 24),
      Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Icon(Icons.shield_outlined, size: 16, color: Couleurs.sarcelle),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            tr('Vos données sont traitées conformément à la loi ivoirienne n° 2013-450 relative à la protection des données personnelles.', 'Your data is processed in accordance with Ivorian law No. 2013-450 on personal data protection.'),
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
                  Text('${user['nom'] ?? tr('Complétez votre profil', 'Complete your profile')}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
                  const SizedBox(height: 3),
                  Text(
                    [
                      user['ville'] ?? session.nomVille,
                      tr('connecté par code SMS', 'signed in by SMS code'),
                      if (user['groupe_sanguin'] != null) tr('groupe ${user['groupe_sanguin']}', 'group ${user['groupe_sanguin']}'),
                    ].whereType<String>().join(' · '),
                    style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 13, height: 1.3),
                  ),
                ]),
              ),
              const SizedBox(width: 8),
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(color: Couleurs.vertClair, shape: BoxShape.circle),
                child: Icon(Icons.edit_outlined, size: 18, color: Couleurs.accent),
              ),
            ]),
          ),
        ),
      ),
      LigneMenu(
        badge: Icon(Icons.mail_outline_rounded, size: 20, color: Couleurs.accent),
        couleurBadge: Couleurs.vertClair,
        titre: tr('Observations, suggestions, préoccupations', 'Comments, suggestions, concerns'),
        sousTitre: tr('Écrire au service BRAVIDO', 'Write to BRAVIDO'),
        onTap: () => ouvrir(const MessagesScreen()),
      ),
      LigneMenu(
        badge: const Icon(Icons.call_rounded, size: 20, color: Couleurs.bleu),
        couleurBadge: Couleurs.bleuClair,
        titre: 'Call center',
        sousTitre: session.callCenter.isEmpty ? tr('Numéro vert BRAVIDO', 'BRAVIDO toll-free number') : tr('Numéro vert BRAVIDO · ${session.callCenter}', 'BRAVIDO toll-free number · ${session.callCenter}'),
        onTap: session.callCenter.isEmpty
            ? () => afficherMessage(context, tr('Le numéro vert sera communiqué au lancement.', 'The toll-free number will be announced at launch.'))
            : () => appeler(session.callCenter),
      ),
      LigneMenu(
        badge: const Icon(Icons.description_outlined, size: 20, color: Color(0xFFB98900)),
        couleurBadge: const Color(0xFFFFF4CC),
        titre: tr('Fiche de souscription / correction', 'Subscription / correction form'),
        sousTitre: tr('Pour les responsables d\'établissement', 'For facility managers'),
        onTap: () => ouvrir(const FichesScreen()),
      ),
      const MonEtablissementEntree(),
      LigneMenu(
        badge: Icon(Icons.group_outlined, size: 20, color: Couleurs.encreRouge),
        couleurBadge: Couleurs.rougeClair,
        titre: tr('Mes complices', 'My accomplices'),
        sousTitre: tr('Les personnes prévenues en cas d\'alerte', 'People alerted in case of an alert'),
        onTap: () => ouvrir(const ComplicesScreen()),
      ),
      LigneMenu(
        badge: Icon(Icons.medical_information_outlined, size: 20, color: Couleurs.encreRouge),
        couleurBadge: Couleurs.rougeClair,
        titre: tr('Ma fiche médicale', 'My medical card'),
        sousTitre: tr('Allergies, traitements : partagés seulement pendant une alerte, si vous le choisissez', 'Allergies, treatments: shared only during an alert, if you choose to'),
        onTap: () => ouvrir(const FicheMedicaleScreen()),
      ),
      LigneMenu(
        badge: Icon(Icons.directions_walk_rounded, size: 20, color: Couleurs.accent),
        couleurBadge: Couleurs.vertClair,
        titre: tr('Je rentre chez moi', 'Heading home'),
        sousTitre: tr('Trajet surveillé : alerte automatique sans confirmation d\'arrivée', 'Monitored trip: automatic alert without arrival confirmation'),
        onTap: () => ouvrir(const TrajetScreen()),
      ),
      LigneMenu(
        badge: const Icon(Icons.phone_callback_rounded, size: 20, color: Color(0xFF6B4FD6)),
        couleurBadge: const Color(0xFFEDE8FB),
        titre: tr('Faux appel', 'Fake call'),
        sousTitre: tr('Un appel entrant simulé pour quitter une situation gênante', 'A simulated incoming call to leave an awkward situation'),
        onTap: () => ouvrir(const FauxAppelScreen()),
      ),
      LigneMenu(
        badge: const Icon(Icons.family_restroom_rounded, size: 20, color: Color(0xFF167A74)),
        couleurBadge: Couleurs.vertClair,
        titre: tr('Abonnement famille', 'Family plan'),
        sousTitre: tr('Profils partagés, historique étendu. Les alertes restent gratuites.', 'Shared profiles, extended history. Alerts stay free.'),
        onTap: () => ouvrir(const AbonnementScreen()),
      ),
      LigneMenu(
        badge: const Icon(Icons.rate_review_outlined, size: 20, color: Color(0xFFB36A00)),
        couleurBadge: Couleurs.orangeClair,
        titre: tr('Mes avis', 'My reviews'),
        sousTitre: tr('Avis donnés sur les établissements et leur état de vérification', 'Reviews you left on facilities and their review status'),
        onTap: () => ouvrir(const MesAvisScreen()),
      ),
      LigneMenu(
        badge: const Icon(Icons.alarm_rounded, size: 20, color: Color(0xFFB0307A)),
        couleurBadge: Couleurs.rose,
        titre: tr('Mes rappels', 'My reminders'),
        sousTitre: tr('Médicaments et rendez-vous, sur ce téléphone uniquement', 'Medication and appointments, on this phone only'),
        onTap: () => ouvrir(const RappelsScreen()),
      ),
      LigneMenu(
        badge: Icon(Icons.tune_rounded, size: 20, color: Couleurs.encreGrise),
        couleurBadge: Couleurs.gris,
        titre: tr('Réglages', 'Settings'),
        sousTitre: tr('Thème, taille du texte, langue', 'Theme, text size, language'),
        onTap: () => ouvrir(const ReglagesScreen()),
      ),
      const CarteModeDiscret(),
      TitreSection(tr('Mes messages', 'My messages')),
      const _DerniersMessages(),
      const SizedBox(height: 18),
      OutlinedButton.icon(
        style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48), backgroundColor: Couleurs.surface),
        onPressed: session.deconnecter,
        icon: const Icon(Icons.logout_rounded, size: 18),
        label: Text(tr('Se déconnecter', 'Sign out')),
      ),
      const SizedBox(height: 4),
      TextButton(
        style: TextButton.styleFrom(foregroundColor: Couleurs.encreRouge, minimumSize: const Size.fromHeight(46)),
        onPressed: () => _supprimerCompte(context),
        child: Text(tr('Supprimer mon compte', 'Delete my account')),
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
          title: Text(tr('Mon profil', 'My profile')),
          content: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: nom, decoration: InputDecoration(labelText: tr('Nom et prénoms', 'Full name'))),
              const SizedBox(height: 12),
              DropdownButtonFormField<int?>(
                initialValue: villeId,
                decoration: InputDecoration(labelText: tr('Ville', 'City')),
                items: [for (final v in session.villes) DropdownMenuItem(value: v['id'] as int, child: Text('${v['nom']}'))],
                onChanged: (v) => setState(() => villeId = v),
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<String?>(
                initialValue: groupe,
                decoration: InputDecoration(labelText: tr('Groupe sanguin (facultatif)', 'Blood group (optional)')),
                items: [
                  DropdownMenuItem(value: null, child: Text(tr('Non renseigné', 'Not provided'))),
                  for (final g in const ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']) DropdownMenuItem(value: g, child: Text(g)),
                ],
                onChanged: (v) => setState(() => groupe = v),
              ),
              const SizedBox(height: 8),
              Text(tr('Le groupe sanguin est montré à vos complices pendant une alerte.', 'Your blood group is shown to your accomplices during an alert.'), style: TextStyle(fontSize: 12, color: Couleurs.texteSecondaire)),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Annuler', 'Cancel'))),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(tr('Enregistrer', 'Save'))),
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
        title: Text(tr('Supprimer mon compte ?', 'Delete my account?')),
        content: Text(tr('Vos complices, vos alertes et vos messages seront définitivement supprimés.', 'Your accomplices, alerts and messages will be permanently deleted.')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Annuler', 'Cancel'))),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Couleurs.rouge),
            onPressed: () => Navigator.pop(context, true),
            child: Text(tr('Supprimer', 'Delete')),
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
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 16),
        child: Center(child: Text(tr('Aucun message envoyé.', 'No message sent.'), style: TextStyle(color: Couleurs.texteSecondaire))),
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
                  Text('${m['reponse']}', maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 13)),
              ]),
            ),
          ),
        ),
    ]);
  }
}
