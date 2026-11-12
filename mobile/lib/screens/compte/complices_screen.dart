import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../core/cache.dart';
import '../../core/outils.dart';
import '../../widgets/communs.dart';

/// Maquette « Mes complices » (URG-01) + invitations reçues.
class ComplicesScreen extends StatefulWidget {
  const ComplicesScreen({super.key});

  @override
  State<ComplicesScreen> createState() => _ComplicesScreenState();
}

class _ComplicesScreenState extends State<ComplicesScreen> {
  final _nom = TextEditingController();
  final _telephone = TextEditingController();
  List<Map<String, dynamic>>? _complices;
  List<Map<String, dynamic>> _invitations = [];
  int _max = 7;
  String? _erreur;
  bool _envoi = false;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  @override
  void dispose() {
    _nom.dispose();
    _telephone.dispose();
    super.dispose();
  }

  Future<void> _charger() async {
    try {
      final r = await Future.wait([Api.instance.get('/complices'), Api.instance.get('/invitations')]);
      final complices = r[0] as Map;
      final liste = (complices['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      await Cache.ecrire('complices', liste); // utilisé par le SMS de secours hors ligne
      if (!mounted) return;
      setState(() {
        _complices = liste;
        _max = (complices['max'] as num?)?.toInt() ?? 7;
        _invitations = ((r[1] as Map)['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _erreur = null;
      });
    } on ApiException catch (e) {
      final cache = Cache.lireListe('complices');
      if (!mounted) return;
      setState(() {
        _complices = cache?.map((x) => Map<String, dynamic>.from(x as Map)).toList();
        _erreur = _complices == null ? e.message : null;
      });
    }
  }

  Future<void> _inviter() async {
    if (_nom.text.trim().isEmpty || _telephone.text.trim().isEmpty) {
      afficherMessage(context, 'Indiquez le nom et le téléphone.');
      return;
    }
    setState(() => _envoi = true);
    try {
      await Api.instance.post('/complices', {'nom': _nom.text.trim(), 'telephone': _telephone.text.trim()});
      _nom.clear();
      _telephone.clear();
      if (mounted) FocusScope.of(context).unfocus();
      await _charger();
      if (mounted) afficherMessage(context, 'Invitation envoyée.');
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    } finally {
      if (mounted) setState(() => _envoi = false);
    }
  }

  Future<void> _retirer(Map<String, dynamic> c) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Retirer ${c['nom']} ?'),
        content: const Text('Cette personne ne sera plus prévenue en cas d\'alerte.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Annuler')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Retirer')),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await Api.instance.delete('/complices/${c['id']}');
      await _charger();
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    }
  }

  Future<void> _repondre(Map<String, dynamic> i, bool accepter) async {
    try {
      await Api.instance.post('/invitations/${i['id']}/${accepter ? 'accepter' : 'refuser'}');
      await _charger();
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final complices = _complices;

    return Scaffold(
      appBar: const BarreTitre('Mes complices'),
      body: complices == null
          ? (_erreur != null ? MessageVide(_erreur!, onReessayer: _charger) : const Chargement())
          : RefreshIndicator(
              onRefresh: _charger,
              child: ListView(padding: const EdgeInsets.all(16), children: [
                Info('Jusqu\'à $_max personnes de confiance. Chacune doit accepter votre invitation pour recevoir vos alertes.'),
                for (final c in complices.where((c) => c['statut'] != 'refuse')) _ligne(c),
                if (complices.where((c) => c['statut'] != 'refuse').length < _max) _formulaire(),
                if (_invitations.isNotEmpty) ...[
                  const TitreSection('Invitations reçues'),
                  for (final i in _invitations) _invitation(i),
                ],
              ]),
            ),
    );
  }

  Widget _ligne(Map<String, dynamic> c) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 10, 6, 10),
        child: Row(children: [
          Avatar('${c['nom']}', taille: 40),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${c['nom']}', style: const TextStyle(fontWeight: FontWeight.w700)),
              Text('${c['telephone']}', style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 13)),
            ]),
          ),
          c['statut'] == 'accepte' ? const Pastille.verte('Accepté') : const Pastille.orange('Invitation envoyée'),
          IconButton(
            tooltip: 'Retirer ce complice',
            onPressed: () => _retirer(c),
            icon: const Icon(Icons.close_rounded, size: 18, color: Couleurs.texteSecondaire),
          ),
        ]),
      ),
    );
  }

  Widget _formulaire() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Nom', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 4),
          TextField(
            controller: _nom,
            textCapitalization: TextCapitalization.words,
            decoration: const InputDecoration(hintText: 'Ex. Mariam (sœur)'),
          ),
          const SizedBox(height: 10),
          const Text('Téléphone', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 4),
          TextField(controller: _telephone, keyboardType: TextInputType.phone, decoration: const InputDecoration(hintText: '07 00 00 00 00')),
          const SizedBox(height: 12),
          FilledButton.icon(
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
            onPressed: _envoi ? null : _inviter,
            icon: const Icon(Icons.person_add_alt_1_rounded, size: 18),
            label: Text(_envoi ? 'Envoi…' : 'Inviter'),
          ),
        ]),
      ),
    );
  }

  Widget _invitation(Map<String, dynamic> i) {
    final proprietaire = (i['proprietaire'] as Map?) ?? const {};
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text('${proprietaire['nom']}', style: const TextStyle(fontWeight: FontWeight.w700))),
            switch ('${i['statut']}') {
              'accepte' => const Pastille.verte('Accepté'),
              'refuse' => const Pastille.rouge('Refusé'),
              _ => const Pastille.orange('À confirmer'),
            },
          ]),
          // Sans nom renseigné, le titre affiche déjà le numéro
          if (proprietaire['nom'] != proprietaire['telephone'])
            Text('${proprietaire['telephone']}', style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 13)),
          const SizedBox(height: 8),
          if (i['statut'] == 'invite')
            Wrap(spacing: 8, children: [
              FilledButton(onPressed: () => _repondre(i, true), child: const Text('Accepter')),
              OutlinedButton(onPressed: () => _repondre(i, false), child: const Text('Refuser')),
            ])
          else if (i['statut'] == 'accepte')
            Wrap(spacing: 8, children: [
              TextButton.icon(
                onPressed: () => appeler('${proprietaire['telephone']}'),
                icon: const Icon(Icons.call, size: 18),
                label: const Text('Appeler'),
              ),
              TextButton(onPressed: () => _repondre(i, false), child: const Text('Quitter ce cercle')),
            ]),
        ]),
      ),
    );
  }
}
