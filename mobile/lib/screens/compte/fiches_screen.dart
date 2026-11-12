import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../core/outils.dart';
import '../../core/session.dart';
import '../../widgets/communs.dart';

/// USR-04 : fiches de souscription et de correction des responsables d'établissement.
class FichesScreen extends StatefulWidget {
  const FichesScreen({super.key});

  @override
  State<FichesScreen> createState() => _FichesScreenState();
}

class _FichesScreenState extends State<FichesScreen> {
  List<Map<String, dynamic>>? _fiches;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    try {
      final r = await Api.instance.get('/fiches') as Map;
      if (!mounted) return;
      setState(() {
        _fiches = (r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _erreur = null;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final fiches = _fiches;
    return Scaffold(
      appBar: const BarreTitre('Mes fiches établissement'),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          await Navigator.push(context, MaterialPageRoute(builder: (_) => const FicheFormScreen()));
          _charger();
        },
        icon: const Icon(Icons.add_business),
        label: const Text('Inscrire un établissement'),
      ),
      body: fiches == null
          ? (_erreur != null ? MessageVide(_erreur!, onReessayer: _charger) : const Chargement())
          : ListView(padding: const EdgeInsets.fromLTRB(12, 12, 12, 96), children: [
              const Text('Pour corriger un établissement déjà présent, ouvrez sa fiche puis « Je gère cet établissement ».'),
              const SizedBox(height: 8),
              if (fiches.isEmpty) const MessageVide('Aucune fiche envoyée.', icone: Icons.storefront_outlined),
              for (final f in fiches)
                Card(
                  child: ListTile(
                    title: Text('${f['nom'] ?? 'Établissement'}'),
                    subtitle: Text([
                      f['type'] == 'souscription' ? 'Inscription' : 'Correction',
                      'envoyée le ${formatDate('${f['created_at']}')}',
                      if (f['motif_rejet'] != null) 'Motif : ${f['motif_rejet']}',
                    ].join(' · ')),
                    trailing: Text(
                      {'en_attente': 'En attente', 'validee': 'Validée', 'rejetee': 'Rejetée'}['${f['statut']}'] ?? '',
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        color: f['statut'] == 'validee' ? Couleurs.vert : (f['statut'] == 'rejetee' ? Couleurs.rouge : Couleurs.orange),
                      ),
                    ),
                  ),
                ),
            ]),
    );
  }
}

class FicheFormScreen extends StatefulWidget {
  /// Sans [etablissement] : fiche de souscription. Avec : fiche de correction pré-remplie.
  const FicheFormScreen({super.key, this.etablissement});

  final Map<String, dynamic>? etablissement;

  @override
  State<FicheFormScreen> createState() => _FicheFormScreenState();
}

class _FicheFormScreenState extends State<FicheFormScreen> {
  final _form = GlobalKey<FormState>();
  late final Map<String, TextEditingController> _champs;
  String? _categorie;
  int? _communeId;
  bool _h24 = false;
  double? _lat;
  double? _lng;
  final Set<int> _assurances = {};
  final Set<String> _services = {};
  List<PlatformFile> _pieces = [];
  bool _envoi = false;

  bool get _correction => widget.etablissement != null;

  @override
  void initState() {
    super.initState();
    final e = widget.etablissement ?? const {};
    _champs = {
      for (final k in ['nom', 'adresse', 'repere', 'tel1', 'tel2', 'horaires', 'description'])
        k: TextEditingController(text: e[k] == null ? '' : '${e[k]}'),
    };
    _h24 = e['h24'] == true;
    _lat = (e['lat'] as num?)?.toDouble();
    _lng = (e['lng'] as num?)?.toDouble();
    _categorie = e['categorie'] is Map ? '${e['categorie']['code']}' : null;
    for (final a in (e['assurances'] as List?) ?? const []) {
      _assurances.add((a as Map)['id'] as int);
    }
    _services.addAll(((e['services'] as List?) ?? const []).map((s) => '$s'));
  }

