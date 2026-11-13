import 'package:flutter/material.dart';
import '../core/langue.dart';
import 'package:intl/intl.dart';

import '../app.dart';
import '../core/api.dart';
import '../core/cache.dart';
import '../core/outils.dart';
import '../core/session.dart';
import '../widgets/communs.dart';
import '../widgets/liste_etablissements.dart';

/// Maquettes « Pharmacies de garde », « Toutes les pharmacies » et « Fiche pharmacie » (PHA-01 à PHA-05).
class PharmaciesScreen extends StatefulWidget {
  const PharmaciesScreen({super.key});

  @override
  State<PharmaciesScreen> createState() => _PharmaciesScreenState();
}

class _PharmaciesScreenState extends State<PharmaciesScreen> {
  bool _garde = true;
  String _recherche = '';
  int? _communeId;
  int? _assuranceId;

  // Gardes
  List<Map<String, dynamic>> _gardes = [];
  Map? _periode;
  bool _chargementGardes = true;
  String? _erreurGardes;
  DateTime? _horsLigneDepuis;

  String get _cleCache => 'gardes_${Session.instance.villeId}';

  @override
  void initState() {
    super.initState();
    _chargerGardes();
  }

  Future<void> _chargerGardes() async {
    setState(() {
      _chargementGardes = true;
      _erreurGardes = null;
    });
    final position = await Localisation.position(delai: const Duration(seconds: 6));
    try {
      final r = await Api.instance.get('/pharmacies/garde', query: {
        'ville_id': Session.instance.villeId,
        'lat': position?.latitude,
        'lng': position?.longitude,
      }) as Map;
      final items = (r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      await Cache.ecrire(_cleCache, {'data': items, 'periode': r['periode']});
      if (!mounted) return;
      setState(() {
        _gardes = items;
        _periode = r['periode'] as Map?;
        _horsLigneDepuis = null;
      });
    } on ApiException catch (e) {
      final cache = Cache.lireMap(_cleCache);
      if (!mounted) return;
      if (e.horsLigne && cache != null) {
        final aujourdhui = DateFormat('yyyy-MM-dd').format(DateTime.now());
        setState(() {
          // Les gardes terminées depuis la mise en cache sont écartées
          _gardes = (cache['data'] as List)
              .map((x) => Map<String, dynamic>.from(x as Map))
              .where((x) => '${x['garde']?['au']}'.compareTo(aujourdhui) >= 0)
              .toList();
          _periode = cache['periode'] as Map?;
          _horsLigneDepuis = Cache.dateDe(_cleCache);
        });
      } else {
        setState(() => _erreurGardes = e.message);
      }
    } finally {
      if (mounted) setState(() => _chargementGardes = false);
    }
  }

  /// Filtres appliqués localement sur la liste des gardes (déjà complète pour la ville).
  List<Map<String, dynamic>> get _gardesFiltrees {
    final session = Session.instance;
    final commune = _communeId == null
        ? null
        : (session.ville?['communes'] as List?)?.cast<Map>().where((c) => c['id'] == _communeId).map((c) => c['nom']).firstOrNull;
    final q = _recherche.toLowerCase();
    return _gardes.where((e) {
      if (commune != null && e['commune'] != commune) return false;
      if (q.isNotEmpty && !'${e['nom']}'.toLowerCase().contains(q)) return false;
      if (_assuranceId != null && !((e['assurances'] as List?) ?? const []).any((a) => (a as Map)['id'] == _assuranceId)) {
        return false;
      }
      return true;
    }).toList();
  }

  String _jm(dynamic date) {
    final d = DateTime.tryParse('$date');
    return d == null ? '' : DateFormat('dd/MM').format(d);
  }

  @override
  Widget build(BuildContext context) {
    final session = Session.instance;
    final communes = ((session.ville?['communes'] as List?) ?? const []).cast<Map>();

    final filtres = Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 4),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Puces<bool>(
          options: {true: tr('De garde', 'On duty'), false: tr('Toutes les pharmacies', 'All pharmacies')},
          valeur: _garde,
          onChanged: (v) => setState(() => _garde = v),
        ),
        const SizedBox(height: 8),
        if (_garde)
          Info(
            '',
            enrichi: TextSpan(children: [
              if (_periode != null) ...[
                TextSpan(text: tr('Programme de garde du ', 'On-duty schedule from ')),
                TextSpan(text: _jm(_periode!['du']), style: const TextStyle(fontWeight: FontWeight.w800)),
                TextSpan(text: tr(' au ', ' to ')),
                TextSpan(text: _jm(_periode!['au']), style: const TextStyle(fontWeight: FontWeight.w800)),
                const TextSpan(text: '. '),
              ],
              TextSpan(text: tr('La plus proche en tête de liste.', 'The closest comes first.')),
            ]),
          ),
        if (_garde && _horsLigneDepuis != null)
          Bandeau(tr('Hors ligne · liste enregistrée le ${DateFormat('dd/MM à HH:mm').format(_horsLigneDepuis!)}', 'Offline · list saved on ${DateFormat('dd/MM HH:mm').format(_horsLigneDepuis!)}')),
        TextField(
          textInputAction: TextInputAction.search,
          decoration: InputDecoration(hintText: tr('Rechercher une pharmacie', 'Search a pharmacy')),
          onChanged: _garde ? (v) => setState(() => _recherche = v.trim()) : null,
          onSubmitted: (v) => setState(() => _recherche = v.trim()),
        ),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(
            child: DropdownButtonFormField<int?>(
              initialValue: _communeId,
              isExpanded: true,
              items: [
                DropdownMenuItem(value: null, child: Text(tr('Communes', 'Districts'), maxLines: 1, overflow: TextOverflow.ellipsis)),
                for (final c in communes) DropdownMenuItem(value: c['id'] as int, child: Text('${c['nom']}', maxLines: 1, overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) => setState(() => _communeId = v),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: DropdownButtonFormField<int?>(
              initialValue: _assuranceId,
              isExpanded: true,
              items: [
                DropdownMenuItem(value: null, child: Text(tr('Assurances', 'Insurers'), maxLines: 1, overflow: TextOverflow.ellipsis)),
                for (final a in session.assurances)
                  DropdownMenuItem(value: a['id'] as int, child: Text('${a['nom']}', maxLines: 1, overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) => setState(() => _assuranceId = v),
            ),
          ),
        ]),
      ]),
    );

    return Scaffold(
      appBar: BarreTitre(tr('Pharmacies', 'Pharmacies'), actions: const [SosAction()]),
      body: Column(children: [
        filtres,
        Expanded(
          child: _garde
              ? _listeGardes()
              : ListeEtablissements(
                  chemin: '/pharmacies',
                  filtres: {
                    'ville_id': session.villeId,
                    'commune_id': _communeId,
                    'q': _recherche,
                    'assurance_id': _assuranceId,
                  },
                ),
        ),
      ]),
    );
  }

  Widget _listeGardes() {
    if (_chargementGardes && _gardes.isEmpty) return const Chargement();
    if (_erreurGardes != null) return MessageVide(_erreurGardes!, icone: Icons.cloud_off, onReessayer: _chargerGardes);
    final items = _gardesFiltrees;

    return RefreshIndicator(
      onRefresh: _chargerGardes,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
        children: [
          if (items.isEmpty)
            MessageVide(tr('Aucune pharmacie de garde pour ces critères.', 'No on-duty pharmacy for these criteria.'), icone: Icons.local_pharmacy_outlined)
          else
            for (final e in items) EtablissementTile(e),
          if (items.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(tr('${items.length} pharmacie${items.length > 1 ? 's' : ''} de garde', '${items.length} on-duty pharmac${items.length > 1 ? 'ies' : 'y'}'),
                  textAlign: TextAlign.center, style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 12)),
            ),
        ],
      ),
    );
  }
}
