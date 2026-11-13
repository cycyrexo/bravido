import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../core/outils.dart';
import '../../core/session.dart';
import '../../widgets/communs.dart';

/// F18 : entrée « Mon établissement » de l'espace utilisateur (visible si l'utilisateur gère au moins un établissement).
class MonEtablissementEntree extends StatefulWidget {
  const MonEtablissementEntree({super.key});

  @override
  State<MonEtablissementEntree> createState() => _MonEtablissementEntreeState();
}

class _MonEtablissementEntreeState extends State<MonEtablissementEntree> {
  int _nb = 0;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    try {
      final r = await Api.instance.get('/mon-etablissement') as Map;
      if (mounted) setState(() => _nb = (r['data'] as List).length);
    } catch (_) {
      // hors ligne : l'entrée reste masquée
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_nb == 0) return const SizedBox.shrink();
    return LigneMenu(
      badge: Icon(Icons.storefront_rounded, size: 20, color: Couleurs.encreOrange),
      couleurBadge: Couleurs.orangeClair,
      titre: 'Mon établissement',
      sousTitre: _nb == 1 ? 'Gérer ma fiche, ma garde, mes statistiques' : '$_nb établissements à gérer',
      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const MonEtablissementListeScreen())),
    );
  }
}

class MonEtablissementListeScreen extends StatefulWidget {
  const MonEtablissementListeScreen({super.key});

  @override
  State<MonEtablissementListeScreen> createState() => _MonEtablissementListeScreenState();
}

class _MonEtablissementListeScreenState extends State<MonEtablissementListeScreen> {
  List<Map<String, dynamic>>? _liste;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    try {
      final r = await Api.instance.get('/mon-etablissement') as Map;
      if (!mounted) return;
      setState(() {
        _liste = (r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _erreur = null;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final liste = _liste;
    return Scaffold(
      appBar: const BarreTitre('Mon établissement'),
      body: liste == null
          ? (_erreur != null ? MessageVide(_erreur!, onReessayer: _charger) : const Chargement())
          : liste.isEmpty
              ? const MessageVide('Vous ne gérez aucun établissement pour le moment.', icone: Icons.storefront_outlined)
              : ListView(padding: const EdgeInsets.all(14), children: [
                  for (final e in liste)
                    Card(
                      clipBehavior: Clip.antiAlias,
                      child: InkWell(
                        onTap: () async {
                          await Navigator.push(context, MaterialPageRoute(builder: (_) => MonEtablissementDetailScreen(id: e['id'] as int)));
                          _charger();
                        },
                        child: Padding(
                          padding: const EdgeInsets.all(14),
                          child: Row(children: [
                            IconeCategorie('${(e['categorie'] as Map)['code']}', taille: 46),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                Text('${e['nom']}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
                                const SizedBox(height: 2),
                                Text('${e['commune']}, ${e['ville']}', style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 13)),
                                const SizedBox(height: 6),
                                Wrap(spacing: 6, runSpacing: 4, children: [
                                  if (e['premium'] == true) const Pastille.orange('Premium'),
                                  Pastille.grise('${(e['stats'] as Map)['vues']} vues · 30 j'),
                                ]),
                              ]),
                            ),
                            Icon(Icons.chevron_right, color: Couleurs.texteSecondaire),
                          ]),
                        ),
                      ),
                    ),
                ]),
    );
  }
}

const _joursSemaine = {
  'lun': 'Lundi',
  'mar': 'Mardi',
  'mer': 'Mercredi',
  'jeu': 'Jeudi',
  'ven': 'Vendredi',
  'sam': 'Samedi',
  'dim': 'Dimanche',
};

/// Tableau de bord d'un établissement : fiche, modification modérée, garde, statistiques, options Premium.
class MonEtablissementDetailScreen extends StatefulWidget {
  const MonEtablissementDetailScreen({super.key, required this.id});

  final int id;

  @override
  State<MonEtablissementDetailScreen> createState() => _MonEtablissementDetailScreenState();
}

class _MonEtablissementDetailScreenState extends State<MonEtablissementDetailScreen> {
  Map<String, dynamic>? _d;
  String? _erreur;
  bool _occupe = false;

