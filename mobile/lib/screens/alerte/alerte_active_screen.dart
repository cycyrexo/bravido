import 'dart:async';

import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../core/outils.dart';
import '../../core/suivi_alerte.dart';
import '../../widgets/communs.dart';
import 'sos_screen.dart';

/// Suivi de l'alerte par la personne protégée (URG-03, URG-04) :
/// envoi de la position toutes les 30 s pendant 1 h, état de chaque complice.
class AlerteActiveScreen extends StatefulWidget {
  const AlerteActiveScreen({super.key, required this.alerte});

  final Map<String, dynamic> alerte;

  @override
  State<AlerteActiveScreen> createState() => _AlerteActiveScreenState();
}

class _AlerteActiveScreenState extends State<AlerteActiveScreen> {
  late Map<String, dynamic> _alerte = widget.alerte;
  Timer? _minuteurSuivi;
  bool _termine = false;

  int get _id => _alerte['id'] as int;
  bool get _active => _alerte['statut'] == 'active';

  @override
  void initState() {
    super.initState();
    if (_active) {
      // Le suivi de position continue même si l'utilisateur quitte cet écran
      SuiviAlerte.demarrer(_id, intervalleS: (_alerte['intervalle_position_s'] as num?)?.toInt() ?? 30);
      _minuteurSuivi = Timer.periodic(const Duration(seconds: 5), (_) => _rafraichir());
    }
    _rafraichir();
  }

  @override
  void dispose() {
    _minuteurSuivi?.cancel();
    super.dispose();
  }

  void _arreter() {
    _minuteurSuivi?.cancel();
    SuiviAlerte.arreter();
  }

  Future<void> _rafraichir() async {
    try {
      final r = await Api.instance.get('/alertes/$_id');
      // Une réponse lancée avant la clôture ne doit pas réafficher l'alerte comme active
      if (!mounted || _termine) return;
      setState(() => _alerte = Map<String, dynamic>.from((r as Map)['data'] as Map));
      if (!_active) _arreter();
    } catch (_) {}
  }

  Future<void> _terminer({required bool annuler}) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(annuler ? 'Annuler l\'alerte ?' : 'Clôturer l\'alerte ?'),
        content: Text(annuler
            ? 'Vos complices seront prévenus qu\'il s\'agissait d\'une erreur.'
            : 'Vos complices seront prévenus que vous êtes en sécurité. Le partage de position s\'arrête.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Non')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Oui')),
        ],
      ),
    );
    if (ok != true) return;

    _minuteurSuivi?.cancel();
    try {
      final r = await Api.instance.post('/alertes/$_id/${annuler ? 'annuler' : 'cloturer'}');
      _termine = true;
      _arreter();
      if (!mounted) return;
      setState(() => _alerte = Map<String, dynamic>.from((r as Map)['data'] as Map));
    } on ApiException catch (e) {
      if (mounted && _active) {
        _minuteurSuivi = Timer.periodic(const Duration(seconds: 5), (_) => _rafraichir());
      }
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final destinataires = ((_alerte['destinataires'] as List?) ?? const []).cast<Map>();

    return Scaffold(
      appBar: BarreTitre(_active ? 'Alerte en cours' : 'Alerte terminée'),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 6, 16, 28),
        children: [
          // Bandeau de la maquette : « Alerte « Malaise » active · Position partagée... »
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: _active ? Couleurs.rougeClair : Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: _active ? const Color(0xFFF3C4C0) : Couleurs.bord),
              boxShadow: _active ? null : Couleurs.ombre,
            ),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(color: _active ? Couleurs.rouge : Couleurs.vertClair, shape: BoxShape.circle),
                child: Icon(_active ? Icons.sensors_rounded : Icons.check_rounded, color: _active ? Colors.white : Couleurs.vert, size: 24),
              ),
              const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(
                _active ? 'Alerte « ${_alerte['motif_libelle']} » active' : 'Alerte « ${_alerte['motif_libelle']} » terminée',
                style: TextStyle(color: _active ? Couleurs.rougeFonce : Couleurs.texte, fontWeight: FontWeight.w800, fontSize: 16),
              ),
              const SizedBox(height: 4),
              Text(
                _active
                    ? 'Position partagée · mise à jour toutes les 30 s jusqu\'à ${formatHeure('${_alerte['expires_at']}')}, '
                        'même téléphone verrouillé.'
                    : 'Déclenchée à ${formatHeure('${_alerte['started_at']}')}. Le partage de position est arrêté.',
                style: const TextStyle(color: Color(0xFF4A5A62), fontSize: 13, height: 1.35),
              ),
              ValueListenableBuilder<DateTime?>(
                valueListenable: SuiviAlerte.dernierEnvoi,
                builder: (_, date, __) => date == null || !_active
                    ? const SizedBox.shrink()
                    : Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: Pastille.rouge('Dernière position envoyée à ${formatHeure(date.toIso8601String())}'),
                      ),
              ),
            ])),
            ]),
          ),
          const TitreSection('Vos complices'),
          for (final d in destinataires) _ligneComplice(d),
          if (destinataires.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 8),
              child: Text('Aucun complice prévenu.', style: TextStyle(color: Couleurs.texteSecondaire)),
            ),
          const SizedBox(height: 14),
          const Row(children: [
            Expanded(child: BoutonUrgence('185', 'SAMU')),
            SizedBox(width: 12),
            Expanded(child: BoutonUrgence('180', 'Pompiers')),
          ]),
          if (_active) ...[
            const SizedBox(height: 12),
            FilledButton.icon(
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
              onPressed: () => _terminer(annuler: false),
              icon: const Icon(Icons.verified_user_outlined, size: 19),
              label: const Text('Je suis en sécurité'),
            ),
            const SizedBox(height: 4),
            TextButton(
              style: TextButton.styleFrom(foregroundColor: Couleurs.texteSecondaire, minimumSize: const Size.fromHeight(46)),
              onPressed: () => _terminer(annuler: true),
              child: const Text('Fausse manipulation : annuler l\'alerte'),
            ),
          ],
        ],
      ),
    );
  }

  Widget _ligneComplice(Map d) {
    final pastille = switch ('${d['etat']}') {
      'j_arrive' => const Pastille.verte('J\'arrive'),
      'appelle_secours' => const Pastille.rouge('Appelle les secours'),
      'vue' => const Pastille.verte('Vue'),
      'recue' => const Pastille.verte('Reçue'),
      'envoyee' => const Pastille.grise('Envoyée'),
      _ => const Pastille.orange('En attente'),
    };

    return Card(
      child: InkWell(
        onTap: () => appeler('${d['telephone']}'),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Avatar('${d['nom']}', taille: 40),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${d['nom']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                Text(d['canal_sms'] == true ? 'Push + SMS de secours' : 'Notification push',
                    style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 13)),
              ]),
            ),
            const SizedBox(width: 8),
            pastille,
          ]),
        ),
      ),
    );
  }
}
