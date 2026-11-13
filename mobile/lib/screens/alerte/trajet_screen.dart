import 'dart:async';
import '../../core/langue.dart';

import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../core/outils.dart';
import '../../core/session.dart';
import '../../widgets/communs.dart';
import '../compte/complices_screen.dart';
import 'alerte_active_screen.dart';

/// Partage périodique de la position pendant un trajet surveillé (premier plan uniquement).
class SuiviTrajet {
  static Timer? _minuteur;
  static int? _id;

  static void demarrer(int trajetId, int intervalleS) {
    if (_id == trajetId && _minuteur != null) return;
    arreter();
    _id = trajetId;
    Future<void> envoyer() async {
      final id = _id;
      if (id == null) return;
      final p = await Localisation.position(demander: false, delai: const Duration(seconds: 8));
      if (p == null || _id != id) return;
      try {
        await Api.instance.post('/trajets/$id/positions', {
          'lat': p.latitude,
          'lng': p.longitude,
          'precision_m': p.accuracy.round(),
        });
      } on ApiException catch (e) {
        if (e.status == 409 || e.status == 404) arreter(); // trajet terminé côté serveur
      }
    }

    envoyer();
    _minuteur = Timer.periodic(Duration(seconds: intervalleS), (_) => envoyer());
  }

  static void arreter() {
    _minuteur?.cancel();
    _minuteur = null;
    _id = null;
  }
}

/// « Je rentre chez moi » : trajet surveillé. Sans confirmation d'arrivée à l'échéance (+ 2 min de délai),
/// vos complices choisis sont alertés automatiquement.
class TrajetScreen extends StatefulWidget {
  const TrajetScreen({super.key});

  @override
  State<TrajetScreen> createState() => _TrajetScreenState();
}

class _TrajetScreenState extends State<TrajetScreen> {
  static const _durees = [15, 30, 45, 60, 90, 120, 180];

  Map<String, dynamic>? _trajet;
  List<Map<String, dynamic>> _complices = [];
  final Set<int> _choisis = {};
  int _duree = 30;
  bool _partage = false;
  bool _chargement = true;
  bool _envoi = false;
  String? _erreur;

  Timer? _tic;
  Timer? _sondage;
  Duration _decalage = Duration.zero; // serveur - appareil
  Duration _reste = Duration.zero;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  @override
  void dispose() {
    _tic?.cancel();
    _sondage?.cancel();
    super.dispose();
  }

