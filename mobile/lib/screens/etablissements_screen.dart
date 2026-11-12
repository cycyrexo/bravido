import 'package:flutter/material.dart';

import '../app.dart';
import '../core/session.dart';
import '../widgets/communs.dart';
import '../widgets/liste_etablissements.dart';

/// Maquettes « Établissements publics / privés » (HOP-01, HOP-02),
/// « Centres de secours » (URG-06) et « Bureaux FGA » (URG-07).
class EtablissementsScreen extends StatefulWidget {
  const EtablissementsScreen({super.key, required this.titre, required this.categories, this.introduction})
      : avecFiltres = false;

  /// Hôpitaux & Cliniques : onglets par catégorie, filtres service et assurance.
  const EtablissementsScreen.soins({super.key})
      : titre = 'Hôpitaux & Cliniques',
        categories = const [],
        introduction = null,
        avecFiltres = true;

  final String titre;
  final List<String> categories;
  final String? introduction;
  final bool avecFiltres;

  @override
  State<EtablissementsScreen> createState() => _EtablissementsScreenState();
}

class _EtablissementsScreenState extends State<EtablissementsScreen> {
  // Libellés courts des onglets de la maquette
  static const _onglets = {
    'hopital_public': 'Publics',
    'hopital_prive': 'Privés',
    'militaire': 'Militaires',
    'veterinaire': 'Vétérinaires',
    'optique': 'Optique',
    'dentaire': 'Dentaires',
    'imagerie': 'Imagerie',
    'laboratoire': 'Laboratoires',
  };

  String? _categorie;
  String? _service;
  int? _assuranceId;
  String _recherche = '';
  int? _total;

  /// Onglets construits depuis les catégories du module « soins » : une nouvelle
  /// catégorie créée au back-office apparaît sans mise à jour de l'application.
  Map<String, String> get _ongletsDisponibles {
    final onglets = <String, String>{
      for (final c in Session.instance.categories.where((c) => c['module'] == 'soins'))
        '${c['code']}': _onglets['${c['code']}'] ?? '${c['libelle'] ?? c['code']}',
    };
    return onglets.isEmpty ? const {'hopital_public': 'Publics'} : onglets;
  }

  @override
  Widget build(BuildContext context) {
    final session = Session.instance;
    final onglets = _ongletsDisponibles;
    final categorie = onglets.containsKey(_categorie) ? _categorie! : onglets.keys.first;
    final codes = widget.avecFiltres ? [categorie] : widget.categories;

    return Scaffold(
      appBar: BarreTitre(widget.titre, actions: const [SosAction()]),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (widget.avecFiltres) ...[
              Puces<String>(options: onglets, valeur: categorie, onChanged: (v) => setState(() {
                _categorie = v;
                _total = null;
              })),
              const SizedBox(height: 8),
            ],
            Info(widget.introduction ??
                'Ville : ${session.nomVille}${_total == null ? '' : ' · $_total établissement${_total! > 1 ? 's' : ''}'}'),
            if (widget.avecFiltres) ...[
              TextField(
                textInputAction: TextInputAction.search,
                decoration: const InputDecoration(
                  hintText: 'Rechercher un établissement',
                  prefixIcon: Icon(Icons.search_rounded, size: 20, color: Couleurs.texteSecondaire),
                ),
                onSubmitted: (v) => setState(() => _recherche = v.trim()),
              ),
              const SizedBox(height: 8),
              Row(children: [
                Expanded(
                  child: DropdownButtonFormField<String?>(
                    initialValue: _service,
                    isExpanded: true,
                    items: [
                      const DropdownMenuItem(value: null, child: Text('Services', maxLines: 1, overflow: TextOverflow.ellipsis)),
                      for (final s in session.services) DropdownMenuItem(value: s, child: Text(s, overflow: TextOverflow.ellipsis)),
                    ],
                    onChanged: (v) => setState(() => _service = v),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: DropdownButtonFormField<int?>(
                    initialValue: _assuranceId,
                    isExpanded: true,
                    items: [
                      const DropdownMenuItem(value: null, child: Text('Assurances', maxLines: 1, overflow: TextOverflow.ellipsis)),
                      for (final a in session.assurances)
                        DropdownMenuItem(value: a['id'] as int, child: Text('${a['nom']}', overflow: TextOverflow.ellipsis)),
                    ],
                    onChanged: (v) => setState(() => _assuranceId = v),
                  ),
                ),
              ]),
              const SizedBox(height: 4),
            ],
          ]),
        ),
        Expanded(
          child: ListeEtablissements(
            chemin: '/etablissements',
            filtres: {
              'categorie': codes.join(','),
              'ville_id': session.villeId,
              'service': _service,
              'assurance_id': _assuranceId,
              'q': _recherche,
            },
            onTotal: (n) {
              if (mounted && n != _total) setState(() => _total = n);
            },
          ),
        ),
      ]),
    );
  }
}
