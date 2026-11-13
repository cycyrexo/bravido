import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../core/langue.dart';
import '../../widgets/communs.dart';
import '../avis_widgets.dart';

/// F13 : mes avis et leur état de modération (Espace utilisateur).
class MesAvisScreen extends StatefulWidget {
  const MesAvisScreen({super.key});

  @override
  State<MesAvisScreen> createState() => _MesAvisScreenState();
}

class _MesAvisScreenState extends State<MesAvisScreen> {
  List<Map<String, dynamic>>? _avis;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    try {
      final r = await Api.instance.get('/mes-avis') as Map;
      if (mounted) setState(() => _avis = (r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList());
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    }
  }

  Widget _etat(Map<String, dynamic> a) => switch (a['statut']) {
        'approuve' => Pastille.verte(tr('Publié', 'Published')),
        'rejete' => Pastille.orange(tr('Non publié', 'Not published')),
        _ => Pastille.grise(tr('En vérification', 'Under review')),
      };

  Future<void> _modifier(Map<String, dynamic> a) async {
    final ok = await ouvrirFormulaireAvis(context, (a['etablissement'] as Map)['id'] as int, a);
    if (ok == true) _charger();
  }

  @override
  Widget build(BuildContext context) {
    final avis = _avis;
    return Scaffold(
      appBar: BarreTitre(tr('Mes avis', 'My reviews')),
      body: avis == null
          ? (_erreur != null ? MessageVide(_erreur!, icone: Icons.cloud_off, onReessayer: _charger) : const Chargement())
          : avis.isEmpty
              ? MessageVide(tr('Vous n\'avez pas encore donné d\'avis. Ouvrez la fiche d\'un établissement pour en laisser un.', 'You have not left any reviews yet. Open a facility page to leave one.'), icone: Icons.rate_review_outlined)
              : RefreshIndicator(
                  onRefresh: _charger,
                  child: ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                    itemCount: avis.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 10),
                    itemBuilder: (context, i) {
                      final a = avis[i];
                      final e = a['etablissement'] as Map;
                      return Card(
                        child: InkWell(
                          onTap: () => _modifier(a),
                          child: Padding(
                            padding: const EdgeInsets.all(14),
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Row(children: [
                                Expanded(child: Text('${e['nom']}', style: const TextStyle(fontWeight: FontWeight.w800))),
                                _etat(a),
                              ]),
                              const SizedBox(height: 4),
                              Etoiles(note: (a['note'] as num).toDouble()),
                              if (a['commentaire'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text('${a['commentaire']}')),
                              if (a['statut'] == 'rejete' && a['motif_rejet'] != null)
                                Padding(padding: const EdgeInsets.only(top: 6), child: Text('${a['motif_rejet']}', style: TextStyle(color: Couleurs.encreOrange, fontSize: 12.5))),
                              const SizedBox(height: 4),
                              Text(tr('Touchez pour modifier ou supprimer', 'Tap to edit or delete'), style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 12)),
                            ]),
                          ),
                        ),
                      );
                    },
                  ),
                ),
    );
  }
}
