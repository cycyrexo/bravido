import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_map_marker_cluster/flutter_map_marker_cluster.dart';
import 'package:latlong2/latlong.dart';

import '../app.dart';
import '../core/api.dart';
import '../core/langue.dart';
import '../core/outils.dart';
import '../core/session.dart';
import '../widgets/communs.dart';
import 'etablissement_detail_screen.dart';
import 'etablissements_screen.dart';
import 'pharmacies_screen.dart';

/// F11 : carte des établissements à position EXACTE (les positions approximatives = centre de commune
/// ne sont jamais dessinées : un marqueur faux serait trompeur). Fond de carte OpenStreetMap
/// (serveur public à usage modéré : prévoir un fournisseur de tuiles dédié en production).
class CarteScreen extends StatefulWidget {
  const CarteScreen({super.key});

  @override
  State<CarteScreen> createState() => _CarteScreenState();
}

class _CarteScreenState extends State<CarteScreen> {
  static const _abidjan = LatLng(5.3600, -4.0083);

  final _controleur = MapController();
  Timer? _debounce;
  int _numero = 0;

  String? _categorie;
  List<Map<String, dynamic>> _points = [];
  int _sansPosition = 0;
  bool _tronque = false;
  bool _chargement = false;
  bool _charge = false; // au moins une réponse reçue
  String? _erreur;
  LatLng? _maPosition;
  bool _cherchePosition = false;

  LatLng get _centreInitial {
    final v = Session.instance.ville;
    final lat = (v?['lat'] as num?)?.toDouble(), lng = (v?['lng'] as num?)?.toDouble();
    return lat != null && lng != null ? LatLng(lat, lng) : _abidjan;
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _controleur.dispose();
    super.dispose();
  }

