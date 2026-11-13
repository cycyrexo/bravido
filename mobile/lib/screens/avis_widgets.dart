import 'package:flutter/material.dart';

import '../app.dart';
import '../core/api.dart';
import '../core/langue.dart';
import '../core/session.dart';
import '../widgets/communs.dart';
import 'compte/compte_screen.dart';

/// Rangée d'étoiles (lecture seule ou sélection).
class Etoiles extends StatelessWidget {
  const Etoiles({super.key, required this.note, this.taille = 18, this.onChange});

  final double note;
  final double taille;
  final ValueChanged<int>? onChange;

  @override
  Widget build(BuildContext context) {
    return Row(mainAxisSize: MainAxisSize.min, children: [
      for (var i = 1; i <= 5; i++)
        Semantics(
          button: onChange != null,
          label: tr('$i étoile${i > 1 ? 's' : ''}', '$i star${i > 1 ? 's' : ''}'),
          child: InkResponse(
            onTap: onChange == null ? null : () => onChange!(i),
            radius: taille,
            child: Padding(
              padding: EdgeInsets.all(onChange == null ? 0 : 4),
              child: Icon(note >= i - 0.25 ? Icons.star_rounded : (note >= i - 0.75 ? Icons.star_half_rounded : Icons.star_outline_rounded), size: taille, color: Couleurs.encreOrange),
            ),
          ),
        ),
    ]);
  }
}

/// F13 : note moyenne, derniers avis publiés et bouton « Donner mon avis » sur la fiche d'un établissement.
class BlocAvis extends StatefulWidget {
  const BlocAvis({super.key, required this.etablissementId});

  final int etablissementId;

  @override
  State<BlocAvis> createState() => _BlocAvisState();
}

class _BlocAvisState extends State<BlocAvis> {
  Map<String, dynamic>? _meta;
  List<Map<String, dynamic>> _avis = [];
  bool _chargee = false;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    try {
      final r = await Api.instance.get('/etablissements/${widget.etablissementId}/avis') as Map;
      if (!mounted) return;
      setState(() {
        _meta = Map<String, dynamic>.from(r['meta'] as Map);
        _avis = (r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _chargee = true;
      });
    } catch (_) {
      // hors ligne : pas de bloc d'avis
    }
  }

  Future<void> _donnerAvis() async {
    if (!Session.instance.connecte) {
      afficherMessage(context, tr('Connectez-vous pour donner votre avis.', 'Sign in to leave a review.'));
      Navigator.push(context, MaterialPageRoute(builder: (_) => const CompteScreen()));
      return;
    }
    final ok = await ouvrirFormulaireAvis(context, widget.etablissementId, _meta?['mon_avis'] as Map?);
    if (ok == true) _charger();
  }

  Future<void> _signaler(Map<String, dynamic> a) async {
    if (!Session.instance.connecte) {
      afficherMessage(context, tr('Connectez-vous pour signaler un avis.', 'Sign in to report a review.'));
      return;
    }
    try {
      final r = await Api.instance.post('/avis/${a['id']}/signaler') as Map;
      if (mounted) afficherMessage(context, '${r['message']}');
      _charger();
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!_chargee || _meta == null || _meta!['actif'] != true) return const SizedBox.shrink();
    final theme = Theme.of(context);
    final total = (_meta!['total'] as num).toInt();
    final moyenne = (_meta!['moyenne'] as num?)?.toDouble();
    final mien = _meta!['mon_avis'] as Map?;

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      const SizedBox(height: 14),
      Row(children: [
        Text(tr('Avis', 'Reviews'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
        const SizedBox(width: 10),
        if (moyenne != null) ...[
          Etoiles(note: moyenne),
          const SizedBox(width: 6),
          Text('${moyenne.toStringAsFixed(1).replaceAll('.', tr(',', '.'))} · $total', style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 13)),
        ] else
          Text(tr('Pas encore d\'avis', 'No reviews yet'), style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 13)),
      ]),
      for (final a in _avis.take(3))
        Padding(
          padding: const EdgeInsets.only(top: 10),
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.45), borderRadius: BorderRadius.circular(12)),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Etoiles(note: (a['note'] as num).toDouble(), taille: 15),
                const SizedBox(width: 8),
                Expanded(child: Text('${a['auteur']}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13))),
                PopupMenuButton<String>(
                  tooltip: tr('Plus', 'More'),
                  icon: Icon(Icons.more_vert_rounded, size: 18, color: Couleurs.texteSecondaire),
                  onSelected: (_) => _signaler(a),
                  itemBuilder: (_) => [PopupMenuItem(value: 'signaler', child: Text(tr('Signaler cet avis', 'Report this review')))],
                ),
              ]),
              if (a['commentaire'] != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text('${a['commentaire']}', style: const TextStyle(height: 1.35))),
              if (a['reponse'] is Map)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Container(
                    padding: const EdgeInsets.all(10),
                    decoration: const BoxDecoration(border: Border(left: BorderSide(color: Couleurs.sarcelle, width: 3))),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('Réponse de l\'établissement', 'Reply from the facility'), style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 12, fontWeight: FontWeight.w700)),
                      Text('${(a['reponse'] as Map)['texte']}', style: const TextStyle(height: 1.35)),
                    ]),
                  ),
                ),
            ]),
          ),
        ),
      const SizedBox(height: 8),
      if (mien != null && mien['statut'] != 'approuve')
        Padding(
          padding: const EdgeInsets.only(bottom: 6),
          child: Text(
            mien['statut'] == 'rejete'
                ? tr('Votre avis n\'a pas été publié : ${mien['motif_rejet'] ?? ''}', 'Your review was not published: ${mien['motif_rejet'] ?? ''}')
                : tr('Votre avis est en cours de vérification avant publication.', 'Your review is being checked before publication.'),
            style: TextStyle(color: Couleurs.encreOrange, fontSize: 12.5),
          ),
        ),
      OutlinedButton.icon(
        style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(46)),
        onPressed: _donnerAvis,
        icon: const Icon(Icons.rate_review_outlined, size: 18),
        label: Text(mien == null ? tr('Donner mon avis', 'Leave a review') : tr('Modifier mon avis', 'Edit my review')),
      ),
    ]);
  }
}

