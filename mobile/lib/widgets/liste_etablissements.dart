import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';

import '../app.dart';
import '../core/api.dart';
import '../core/outils.dart';
import 'communs.dart';

/// Liste paginée d'établissements, triée par distance si la position est connue.
class ListeEtablissements extends StatefulWidget {
  const ListeEtablissements({super.key, required this.chemin, required this.filtres, this.entete, this.onTotal});

  final String chemin;
  final Map<String, dynamic> filtres;
  final Widget? entete;

  /// Nombre total de résultats (pour « Ville : Abidjan · 3 établissements »).
  final ValueChanged<int>? onTotal;

  @override
  State<ListeEtablissements> createState() => _ListeEtablissementsState();
}

class _ListeEtablissementsState extends State<ListeEtablissements> {
  final List<Map<String, dynamic>> _items = [];
  int _page = 1;
  int _dernierePage = 1;
  bool _chargement = false;
  String? _erreur;
  Position? _position;
  int _requete = 0;

  @override
  void initState() {
    super.initState();
    _charger(reinitialiser: true);
  }

  @override
  void didUpdateWidget(ListeEtablissements ancien) {
    super.didUpdateWidget(ancien);
    if (ancien.chemin != widget.chemin || !mapEquals(ancien.filtres, widget.filtres)) {
      _charger(reinitialiser: true);
    }
  }

  Future<void> _charger({bool reinitialiser = false}) async {
    final numero = ++_requete; // ignore les réponses de requêtes dépassées
    setState(() {
      _chargement = true;
      _erreur = null;
      if (reinitialiser) {
        _items.clear();
        _page = 1;
      }
    });

    _position ??= await Localisation.position(delai: const Duration(seconds: 6));

    try {
      final r = await Api.instance.get(widget.chemin, query: {
        ...widget.filtres,
        'lat': _position?.latitude,
        'lng': _position?.longitude,
        'page': _page,
        'par_page': 30,
      }) as Map;
      if (!mounted || numero != _requete) return;
      setState(() {
        _items.addAll((r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)));
        _dernierePage = (r['meta']?['last_page'] as num?)?.toInt() ?? 1;
      });
      widget.onTotal?.call((r['meta']?['total'] as num?)?.toInt() ?? _items.length);
    } on ApiException catch (e) {
      if (mounted && numero == _requete) setState(() => _erreur = e.message);
    } finally {
      if (mounted && numero == _requete) setState(() => _chargement = false);
    }
  }

  bool get _resteDesPages => _page < _dernierePage;

  void _pageSuivante() {
    if (_chargement || _erreur != null || !_resteDesPages) return;
    _page++;
    _charger();
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () => _charger(reinitialiser: true),
      // Chargement automatique de la page suivante à l'approche de la fin de liste
      child: NotificationListener<ScrollNotification>(
        onNotification: (n) {
          if (n.metrics.axis == Axis.vertical && n.metrics.extentAfter < 700) _pageSuivante();
          return false;
        },
        child: ListView.builder(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
          itemCount: _items.length + 2,
          itemBuilder: (context, i) {
            if (i == 0) return widget.entete ?? const SizedBox.shrink();
            if (i <= _items.length) return EtablissementTile(_items[i - 1]);

            if (_chargement) return const Chargement();
            if (_erreur != null) return MessageVide(_erreur!, icone: Icons.cloud_off, onReessayer: () => _charger(reinitialiser: _items.isEmpty));
            if (_items.isEmpty) return const MessageVide('Aucun résultat pour ces critères.', icone: Icons.search_off);
            if (_resteDesPages) {
              // Secours si le défilement n'a pas déclenché le chargement (liste plus courte que l'écran)
              return Center(child: TextButton(onPressed: _pageSuivante, child: const Text('Afficher plus')));
            }
            return Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Text('${_items.length} résultat${_items.length > 1 ? 's' : ''}',
                  textAlign: TextAlign.center, style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 12.5)),
            );
          },
        ),
      ),
    );
  }
}