  void _auDeplacement(MapCamera camera, bool geste) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 500), () => _charger(camera.visibleBounds));
  }

  Future<void> _charger([LatLngBounds? zone]) async {
    final b = zone ?? _controleur.camera.visibleBounds;
    final n = ++_numero;
    setState(() {
      _chargement = true;
      _erreur = null;
    });
    try {
      final r = await Api.instance.get('/etablissements/carte', query: {
        'bbox': '${b.south.toStringAsFixed(5)},${b.west.toStringAsFixed(5)},${b.north.toStringAsFixed(5)},${b.east.toStringAsFixed(5)}',
        'categorie': _categorie,
        'limit': 300,
      }) as Map;
      if (!mounted || n != _numero) return;
      final meta = (r['meta'] as Map?) ?? const {};
      setState(() {
        _points = (r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _sansPosition = (meta['sans_position'] as num?)?.toInt() ?? 0;
        _tronque = meta['tronque'] == true;
        _charge = true;
      });
    } on ApiException catch (e) {
      if (mounted && n == _numero) setState(() => _erreur = e.message);
    } finally {
      if (mounted && n == _numero) setState(() => _chargement = false);
    }
  }

  Future<void> _centrerSurMoi() async {
    setState(() => _cherchePosition = true);
    final p = await Localisation.position(delai: const Duration(seconds: 8));
    if (!mounted) return;
    setState(() => _cherchePosition = false);
    if (p == null) {
      afficherMessage(context, tr('Position indisponible. Activez la localisation.', 'Location unavailable. Turn on location services.'));
      return;
    }
    final point = LatLng(p.latitude, p.longitude);
    setState(() => _maPosition = point);
    _controleur.move(point, 15);
  }

  void _voirListe() {
    final ecran = _categorie == 'pharmacie' ? const PharmaciesScreen() : const EtablissementsScreen.soins();
    Navigator.push(context, MaterialPageRoute(builder: (_) => ecran));
  }

  List<Map<String, dynamic>> get _categories {
    final l = Session.instance.categories.where((c) => c['code'] != 'a_classer').toList();
    return l;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: BarreTitre(tr('Carte', 'Map'), actions: const [SosAction()]),
      body: Stack(children: [
        FlutterMap(
          mapController: _controleur,
          options: MapOptions(
            initialCenter: _centreInitial,
            initialZoom: 12,
            minZoom: 5,
            maxZoom: 18,
            onMapReady: () => _charger(),
            onPositionChanged: _auDeplacement,
          ),
          children: [
            TileLayer(
              urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
              userAgentPackageName: 'ci.bravido.app',
            ),
            MarkerClusterLayerWidget(
              options: MarkerClusterLayerOptions(
                maxClusterRadius: 55,
                size: const Size(46, 46),
                alignment: Alignment.center,
                padding: const EdgeInsets.all(40),
                markers: [
                  for (final p in _points)
                    Marker(
                      point: LatLng((p['lat'] as num).toDouble(), (p['lng'] as num).toDouble()),
                      width: 40,
                      height: 40,
                      child: _Marqueur(p, onTap: () => ouvrirFiche(context, {
                            'id': p['id'],
                            'nom': p['nom'],
                            'h24': p['h24'],
                            'categorie': {'code': p['categorie'], 'libelle': ''},
                          })),
                    ),
                ],
                builder: (context, markers) => Container(
                  decoration: BoxDecoration(
                    color: Couleurs.vert,
                    shape: BoxShape.circle,
                    border: Border.all(color: Colors.white, width: 3),
                    boxShadow: const [BoxShadow(color: Color(0x55000000), blurRadius: 6, offset: Offset(0, 2))],
                  ),
                  alignment: Alignment.center,
                  child: Text('${markers.length}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 15)),
                ),
              ),
            ),
            if (_maPosition != null)
              MarkerLayer(markers: [
                Marker(
                  point: _maPosition!,
                  width: 26,
                  height: 26,
                  child: Container(
                    decoration: BoxDecoration(
                      color: const Color(0xFF1A73E8),
                      shape: BoxShape.circle,
                      border: Border.all(color: Colors.white, width: 3),
                      boxShadow: const [BoxShadow(color: Color(0x55000000), blurRadius: 6)],
                    ),
                  ),
                ),
              ]),
            const RichAttributionWidget(
              alignment: AttributionAlignment.bottomLeft,
              showFlutterMapAttribution: false,
              attributions: [TextSourceAttribution('© OpenStreetMap contributors')],
            ),
          ],
        ),
        // Filtres par catégorie
        Positioned(
          top: 8,
          left: 0,
          right: 0,
          child: SizedBox(
            height: 44,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12),
              children: [
                _PuceCategorie(label: tr('Toutes', 'All'), code: null, selectionnee: _categorie == null, onTap: () => _choisir(null)),
                for (final c in _categories)
                  _PuceCategorie(
                    label: '${c['libelle']}',
                    code: '${c['code']}',
                    selectionnee: _categorie == c['code'],
                    onTap: () => _choisir('${c['code']}'),
                  ),
              ],
            ),
          ),
        ),
        if (_chargement) const Positioned(top: 58, left: 0, right: 0, child: LinearProgressIndicator(minHeight: 3)),
        // Bandeau et états vides
        Positioned(
          left: 12,
          right: 12,
          bottom: 36,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.end, children: [
            FloatingActionButton.small(
              heroTag: 'ma_position',
              tooltip: tr('Ma position', 'My location'),
              backgroundColor: theme.colorScheme.surface,
              foregroundColor: Couleurs.accent,
              onPressed: _cherchePosition ? null : _centrerSurMoi,
              child: _cherchePosition
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.my_location_rounded),
            ),
            const SizedBox(height: 8),
            if (_erreur != null)
              _Carte(
                icone: Icons.cloud_off,
                texte: _erreur!,
                action: tr('Réessayer', 'Retry'),
                onAction: _charger,
              )
            else if (_charge && _points.isEmpty)
              _Carte(
                icone: Icons.location_searching_rounded,
                texte: _sansPosition > 0
                    ? tr(
                        'Aucun établissement à position exacte dans cette zone. $_sansPosition établissement${_sansPosition > 1 ? 's' : ''} sans position exacte : consultez la liste.',
                        'No facility with an exact position in this area. $_sansPosition facilit${_sansPosition > 1 ? 'ies' : 'y'} without exact position: see the list.')
                    : tr('Aucun établissement dans cette zone. Déplacez ou dézoomez la carte.', 'No facility in this area. Move or zoom out the map.'),
                action: _sansPosition > 0 ? tr('Voir la liste', 'See the list') : null,
                onAction: _voirListe,
              )
            else if (_charge && _sansPosition > 0)
              _Carte(
                icone: Icons.info_outline_rounded,
                texte: tr(
                  '$_sansPosition établissement${_sansPosition > 1 ? 's' : ''} sans position exacte ne ${_sansPosition > 1 ? 'sont' : 'est'} pas sur la carte.',
                  '$_sansPosition facilit${_sansPosition > 1 ? 'ies' : 'y'} without exact position ${_sansPosition > 1 ? 'are' : 'is'} not on the map.',
                ),
                action: tr('Voir la liste', 'See the list'),
                onAction: _voirListe,
              ),
            if (_tronque)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: _Carte(
                  icone: Icons.zoom_in_rounded,
                  texte: tr('Zone dense : zoomez pour voir tous les établissements.', 'Dense area: zoom in to see every facility.'),
                ),
              ),
          ]),
        ),
      ]),
    );
  }

  void _choisir(String? code) {
    setState(() {
      _categorie = code;
      _points = [];
    });
    _charger();
  }
}