  String get _base => '/mon-etablissement/${widget.id}';

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    try {
      final r = await Api.instance.get(_base) as Map;
      if (!mounted) return;
      setState(() {
        _d = Map<String, dynamic>.from(r['data'] as Map);
        _erreur = null;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    }
  }

  Future<void> _declarerGarde() async {
    final aujourdhui = DateTime.now();
    final plage = await showDateRangePicker(
      context: context,
      firstDate: DateTime(aujourdhui.year, aujourdhui.month, aujourdhui.day),
      lastDate: aujourdhui.add(const Duration(days: 60)),
      helpText: 'Période de votre garde (14 jours maximum)',
      saveText: 'Valider',
    );
    if (plage == null || !mounted) return;
    String jour(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
    setState(() => _occupe = true);
    try {
      final r = await Api.instance.post('$_base/garde', {'date_debut': jour(plage.start), 'date_fin': jour(plage.end)}) as Map;
      if (mounted) afficherMessage(context, '${r['message']}');
      await _charger();
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    } finally {
      if (mounted) setState(() => _occupe = false);
    }
  }

  Future<void> _ajouterPhoto() async {
    final r = await FilePicker.platform.pickFiles(type: FileType.image);
    final f = r?.files.single;
    if (f == null || f.path == null) return;
    setState(() => _occupe = true);
    try {
      final data = FormData()..files.add(MapEntry('photo', await MultipartFile.fromFile(f.path!, filename: f.name)));
      await Api.instance.post('$_base/photos', data);
      await _charger();
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    } finally {
      if (mounted) setState(() => _occupe = false);
    }
  }

  Future<void> _supprimerPhoto(int idPhoto) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Supprimer cette photo ?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Annuler')),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Supprimer')),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await Api.instance.delete('$_base/photos/$idPhoto');
      await _charger();
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    }
  }

  Future<void> _modifierHoraires() async {
    final actuels = Map<String, dynamic>.from((_d!['horaires_detail_edit'] as Map?) ?? const {});
    final champs = {for (final j in _joursSemaine.keys) j: TextEditingController(text: '${actuels[j] ?? ''}')};
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (c) => Padding(
        padding: EdgeInsets.fromLTRB(16, 0, 16, MediaQuery.of(c).viewInsets.bottom + 16),
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            const Text('Horaires détaillés', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            const SizedBox(height: 4),
            Text('Ex. : 08:00-20:00 ou Fermé. Laissez vide si non précisé.', style: TextStyle(color: Couleurs.texteSecondaire)),
            const SizedBox(height: 8),
            for (final j in _joursSemaine.entries)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: TextField(controller: champs[j.key], maxLength: 60, decoration: InputDecoration(labelText: j.value, counterText: '')),
              ),
            const SizedBox(height: 8),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Enregistrer')),
          ]),
        ),
      ),
    );
    if (ok == true) {
      try {
        await Api.instance.put('$_base/horaires-detail', {'jours': {for (final e in champs.entries) e.key: e.value.text.trim()}});
        await _charger();
      } on ApiException catch (e) {
        if (mounted) afficherMessage(context, e.message);
      }
    }
    for (final c in champs.values) {
      c.dispose();
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    return Scaffold(
      appBar: const BarreTitre('Mon établissement'),
      body: d == null
          ? (_erreur != null ? MessageVide(_erreur!, onReessayer: _charger) : const Chargement())
          : RefreshIndicator(onRefresh: _charger, child: _corps(d)),
    );
  }

  Widget _corps(Map<String, dynamic> d) {
    final premium = d['premium'] == true;
    final stats = Map<String, dynamic>.from(d['stats'] as Map);
    final services = ((d['services'] as List?) ?? const []).map((s) => '$s').toList();
    final assurances = ((d['assurances'] as List?) ?? const []).map((a) => '${(a as Map)['nom']}').toList();
    final declarations = ((d['declarations_garde'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final photos = ((d['photos'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final photosMax = d['photos_max'] as int? ?? 5;
    final horaires = (d['horaires_detail'] as Map?) ?? const {};
    final cat = d['categorie'] is Map ? '${d['categorie']['code']}' : null;

    return ListView(padding: const EdgeInsets.fromLTRB(14, 6, 14, 32), children: [
      Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        IconeCategorie(cat, taille: 52),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('${d['nom']}', style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800, height: 1.2)),
            const SizedBox(height: 3),
            Text('${d['commune']}, ${d['ville']}', style: TextStyle(color: Couleurs.texteSecondaire)),
            const SizedBox(height: 6),
            Wrap(spacing: 6, runSpacing: 4, children: [
              Pastille.verte(d['statut'] == 'publie' ? 'Publiée' : 'Non publiée'),
              if (premium) Pastille.orange(d['premium_jusqu_au'] == null ? 'Premium' : 'Premium jusqu\'au ${formatDate('${d['premium_jusqu_au']}')}'),
            ]),
          ]),
        ),
      ]),
      const TitreSection('Statistiques · 30 derniers jours'),
      Row(children: [
        _CarteStat(Icons.visibility_outlined, 'Vues', '${stats['vues']}', Couleurs.bleu, Couleurs.bleuClair),
        const SizedBox(width: 10),
        _CarteStat(Icons.call_outlined, 'Appels', '${stats['appels']}', Couleurs.vert, Couleurs.vertClair),
        const SizedBox(width: 10),
        _CarteStat(Icons.directions_outlined, 'Itinéraires', '${stats['itineraires']}', Couleurs.orange, Couleurs.orangeClair),
      ]),
      const TitreSection('Ma fiche'),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            _Ligne('Téléphone', [d['tel1'], d['tel2']].whereType<String>().where((t) => t.isNotEmpty).join(' · ')),
            _Ligne('Horaires', d['h24'] == true ? '24h/24' : '${d['horaires'] ?? ''}'),
            _Ligne('Services', services.join(', ')),
            _Ligne('Assurances', assurances.join(', ')),
            const SizedBox(height: 10),
            if (d['correction_en_attente'] == true)
              const Bandeau('Votre dernière modification est en attente de validation.', icone: Icons.hourglass_top_rounded)
            else
              OutlinedButton.icon(
                onPressed: () async {
                  final envoye = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => ModifierFicheScreen(donnees: d)));
                  if (envoye == true) _charger();
                },
                icon: const Icon(Icons.edit_outlined, size: 18),
                label: const Text('Modifier ma fiche'),
              ),
            const SizedBox(height: 4),
            Text('Les modifications sont vérifiées par notre équipe avant d\'être visibles.',
                style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 12.5)),
          ]),
        ),
      ),
      if (d['peut_declarer_garde'] == true) ...[
        const TitreSection('Garde'),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('Une information de garde erronée peut mettre une personne en danger : chaque déclaration est vérifiée par notre équipe avant publication.',
                  style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 13, height: 1.35)),
              const SizedBox(height: 10),
              FilledButton.icon(
                onPressed: _occupe ? null : _declarerGarde,
                icon: const Icon(Icons.nightlight_round, size: 18),
                label: const Text('Déclarer ma garde'),
              ),
              if (declarations.isNotEmpty) const SizedBox(height: 12),
              for (final g in declarations)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(children: [
                    Expanded(child: Text('Du ${formatDate('${g['du']}')} au ${formatDate('${g['au']}')}', style: const TextStyle(fontWeight: FontWeight.w600))),
                    switch ('${g['statut']}') {
                      'validee' => const Pastille.verte('Validée'),
                      'rejetee' => const Pastille.rouge('Rejetée'),
                      _ => const Pastille.orange('En attente'),
                    },
                  ]),
                ),
              for (final g in declarations.where((g) => g['motif_rejet'] != null).take(1))
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text('Motif : ${g['motif_rejet']}', style: TextStyle(color: Couleurs.encreRouge, fontSize: 12.5)),
                ),
            ]),
          ),
        ),
      ],
      const TitreSection('Fiche Premium'),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: !premium
              ? Text(
                  'Photos, horaires détaillés par jour et description complète sont réservés aux fiches Premium. Contactez le service BRAVIDO pour en bénéficier.',
                  style: TextStyle(color: Couleurs.texteSecondaire, height: 1.35))
              : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('Photos (${photos.length}/$photosMax)', style: const TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 8),
                  Wrap(spacing: 8, runSpacing: 8, children: [
                    for (final p in photos)
                      Stack(children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(10),
                          child: Image.network('${p['miniature']}', width: 92, height: 92, fit: BoxFit.cover,
                              errorBuilder: (_, __, ___) => Container(width: 92, height: 92, color: Couleurs.gris, child: const Icon(Icons.broken_image_outlined))),
                        ),
                        Positioned(
                          right: 2,
                          top: 2,
                          child: InkWell(
                            onTap: () => _supprimerPhoto(p['id'] as int),
                            child: const CircleAvatar(radius: 12, backgroundColor: Colors.black54, child: Icon(Icons.close, size: 14, color: Colors.white)),
                          ),
                        ),
                      ]),
                    if (photos.length < photosMax)
                      InkWell(
                        onTap: _occupe ? null : _ajouterPhoto,
                        borderRadius: BorderRadius.circular(10),
                        child: Container(
                          width: 92,
                          height: 92,
                          decoration: BoxDecoration(border: Border.all(color: Couleurs.bord), borderRadius: BorderRadius.circular(10)),
                          child: Icon(Icons.add_a_photo_outlined, color: Couleurs.accent),
                        ),
                      ),
                  ]),
                  const SizedBox(height: 14),
                  const Text('Horaires détaillés', style: TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 4),
                  if (horaires.isEmpty) Text('Non renseignés.', style: TextStyle(color: Couleurs.texteSecondaire)),
                  for (final j in _joursSemaine.entries)
                    if (horaires[j.key] != null) _Ligne(j.value, '${horaires[j.key]}'),
                  const SizedBox(height: 6),
                  OutlinedButton.icon(onPressed: _modifierHoraires, icon: const Icon(Icons.schedule_rounded, size: 18), label: const Text('Modifier les horaires')),
                ]),
        ),
      ),
    ]);
  }
}