  @override
  void dispose() {
    for (final c in _champs.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _maPosition() async {
    final p = await Localisation.position();
    if (p == null) {
      if (mounted) afficherMessage(context, 'Position indisponible. Activez la localisation.');
      return;
    }
    setState(() {
      _lat = p.latitude;
      _lng = p.longitude;
    });
  }

  Future<void> _choisirPieces() async {
    final r = await FilePicker.platform.pickFiles(
      allowMultiple: true,
      type: FileType.custom,
      allowedExtensions: const ['pdf', 'jpg', 'jpeg', 'png'],
    );
    if (r == null) return;
    setState(() => _pieces = r.files.where((f) => f.path != null).take(3).toList());
  }

  Future<void> _envoyer() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _envoi = true);

    final data = FormData();
    void champ(String cle, Object? valeur) {
      final v = valeur?.toString().trim() ?? '';
      if (v.isNotEmpty) data.fields.add(MapEntry('donnees[$cle]', v));
    }

    data.fields.add(MapEntry('type', _correction ? 'correction' : 'souscription'));
    if (_correction) data.fields.add(MapEntry('etablissement_id', '${widget.etablissement!['id']}'));
    _champs.forEach((cle, c) => champ(cle, c.text));
    champ('categorie_code', _categorie);
    champ('commune_id', _communeId);
    champ('lat', _lat);
    champ('lng', _lng);
    data.fields.add(MapEntry('donnees[h24]', _h24 ? '1' : '0'));
    var i = 0;
    for (final a in _assurances) {
      data.fields.add(MapEntry('donnees[assurances][${i++}]', '$a'));
    }
    i = 0;
    for (final s in _services) {
      data.fields.add(MapEntry('donnees[services][${i++}]', s));
    }
    for (var j = 0; j < _pieces.length; j++) {
      data.files.add(MapEntry('pieces[$j]', await MultipartFile.fromFile(_pieces[j].path!, filename: _pieces[j].name)));
    }

    try {
      await Api.instance.post('/fiches', data);
      if (!mounted) return;
      afficherMessage(context, 'Fiche envoyée. Notre équipe va la vérifier.');
      Navigator.pop(context);
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    } finally {
      if (mounted) setState(() => _envoi = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = Session.instance;
    final categories = session.categories.where((c) => c['module'] != 'urgence').toList();
    final communes = [
      for (final v in session.villes)
        for (final c in (v['communes'] as List? ?? const [])) {'id': (c as Map)['id'], 'nom': '${c['nom']} (${v['nom']})'},
    ];

    return Scaffold(
      appBar: const BarreTitre('Fiche établissement'),
      body: Form(
        key: _form,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          // Onglets de la maquette ; la correction part de la fiche d'un établissement existant
          Puces<bool>(
            options: const {false: 'Souscription', true: 'Correction'},
            valeur: _correction,
            onChanged: (correction) {
              if (correction && !_correction) {
                afficherMessage(context, 'Ouvrez la fiche de l\'établissement à corriger, puis « Je gère cet établissement ».');
              }
            },
          ),
          const SizedBox(height: 10),
          if (_correction)
            Info('Correction de « ${widget.etablissement!['nom']} ». Modifiez uniquement ce qui est faux : la correction sera publiée après vérification.'),
          TextFormField(
            controller: _champs['nom'],
            decoration: const InputDecoration(labelText: 'Dénomination *'),
            validator: (v) => !_correction && (v == null || v.trim().isEmpty) ? 'Obligatoire' : null,
          ),
          const SizedBox(height: 12),
          if (!_correction) ...[
            DropdownButtonFormField<String>(
              initialValue: _categorie,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Catégorie *'),
              items: [for (final c in categories) DropdownMenuItem(value: '${c['code']}', child: Text('${c['libelle']}'))],
              onChanged: (v) => setState(() => _categorie = v),
              validator: (v) => v == null ? 'Obligatoire' : null,
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<int>(
              initialValue: _communeId,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Commune *'),
              items: [for (final c in communes) DropdownMenuItem(value: c['id'] as int, child: Text('${c['nom']}'))],
              onChanged: (v) => setState(() => _communeId = v),
              validator: (v) => v == null ? 'Obligatoire' : null,
            ),
            const SizedBox(height: 12),
          ],
          TextFormField(controller: _champs['adresse'], decoration: const InputDecoration(labelText: 'Adresse')),
          const SizedBox(height: 12),
          TextFormField(controller: _champs['repere'], decoration: const InputDecoration(labelText: 'Repère (ex. face à la mairie)')),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: TextFormField(controller: _champs['tel1'], keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Téléphone 1'))),
            const SizedBox(width: 8),
            Expanded(child: TextFormField(controller: _champs['tel2'], keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Téléphone 2'))),
          ]),
          const SizedBox(height: 12),
          TextFormField(controller: _champs['horaires'], decoration: const InputDecoration(labelText: 'Horaires (ex. Lun-Sam 8h-20h)')),
          SwitchListTile(value: _h24, onChanged: (v) => setState(() => _h24 = v), title: const Text('Ouvert 24 h/24'), contentPadding: EdgeInsets.zero),
          TextFormField(controller: _champs['description'], maxLines: 3, decoration: const InputDecoration(labelText: 'Description')),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: _maPosition,
            icon: const Icon(Icons.my_location),
            label: Text(_lat == null ? 'Je suis sur place : utiliser ma position' : 'Position : ${_lat!.toStringAsFixed(5)}, ${_lng!.toStringAsFixed(5)}'),
          ),
          const SizedBox(height: 16),
          Text('Assurances acceptées', style: Theme.of(context).textTheme.titleSmall),
          Wrap(spacing: 6, children: [
            for (final a in session.assurances)
              FilterChip(
                label: Text('${a['nom']}'),
                selected: _assurances.contains(a['id']),
                onSelected: (s) => setState(() => s ? _assurances.add(a['id'] as int) : _assurances.remove(a['id'])),
              ),
          ]),
          const SizedBox(height: 12),
          Text('Services', style: Theme.of(context).textTheme.titleSmall),
          Wrap(spacing: 6, children: [
            for (final s in session.services)
              FilterChip(
                label: Text(s),
                selected: _services.contains(s),
                onSelected: (sel) => setState(() => sel ? _services.add(s) : _services.remove(s)),
              ),
          ]),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: _choisirPieces,
            icon: const Icon(Icons.attach_file),
            label: Text(_pieces.isEmpty ? 'Joindre des justificatifs (3 max, PDF/JPG/PNG)' : _pieces.map((p) => p.name).join(', ')),
          ),
          const SizedBox(height: 24),
          FilledButton(
            onPressed: _envoi ? null : _envoyer,
            child: Padding(padding: const EdgeInsets.all(12), child: Text(_envoi ? 'Envoi…' : 'Envoyer pour validation')),
          ),
        ]),
      ),
    );
  }
}