class _Marqueur extends StatelessWidget {
  const _Marqueur(this.p, {required this.onTap});

  final Map<String, dynamic> p;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final (icone, couleur, _) = styleCategorie('${p['categorie']}');
    return Semantics(
      button: true,
      label: '${p['nom']}',
      child: GestureDetector(
        onTap: onTap,
        child: Container(
          decoration: BoxDecoration(
            color: couleur,
            shape: BoxShape.circle,
            border: Border.all(color: Colors.white, width: 2.5),
            boxShadow: const [BoxShadow(color: Color(0x55000000), blurRadius: 5, offset: Offset(0, 2))],
          ),
          child: Icon(icone, color: Colors.white, size: 20),
        ),
      ),
    );
  }
}

class _PuceCategorie extends StatelessWidget {
  const _PuceCategorie({required this.label, required this.code, required this.selectionnee, required this.onTap});

  final String label;
  final String? code;
  final bool selectionnee;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final couleur = code == null ? Couleurs.vert : styleCategorie(code).$2;
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: ChoiceChip(
        avatar: code == null ? null : Icon(styleCategorie(code).$1, size: 16, color: selectionnee ? Colors.white : couleur),
        label: Text(label),
        selected: selectionnee,
        selectedColor: couleur,
        backgroundColor: theme.colorScheme.surface,
        labelStyle: TextStyle(color: selectionnee ? Colors.white : theme.colorScheme.onSurface, fontWeight: FontWeight.w700, fontSize: 13),
        elevation: 2,
        onSelected: (_) => onTap(),
        visualDensity: VisualDensity.compact,
      ),
    );
  }
}

class _Carte extends StatelessWidget {
  const _Carte({required this.icone, required this.texte, this.action, this.onAction});

  final IconData icone;
  final String texte;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Material(
      color: theme.colorScheme.surface,
      elevation: 3,
      borderRadius: BorderRadius.circular(14),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 6, 8),
        child: Row(children: [
          Icon(icone, size: 20, color: Couleurs.accent),
          const SizedBox(width: 10),
          Expanded(child: Text(texte, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, height: 1.3))),
          if (action != null) TextButton(onPressed: onAction, child: Text(action!)),
        ]),
      ),
    );
  }
}