  Future<void> _charger() async {
    if (!Session.instance.connecte) {
      setState(() => _chargement = false);
      return;
    }
    try {
      final r = await Api.instance.get('/trajets/en-cours') as Map;
      final c = await Api.instance.get('/complices') as Map;
      if (!mounted) return;
      _complices = [
        for (final x in c['data'] as List)
          if (x is Map && x['statut'] == 'accepte') Map<String, dynamic>.from(x),
      ];
      _choisis
        ..clear()
        ..addAll(_complices.map((x) => x['id'] as int)); // par défaut : tous
      _appliquer(r['data'] == null ? null : Map<String, dynamic>.from(r['data'] as Map));
      setState(() => _chargement = false);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _erreur = e.message;
          _chargement = false;
        });
      }
    }
  }

  void _appliquer(Map<String, dynamic>? t) {
    _trajet = t;
    _tic?.cancel();
    _sondage?.cancel();
    if (t == null) {
      SuiviTrajet.arreter();
      return;
    }
    final serveur = DateTime.tryParse('${t['serveur_now']}');
    _decalage = serveur == null ? Duration.zero : serveur.difference(DateTime.now());
    _calculerReste();
    if (t['statut'] == 'en_cours') {
      _tic = Timer.periodic(const Duration(seconds: 1), (_) => _calculerReste());
      _sondage = Timer.periodic(const Duration(seconds: 15), (_) => _rafraichir());
      if (t['partage_position'] == true) {
        SuiviTrajet.demarrer(t['id'] as int, (t['intervalle_position_s'] as num?)?.toInt() ?? 60);
      }
    } else {
      SuiviTrajet.arreter();
    }
  }

  void _calculerReste() {
    final e = DateTime.tryParse('${_trajet?['echeance_at']}');
    if (e == null || !mounted) return;
    setState(() => _reste = e.difference(DateTime.now().add(_decalage)));
  }

  Future<void> _rafraichir() async {
    try {
      final r = await Api.instance.get('/trajets/en-cours') as Map;
      if (!mounted) return;
      final t = r['data'] == null ? null : Map<String, dynamic>.from(r['data'] as Map);
      setState(() => _appliquer(t));
    } catch (_) {}
  }

  Future<void> _demarrer() async {
    setState(() {
      _envoi = true;
      _erreur = null;
    });
    final position = _partage ? await Localisation.position(delai: const Duration(seconds: 6)) : null;
    try {
      final r = await Api.instance.post('/trajets', {
        'duree_minutes': _duree,
        'complice_ids': _choisis.toList(),
        'partage_position': _partage,
        if (position != null) 'lat': position.latitude,
        if (position != null) 'lng': position.longitude,
        if (position != null) 'precision_m': position.accuracy.round(),
      }) as Map;
      if (mounted) setState(() => _appliquer(Map<String, dynamic>.from(r['data'] as Map)));
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    } finally {
      if (mounted) setState(() => _envoi = false);
    }
  }

  Future<void> _arrive() async {
    final id = _trajet!['id'];
    setState(() => _envoi = true);
    try {
      await Api.instance.post('/trajets/$id/arrive');
      SuiviTrajet.arreter();
      if (!mounted) return;
      setState(() => _appliquer(null));
      afficherMessage(context, tr('Content que vous soyez bien arrivé(e). Surveillance terminée.', 'Glad you arrived safely. Monitoring ended.'));
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    } finally {
      if (mounted) setState(() => _envoi = false);
    }
  }

  Future<void> _prolonger() async {
    final id = _trajet!['id'];
    setState(() => _envoi = true);
    try {
      final r = await Api.instance.post('/trajets/$id/prolonger') as Map;
      if (mounted) setState(() => _appliquer(Map<String, dynamic>.from(r['data'] as Map)));
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    } finally {
      if (mounted) setState(() => _envoi = false);
    }
  }

  Future<void> _ouvrirAlerte() async {
    try {
      final r = await Api.instance.get('/alertes/${_trajet!['alerte_id']}') as Map;
      if (!mounted) return;
      await Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => AlerteActiveScreen(alerte: Map<String, dynamic>.from(r['data'] as Map))),
      );
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: BarreTitre(tr('Je rentre chez moi', 'Heading home'), actions: const [SosAction()]),
      body: _chargement
          ? const Chargement()
          : !Session.instance.connecte
              ? Padding(
                  padding: const EdgeInsets.all(24),
                  child: MessageVide(tr('Connectez-vous (Espace Utilisateur) pour utiliser le trajet surveillé.', 'Sign in (My Account) to use the monitored trip.'), icone: Icons.lock_outline),
                )
              : ListView(
                  padding: const EdgeInsets.fromLTRB(18, 8, 18, 28),
                  children: [
                    if (_erreur != null) Bandeau(_erreur!, icone: Icons.error_outline),
                    if (_trajet == null) ..._formulaire() else ..._suivi(),
                  ],
                ),
    );
  }

  // ----- Préparation -----------------------------------------------------------------------------
  List<Widget> _formulaire() {
    return [
      Info(tr('Indiquez la durée prévue de votre trajet. Si vous n\'avez pas confirmé votre arrivée à l\'échéance '
          '(plus 2 minutes de délai), vos complices choisis sont alertés automatiquement avec votre dernière position connue.',
          'Enter the expected duration of your trip. If you have not confirmed your arrival by the deadline '
          '(plus a 2-minute grace period), your chosen accomplices are alerted automatically with your last known position.')),
      TitreSection(tr('Durée du trajet', 'Trip duration')),
      Wrap(spacing: 8, runSpacing: 8, children: [
        for (final d in _durees)
          ChoiceChip(
            label: Text(d < 60 ? '$d min' : (d % 60 == 0 ? '${d ~/ 60} h' : '${d ~/ 60} h ${d % 60}')),
            selected: _duree == d,
            labelStyle: TextStyle(color: _duree == d ? Colors.white : Couleurs.texte, fontWeight: FontWeight.w700, fontSize: 13),
            onSelected: (_) => setState(() => _duree = d),
          ),
      ]),
      TitreSection(tr('Qui suit mon trajet ?', 'Who follows my trip?')),
      if (_complices.isEmpty)
        Column(children: [
          Bandeau(tr('Aucun complice n\'a encore accepté votre invitation.', 'No accomplice has accepted your invitation yet.'), icone: Icons.group_add),
          OutlinedButton.icon(
            style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48), backgroundColor: Couleurs.surface),
            onPressed: () async {
              await Navigator.push(context, MaterialPageRoute(builder: (_) => const ComplicesScreen()));
              _charger();
            },
            icon: const Icon(Icons.group_add_rounded, size: 18),
            label: Text(tr('Ajouter mes complices', 'Add my accomplices')),
          ),
        ])
      else
        Card(
          child: Column(children: [
            for (final c in _complices)
              CheckboxListTile(
                value: _choisis.contains(c['id']),
                title: Text('${c['nom']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text('${c['telephone'] ?? ''}'),
                onChanged: (v) => setState(() => v == true ? _choisis.add(c['id'] as int) : _choisis.remove(c['id'])),
              ),
          ]),
        ),
      const SizedBox(height: 10),
      Card(
        child: SwitchListTile(
          value: _partage,
          onChanged: (v) => setState(() => _partage = v),
          title: Text(tr('Partager ma position pendant le trajet', 'Share my location during the trip'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
          subtitle: Text(tr('Facultatif. Position envoyée toutes les 60 s (application ouverte) et visible seulement par les complices choisis.', 'Optional. Location sent every 60 s (app open) and visible only to the chosen accomplices.')),
        ),
      ),
      const SizedBox(height: 18),
      FilledButton.icon(
        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(54), backgroundColor: Couleurs.vert),
        onPressed: _envoi || (_complices.isNotEmpty && _choisis.isEmpty) ? null : _demarrer,
        icon: _envoi
            ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
            : const Icon(Icons.directions_walk_rounded),
        label: Text(tr('Démarrer mon trajet', 'Start my trip'), style:const  TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
      ),
      const SizedBox(height: 12),
      Text(
        tr('BRAVIDO ne remplace pas les secours : en cas d\'urgence, appelez le 185 (SAMU) ou le 180 (pompiers).', 'BRAVIDO does not replace emergency services: in an emergency, call 185 (SAMU) or 180 (fire brigade).'),
        textAlign: TextAlign.center,
        style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 12.5),
      ),
    ];
  }

  // ----- Trajet en cours -------------------------------------------------------------------------
  String _mmss(Duration d) {
    final s = d.inSeconds.abs();
    final h = s ~/ 3600;
    final m = (s % 3600) ~/ 60;
    final sec = s % 60;
    final mm = m.toString().padLeft(2, '0');
    final ss = sec.toString().padLeft(2, '0');
    return h > 0 ? '$h:$mm:$ss' : '$mm:$ss';
  }

  List<Widget> _suivi() {
    final t = _trajet!;
    final alerte = t['statut'] == 'alerte';
    final depasse = !alerte && _reste.isNegative;
    final grace = (t['grace_minutes'] as num?)?.toInt() ?? 2;
    final resteGrace = Duration(minutes: grace) + _reste;
    final restantes = (t['extensions_restantes'] as num?)?.toInt() ?? 0;
    final noms = [for (final c in (t['complices'] as List? ?? [])) '${(c as Map)['nom']}'];

    if (alerte) {
      return [
        Bandeau(tr('Votre arrivée n\'a pas été confirmée : une alerte a été envoyée à vos complices.', 'Your arrival was not confirmed: an alert has been sent to your accomplices.'),
            couleur: Couleurs.rouge, icone: Icons.warning_amber_rounded),
        const SizedBox(height: 8),
        FilledButton.icon(
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(54), backgroundColor: Couleurs.rouge),
          onPressed: _ouvrirAlerte,
          icon: const Icon(Icons.crisis_alert_rounded),
          label: Text(tr('Voir l\'alerte en cours', 'View the alert in progress'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
        ),
      ];
    }

    final couleur = depasse ? Couleurs.rouge : Couleurs.vert;
    return [
      Container(
        padding: const EdgeInsets.symmetric(vertical: 22, horizontal: 16),
        decoration: BoxDecoration(
          color: depasse ? Couleurs.rougeClair : Couleurs.vertClair,
          borderRadius: BorderRadius.circular(20),
        ),
        child: Column(children: [
          Text(depasse ? tr('Délai dépassé, confirmez votre arrivée', 'Time exceeded, confirm your arrival') : tr('Arrivée prévue dans', 'Arrival expected in'),
              style: TextStyle(color: couleur, fontWeight: FontWeight.w700, fontSize: 14)),
          const SizedBox(height: 6),
          Semantics(
            liveRegion: false,
            label: depasse ? tr('Alerte automatique dans ${_mmss(resteGrace)}', 'Automatic alert in ${_mmss(resteGrace)}') : tr('Temps restant ${_mmss(_reste)}', 'Time left ${_mmss(_reste)}'),
            child: Text(depasse ? _mmss(resteGrace.isNegative ? Duration.zero : resteGrace) : _mmss(_reste),
                style: TextStyle(color: couleur, fontSize: 58, fontWeight: FontWeight.w900, height: 1.1, fontFeatures: const [FontFeature.tabularFigures()])),
          ),
          if (depasse)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(tr('avant l\'alerte automatique à vos complices', 'before the automatic alert to your accomplices'),
                  textAlign: TextAlign.center, style: TextStyle(color: Couleurs.encreRouge, fontSize: 13)),
            ),
        ]),
      ),
      const SizedBox(height: 14),
      Wrap(spacing: 8, runSpacing: 6, children: [
        for (final n in noms) Pastille.verte(n),
        if (t['partage_position'] == true) const Pastille.orange('Position partagée'),
      ]),
      const SizedBox(height: 18),
      FilledButton.icon(
        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(64), backgroundColor: Couleurs.vert),
        onPressed: _envoi ? null : _arrive,
        icon: const Icon(Icons.home_rounded, size: 26),
        label: Text(tr('Je suis arrivé(e)', 'I have arrived'), style:const  TextStyle(fontWeight: FontWeight.w900, fontSize: 19)),
      ),
      const SizedBox(height: 10),
      OutlinedButton.icon(
        style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(50), backgroundColor: Couleurs.surface),
        onPressed: _envoi || restantes == 0 ? null : _prolonger,
        icon: const Icon(Icons.more_time_rounded, size: 20),
        label: Text(restantes == 0
            ? tr('Prolongations épuisées', 'No extensions left')
            : tr(tr('+ ${t['prolongation_minutes'] ?? 15} min ($restantes restante${restantes > 1 ? 's' : ''})', '+ ${t['prolongation_minutes'] ?? 15} min ($restantes left)'), '+ ${t['prolongation_minutes'] ?? 15} min ($restantes left)')),
      ),
      const SizedBox(height: 16),
      Info(tr('Gardez l\'application ouverte pour que la position soit partagée. '
          'Si vous êtes en danger, utilisez le bouton SOS (appui long).', 'Keep the app open so your location is shared. '
          'If you are in danger, use the SOS button (long press).')),
    ];
  }
}