/// Formulaire d'avis (note + commentaire facultatif). Retourne true si l'avis a été enregistré ou supprimé.
Future<bool?> ouvrirFormulaireAvis(BuildContext context, int etablissementId, Map? existant) {
  return showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (_) => Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: FormulaireAvis(etablissementId: etablissementId, existant: existant),
    ),
  );
}

class FormulaireAvis extends StatefulWidget {
  const FormulaireAvis({super.key, required this.etablissementId, this.existant});

  final int etablissementId;
  final Map? existant;

  @override
  State<FormulaireAvis> createState() => _FormulaireAvisState();
}

class _FormulaireAvisState extends State<FormulaireAvis> {
  late int _note = (widget.existant?['note'] as num?)?.toInt() ?? 0;
  late final _texte = TextEditingController(text: '${widget.existant?['commentaire'] ?? ''}');
  bool _envoi = false;
  String? _erreur;

  @override
  void dispose() {
    _texte.dispose();
    super.dispose();
  }

  Future<void> _envoyer() async {
    if (_note == 0) {
      setState(() => _erreur = tr('Choisissez une note de 1 à 5.', 'Choose a rating from 1 to 5.'));
      return;
    }
    setState(() {
      _envoi = true;
      _erreur = null;
    });
    final corps = {'note': _note, 'commentaire': _texte.text.trim()};
    try {
      final chemin = '/etablissements/${widget.etablissementId}/mon-avis';
      widget.existant == null ? await Api.instance.post(chemin, corps) : await Api.instance.put(chemin, corps);
      if (!mounted) return;
      Navigator.pop(context, true);
      afficherMessage(context, tr('Merci ! Votre avis sera publié après vérification.', 'Thank you! Your review will be published after review.'));
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    } finally {
      if (mounted) setState(() => _envoi = false);
    }
  }

  Future<void> _supprimer() async {
    setState(() => _envoi = true);
    try {
      await Api.instance.delete('/etablissements/${widget.etablissementId}/mon-avis');
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    } finally {
      if (mounted) setState(() => _envoi = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(widget.existant == null ? tr('Donner mon avis', 'Leave a review') : tr('Modifier mon avis', 'Edit my review'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
        const SizedBox(height: 10),
        Center(child: Etoiles(note: _note.toDouble(), taille: 38, onChange: (n) => setState(() => _note = n))),
        const SizedBox(height: 10),
        TextField(
          controller: _texte,
          maxLength: 500,
          maxLines: 4,
          decoration: InputDecoration(hintText: tr('Votre commentaire (facultatif)', 'Your comment (optional)')),
        ),
        Container(
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(color: Couleurs.sombre ? const Color(0xFF4A3410) : const Color(0xFFFFF1D6), borderRadius: BorderRadius.circular(10)),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(Icons.health_and_safety_outlined, size: 18, color: Couleurs.encreOrange),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                tr('Ne mentionnez ni diagnostic ni information médicale personnelle sur d\'autres personnes. Votre avis est vérifié avant d\'être publié.',
                    'Do not mention any diagnosis or personal medical information about other people. Your review is checked before it is published.'),
                style: TextStyle(fontSize: 12.5, height: 1.35, color: theme.colorScheme.onSurface),
              ),
            ),
          ]),
        ),
        if (_erreur != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_erreur!, style: TextStyle(color: theme.colorScheme.error))),
        const SizedBox(height: 12),
        FilledButton(
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
          onPressed: _envoi ? null : _envoyer,
          child: Text(tr('Envoyer', 'Send')),
        ),
        if (widget.existant != null)
          TextButton(
            style: TextButton.styleFrom(foregroundColor: theme.colorScheme.error, minimumSize: const Size.fromHeight(44)),
            onPressed: _envoi ? null : _supprimer,
            child: Text(tr('Supprimer mon avis', 'Delete my review')),
          ),
      ]),
    );
  }
}
