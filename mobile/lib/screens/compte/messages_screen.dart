import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../core/outils.dart';
import '../../widgets/communs.dart';

/// USR-02 : observations, suggestions, préoccupations et suivi des réponses.
class MessagesScreen extends StatefulWidget {
  const MessagesScreen({super.key});

  @override
  State<MessagesScreen> createState() => _MessagesScreenState();
}

class _MessagesScreenState extends State<MessagesScreen> {
  static const _types = {
    'observation': 'Observation',
    'suggestion': 'Suggestion',
    'preoccupation': 'Préoccupation',
    'signalement': 'Signalement d\'erreur',
  };

  List<Map<String, dynamic>>? _messages;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    try {
      final r = await Api.instance.get('/messages') as Map;
      if (!mounted) return;
      setState(() {
        _messages = (r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _erreur = null;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    }
  }

  Future<void> _nouveau() async {
    var type = 'observation';
    final texte = TextEditingController();
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => StatefulBuilder(
        builder: (context, setState) => Padding(
          padding: EdgeInsets.fromLTRB(16, 0, 16, MediaQuery.of(context).viewInsets.bottom + 16),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Text('Écrire à BRAVIDO', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: 12),
            Wrap(spacing: 8, children: [
              for (final t in ['observation', 'suggestion', 'preoccupation'])
                ChoiceChip(label: Text(_types[t]!), selected: type == t, onSelected: (_) => setState(() => type = t)),
            ]),
            const SizedBox(height: 12),
            TextField(controller: texte, maxLines: 5, maxLength: 2000, decoration: const InputDecoration(hintText: 'Votre message')),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Envoyer')),
          ]),
        ),
      ),
    );
    if (ok != true || texte.text.trim().length < 5) return;

    try {
      await Api.instance.post('/messages', {'type': type, 'texte': texte.text.trim()});
      await _charger();
      if (mounted) afficherMessage(context, 'Message envoyé. Vous verrez notre réponse ici.');
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final messages = _messages;
    return Scaffold(
      appBar: const BarreTitre('Mes messages'),
      floatingActionButton: FloatingActionButton.extended(onPressed: _nouveau, icon: const Icon(Icons.edit), label: const Text('Écrire')),
      body: messages == null
          ? (_erreur != null ? MessageVide(_erreur!, onReessayer: _charger) : const Chargement())
          : RefreshIndicator(
              onRefresh: _charger,
              child: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 96), children: [
                if (messages.isEmpty) const MessageVide('Aucun message envoyé.', icone: Icons.forum_outlined),
                for (final m in messages)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Expanded(child: Text(_types[m['type']] ?? '${m['type']}', style: const TextStyle(fontWeight: FontWeight.w700))),
                          Text(formatDate('${m['created_at']}'), style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 12.5)),
                        ]),
                        if (m['etablissement'] != null) Text('${m['etablissement']['nom']}', style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 13)),
                        const SizedBox(height: 6),
                        Text('${m['texte']}', style: const TextStyle(height: 1.35)),
                        const SizedBox(height: 10),
                        if (m['reponse'] != null)
                          Container(
                            width: double.infinity,
                            padding: const EdgeInsets.all(12),
                            decoration: BoxDecoration(color: Couleurs.vertClair, borderRadius: BorderRadius.circular(12)),
                            child: Text.rich(TextSpan(children: [
                              const TextSpan(text: 'Réponse BRAVIDO\n', style: TextStyle(color: Color(0xFF146A66), fontWeight: FontWeight.w800, fontSize: 12.5)),
                              TextSpan(text: '${m['reponse']}'),
                            ]), style: const TextStyle(height: 1.4)),
                          )
                        else
                          Pastille.orange(m['statut'] == 'nouveau' ? 'Envoyé' : 'En cours de traitement'),
                      ]),
                    ),
                  ),
              ]),
            ),
    );
  }
}
