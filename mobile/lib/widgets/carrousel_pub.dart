import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/outils.dart';

/// ACC-02 : carrousel panoramique, défilement automatique, comptage impressions / clics.
/// N'est JAMAIS utilisé dans l'écran Alerte.
class CarrouselPub extends StatefulWidget {
  const CarrouselPub({super.key, required this.villeId});

  final int? villeId;

  @override
  State<CarrouselPub> createState() => _CarrouselPubState();
}

class _CarrouselPubState extends State<CarrouselPub> {
  final _page = PageController();
  List<Map<String, dynamic>> _pubs = [];
  Timer? _defilement;
  Timer? _envoi;
  final List<int> _impressions = [];
  final List<int> _clics = [];
  int _index = 0;

  @override
  void initState() {
    super.initState();
    _charger();
    _envoi = Timer.periodic(const Duration(seconds: 30), (_) => _envoyerStats());
  }

  @override
  void didUpdateWidget(CarrouselPub ancien) {
    super.didUpdateWidget(ancien);
    if (ancien.villeId != widget.villeId) _charger();
  }

  Future<void> _charger() async {
    try {
      final r = await Api.instance.get('/publicites', query: {'ville_id': widget.villeId});
      final liste = ((r as Map)['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      if (!mounted) return;
      setState(() {
        _pubs = liste;
        _index = 0;
      });
      if (liste.isNotEmpty) _impressions.add(liste.first['id'] as int);
      _defilement?.cancel();
      if (liste.length > 1) {
        _defilement = Timer.periodic(const Duration(seconds: 5), (_) {
          if (_page.hasClients) _page.animateToPage((_index + 1) % _pubs.length, duration: const Duration(milliseconds: 400), curve: Curves.easeInOut);
        });
      }
    } catch (_) {
      // Pas de publicité hors ligne : on n'affiche rien
    }
  }

  Future<void> _envoyerStats() async {
    if (_impressions.isEmpty && _clics.isEmpty) return;
    final impressions = List<int>.of(_impressions);
    final clics = List<int>.of(_clics);
    _impressions.clear();
    _clics.clear();
    try {
      await Api.instance.post('/publicites/stats', {'impressions': impressions, 'clics': clics});
    } catch (_) {}
  }

  @override
  void dispose() {
    _defilement?.cancel();
    _envoi?.cancel();
    _envoyerStats();
    _page.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_pubs.isEmpty) return const SizedBox.shrink();

    return Column(children: [
      AspectRatio(
        aspectRatio: 3,
        child: ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: PageView.builder(
            controller: _page,
            itemCount: _pubs.length,
            onPageChanged: (i) {
              setState(() => _index = i);
              _impressions.add(_pubs[i]['id'] as int);
            },
            itemBuilder: (_, i) {
              final pub = _pubs[i];
              return Semantics(
                label: 'Publicité : ${pub['titre'] ?? pub['annonceur']}',
                button: pub['lien'] != null,
                child: GestureDetector(
                  onTap: pub['lien'] == null
                      ? null
                      : () {
                          _clics.add(pub['id'] as int);
                          ouvrirLien('${pub['lien']}');
                        },
                  child: Image.network('${pub['image_url']}', fit: BoxFit.cover,
                      errorBuilder: (_, __, ___) => Container(color: Colors.black12)),
                ),
              );
            },
          ),
        ),
      ),
      const SizedBox(height: 4),
      Row(mainAxisAlignment: MainAxisAlignment.center, children: [
        for (var i = 0; i < _pubs.length; i++)
          Container(
            width: 6, height: 6, margin: const EdgeInsets.all(3),
            decoration: BoxDecoration(shape: BoxShape.circle, color: i == _index ? Colors.black54 : Colors.black12),
          ),
      ]),
      const Text('Publicité', style: TextStyle(fontSize: 11, color: Colors.black45)),
    ]);
  }
}