class _CarteStat extends StatelessWidget {
  const _CarteStat(this.icone, this.libelle, this.valeur, this.couleur, this.fond);

  final IconData icone;
  final String libelle;
  final String valeur;
  final Color couleur;
  final Color fond;

  @override
  Widget build(BuildContext context) => Expanded(
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 8),
          decoration: BoxDecoration(color: fond, borderRadius: BorderRadius.circular(16)),
          child: Column(children: [
            Icon(icone, color: couleur, size: 22),
            const SizedBox(height: 6),
            Text(valeur, style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: couleur)),
            Text(libelle, style: TextStyle(fontSize: 12.5, color: Couleurs.texteSecondaire, fontWeight: FontWeight.w600)),
          ]),
        ),
      );
}

class _Ligne extends StatelessWidget {
  const _Ligne(this.libelle, this.valeur);

  final String libelle;
  final String valeur;

  @override
  Widget build(BuildContext context) {
    if (valeur.trim().isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SizedBox(width: 92, child: Text(libelle, style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 13.5))),
        Expanded(child: Text(valeur, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14, height: 1.3))),
      ]),
    );
  }
}

/// Modification rapide (téléphone, horaires, services, assurances) : envoyée comme fiche de correction modérée.
class ModifierFicheScreen extends StatefulWidget {
  const ModifierFicheScreen({super.key, required this.donnees});

  final Map<String, dynamic> donnees;

  @override
  State<ModifierFicheScreen> createState() => _ModifierFicheScreenState();
}

class _ModifierFicheScreenState extends State<ModifierFicheScreen> {
  late final _tel1 = TextEditingController(text: '${widget.donnees['tel1'] ?? ''}');
  late final _tel2 = TextEditingController(text: '${widget.donnees['tel2'] ?? ''}');
  late final _horaires = TextEditingController(text: '${widget.donnees['horaires'] ?? ''}');
  late final _description = TextEditingController(text: '${widget.donnees['description_longue'] ?? ''}');
  late bool _h24 = widget.donnees['h24'] == true;
  late final Set<String> _services = ((widget.donnees['services'] as List?) ?? const []).map((s) => '$s').toSet();
  late final Set<int> _assurances = ((widget.donnees['assurances'] as List?) ?? const []).map((a) => (a as Map)['id'] as int).toSet();
  bool _envoi = false;

  @override
  void dispose() {
    _tel1.dispose();
    _tel2.dispose();
    _horaires.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _envoyer() async {
    setState(() => _envoi = true);
    try {
      final corps = <String, dynamic>{
        'tel1': _tel1.text.trim(),
        'tel2': _tel2.text.trim(),
        'horaires': _horaires.text.trim(),
        'h24': _h24,
        'services': _services.toList(),
        'assurances': _assurances.toList(),
        if (widget.donnees['premium'] == true) 'description_longue': _description.text.trim(),
      };
      // Les chaînes vides ne sont pas des modifications
      corps.removeWhere((_, v) => v is String && v.isEmpty);
      final r = await Api.instance.post('/mon-etablissement/${widget.donnees['id']}/corrections', corps) as Map;
      if (!mounted) return;
      afficherMessage(context, '${r['message']}');
      Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    } finally {
      if (mounted) setState(() => _envoi = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = Session.instance;
    return Scaffold(
      appBar: const BarreTitre('Modifier ma fiche'),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        const Bandeau('Vos modifications seront vérifiées avant publication.', icone: Icons.verified_user_outlined, couleur: Couleurs.vert),
        TextField(controller: _tel1, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Téléphone')),
        const SizedBox(height: 12),
        TextField(controller: _tel2, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Second téléphone')),
        const SizedBox(height: 12),
        TextField(controller: _horaires, decoration: const InputDecoration(labelText: 'Horaires', hintText: 'Lun-Sam 8h-20h')),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('Ouvert 24h/24'), value: _h24, onChanged: (v) => setState(() => _h24 = v)),
        if (widget.donnees['premium'] == true) ...[
          TextField(controller: _description, maxLines: 4, maxLength: 4000, decoration: const InputDecoration(labelText: 'Description détaillée (Premium)')),
        ],
        const TitreSection('Services'),
        Wrap(spacing: 8, runSpacing: 4, children: [
          for (final s in session.services)
            FilterChip(label: Text(s), selected: _services.contains(s), onSelected: (v) => setState(() => v ? _services.add(s) : _services.remove(s))),
        ]),
        const TitreSection('Assurances acceptées'),
        Wrap(spacing: 8, runSpacing: 4, children: [
          for (final a in session.assurances)
            FilterChip(
              label: Text('${a['nom']}'),
              selected: _assurances.contains(a['id']),
              onSelected: (v) => setState(() => v ? _assurances.add(a['id'] as int) : _assurances.remove(a['id'])),
            ),
        ]),
        const SizedBox(height: 20),
        FilledButton(onPressed: _envoi ? null : _envoyer, child: Text(_envoi ? 'Envoi…' : 'Envoyer pour validation')),
      ]),
    );
  }
}

/// F18 : « Je gère cet établissement » : demande de revendication, validée par l'équipe BRAVIDO.
class RevendicationScreen extends StatefulWidget {
  const RevendicationScreen({super.key, required this.etablissement});

  final Map<String, dynamic> etablissement;

  @override
  State<RevendicationScreen> createState() => _RevendicationScreenState();
}

class _RevendicationScreenState extends State<RevendicationScreen> {
  final _role = TextEditingController();
  late final _tel = TextEditingController(text: '${Session.instance.user?['telephone'] ?? ''}');
  PlatformFile? _justificatif;
  bool _envoi = false;

  @override
  void dispose() {
    _role.dispose();
    _tel.dispose();
    super.dispose();
  }

  Future<void> _choisir() async {
    final r = await FilePicker.platform.pickFiles(type: FileType.custom, allowedExtensions: const ['pdf', 'jpg', 'jpeg', 'png']);
    if (r != null && r.files.single.path != null) setState(() => _justificatif = r.files.single);
  }

  Future<void> _envoyer() async {
    if (_role.text.trim().isEmpty || _tel.text.trim().isEmpty) {
      afficherMessage(context, 'Indiquez votre fonction et un numéro où vous joindre.');
      return;
    }
    setState(() => _envoi = true);
    try {
      final data = FormData()
        ..fields.addAll([
          const MapEntry('type', 'revendication'),
          MapEntry('etablissement_id', '${widget.etablissement['id']}'),
          MapEntry('donnees[role]', _role.text.trim()),
          MapEntry('donnees[telephone]', _tel.text.trim()),
        ]);
      final j = _justificatif;
      if (j != null) data.files.add(MapEntry('pieces[0]', await MultipartFile.fromFile(j.path!, filename: j.name)));
      await Api.instance.post('/fiches', data);
      if (!mounted) return;
      afficherMessage(context, 'Demande envoyée. Notre équipe va la vérifier et vous prévenir.');
      Navigator.pop(context);
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    } finally {
      if (mounted) setState(() => _envoi = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: const BarreTitre('Je gère cet établissement'),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Text('${widget.etablissement['nom']}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 4),
        Text([widget.etablissement['commune'], widget.etablissement['ville']].whereType<String>().join(', '),
            style: TextStyle(color: Couleurs.texteSecondaire)),
        const SizedBox(height: 14),
        const Bandeau('Notre équipe vérifie chaque demande avant de vous donner accès à la gestion de la fiche.',
            icone: Icons.verified_user_outlined, couleur: Couleurs.vert),
        TextField(controller: _role, decoration: const InputDecoration(labelText: 'Votre fonction', hintText: 'Pharmacien titulaire, directeur, gérant…')),
        const SizedBox(height: 12),
        TextField(controller: _tel, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Numéro où vous joindre')),
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: _choisir,
          icon: const Icon(Icons.attach_file_rounded, size: 18),
          label: Text(_justificatif == null ? 'Joindre un justificatif (facultatif)' : _justificatif!.name, overflow: TextOverflow.ellipsis),
        ),
        Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Text('Carte professionnelle, autorisation d\'ouverture, facture… (PDF ou photo)',
              style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 12.5)),
        ),
        const SizedBox(height: 20),
        FilledButton(onPressed: _envoi ? null : _envoyer, child: Text(_envoi ? 'Envoi…' : 'Envoyer la demande')),
      ]),
    );
  }
}
