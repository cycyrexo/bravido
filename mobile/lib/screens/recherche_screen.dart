import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/cache.dart';
import '../core/langue.dart';
import '../core/outils.dart';
import '../core/session.dart';
import '../widgets/communs.dart';
import 'etablissement_detail_screen.dart';

/// Barre de recherche arrondie de l'accueil : ouvre la recherche plein écran.
class BarreRecherche extends StatelessWidget {
  const BarreRecherche({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Semantics(
      button: true,
      label: tr('Rechercher un établissement, un service', 'Search a facility or a service'),
      child: Material(
        color: theme.cardTheme.color ?? theme.colorScheme.surface,
        elevation: 2,
        shadowColor: const Color(0x330B2A33),
        shape: const StadiumBorder(),
        child: InkWell(
          customBorder: const StadiumBorder(),
          onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const RechercheScreen())),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
            child: ExcludeSemantics(
              child: Row(children: [
                Icon(Icons.search_rounded, color: theme.colorScheme.primary),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    tr('Rechercher : dentiste, pharmacie, maternité…', 'Search: dentist, pharmacy, maternity…'),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(color: theme.colorScheme.onSurfaceVariant, fontSize: 14.5),
                  ),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

/// F10 : recherche globale plein écran (suggestions, recherches récentes, résultats filtrables par catégorie).
class RechercheScreen extends StatefulWidget {
  const RechercheScreen({super.key});

  @override
  State<RechercheScreen> createState() => _RechercheScreenState();
}

class _RechercheScreenState extends State<RechercheScreen> {
  static const _cleRecentes = 'recherches_recentes';
  static const _maxRecentes = 8;

  final _champ = TextEditingController();
  final _focus = FocusNode();
  Timer? _debounce;
  int _numero = 0; // ignore les réponses dépassées

  List<Map<String, dynamic>> _suggestions = [];
  List<String> _recentes = [];

  String? _requete; // requête dont les résultats sont affichés
  String? _categorie;
  bool _villeSeule = false;
  List<Map<String, dynamic>> _resultats = [];
  List<Map<String, dynamic>> _groupes = [];
  int _total = 0;
  bool _chargement = false;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _recentes = (Cache.lire(_cleRecentes) as List?)?.map((e) => '$e').toList() ?? [];
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _champ.dispose();
    _focus.dispose();
    super.dispose();
  }

  void _auChangement(String texte) {
    _debounce?.cancel();
    final t = texte.trim();
    setState(() {
      if (_requete != null) _requete = null; // retour au mode saisie
      if (t.length < 2) _suggestions = [];
    });
    if (t.length < 2) return;
    _debounce = Timer(const Duration(milliseconds: 300), () => _chargerSuggestions(t));
  }

  Future<void> _chargerSuggestions(String t) async {
    final n = ++_numero;
    try {
      final r = await Api.instance.get('/recherche/suggestions', query: {'q': t}) as Map;
      if (!mounted || n != _numero) return;
      setState(() => _suggestions = (r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList());
    } catch (_) {
      if (mounted && n == _numero) setState(() => _suggestions = []);
    }
  }

  Future<void> _memoriser(String q) async {
    final l = [q, ..._recentes.where((r) => r.toLowerCase() != q.toLowerCase())].take(_maxRecentes).toList();
    _recentes = l;
    await Cache.ecrire(_cleRecentes, l);
  }

  Future<void> _effacerRecentes() async {
    setState(() => _recentes = []);
    await Cache.ecrire(_cleRecentes, <String>[]);
  }

  Future<void> _lancer(String q, {String? categorie, bool garderGroupes = false}) async {
    final t = q.trim();
    if (t.length < 2) return;
    _debounce?.cancel();
    _focus.unfocus();
    final n = ++_numero;
    setState(() {
      _requete = t;
      _categorie = categorie;
      _chargement = true;
      _erreur = null;
      _suggestions = [];
      if (!garderGroupes) _groupes = [];
    });
    if (categorie == null) _memoriser(t);

    final position = Localisation.derniere ?? await Localisation.position(demander: false, delai: const Duration(seconds: 3));
    try {
      final r = await Api.instance.get('/recherche', query: {
        'q': t,
        'categorie': categorie,
        'ville_id': _villeSeule ? Session.instance.villeId : null,
        'lat': position?.latitude,
        'lng': position?.longitude,
      }) as Map;
      if (!mounted || n != _numero) return;
      final meta = (r['meta'] as Map?) ?? const {};
      setState(() {
        _resultats = (r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _total = (meta['total'] as num?)?.toInt() ?? _resultats.length;
        if (!garderGroupes) _groupes = ((meta['groupes'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      });
    } on ApiException catch (e) {
      if (mounted && n == _numero) setState(() => _erreur = e.message);
    } finally {
      if (mounted && n == _numero) setState(() => _chargement = false);
    }
  }

  void _choisirSuggestion(Map<String, dynamic> s) {
    final type = s['type'];
    if (type == 'etablissement') {
      _memoriser('${s['libelle']}');
      ouvrirFiche(context, {'id': s['id'], 'nom': s['libelle'], 'commune': s['commune'], 'categorie': {'code': s['categorie'], 'libelle': ''}});
    } else if (type == 'categorie') {
      _champ.text = '${s['libelle']}';
      _lancer('${s['valeur']}');
    } else {
      _champ.text = '${s['libelle']}';
      _lancer('${s['libelle']}');
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(
        automaticallyImplyLeading: false,
        titleSpacing: 6,
        leading: IconButton(
          tooltip: tr('Retour', 'Back'),
          icon: const Icon(Icons.chevron_left, size: 28),
          onPressed: () => Navigator.maybePop(context),
        ),
        title: TextField(
          controller: _champ,
          focusNode: _focus,
          autofocus: true,
          textInputAction: TextInputAction.search,
          onChanged: _auChangement,
          onSubmitted: _lancer,
          decoration: InputDecoration(
            hintText: tr('Nom, service, catégorie…', 'Name, service, category…'),
            prefixIcon: const Icon(Icons.search_rounded),
            suffixIcon: _champ.text.isEmpty
                ? null
                : IconButton(
                    tooltip: tr('Effacer', 'Clear'),
                    icon: const Icon(Icons.close_rounded),
                    onPressed: () {
                      _champ.clear();
                      _auChangement('');
                      _focus.requestFocus();
                    },
                  ),
          ),
        ),
        actions: const [SizedBox(width: 12)],
      ),
      body: _requete != null ? _vueResultats(theme) : _vueSaisie(theme),
    );
  }

  Widget _vueSaisie(ThemeData theme) {
    final texte = _champ.text.trim();
    if (texte.length >= 2 && _suggestions.isNotEmpty) {
      return ListView.separated(
        padding: const EdgeInsets.fromLTRB(8, 4, 8, 24),
        itemCount: _suggestions.length,
        separatorBuilder: (_, __) => const Divider(height: 1),
        itemBuilder: (_, i) {
          final s = _suggestions[i];
          final (icone, soustitre) = switch (s['type']) {
            'categorie' => (Icons.category_outlined, tr('Catégorie', 'Category')),
            'service' => (Icons.medical_services_outlined, tr('Service', 'Service')),
            _ => (Icons.place_outlined, [s['commune'], ].whereType<String>().join(' · ')),
          };
          return ListTile(
            leading: s['type'] == 'etablissement' && s['categorie'] != null
                ? IconeCategorie('${s['categorie']}', taille: 38)
                : Icon(icone, color: theme.colorScheme.primary),
            title: Text('${s['libelle']}', maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: soustitre.isEmpty ? null : Text(soustitre),
            trailing: IconButton(
              tooltip: tr('Utiliser ce texte', 'Use this text'),
              icon: const Icon(Icons.north_west_rounded, size: 18),
              onPressed: () {
                _champ.text = '${s['libelle']}';
                _champ.selection = TextSelection.collapsed(offset: _champ.text.length);
                _auChangement(_champ.text);
              },
            ),
            onTap: () => _choisirSuggestion(s),
          );
        },
      );
    }
    if (texte.length >= 2) {
      return MessageVide(tr('Appuyez sur Rechercher pour lancer la recherche.', 'Press Search to start the search.'), icone: Icons.search_rounded);
    }
    if (_recentes.isEmpty) {
      return MessageVide(
        tr('Cherchez un établissement, un service ou une catégorie : « dentiste », « maternité », « labo »…',
            'Look for a facility, a service or a category: "dentist", "maternity", "lab"…'),
        icone: Icons.search_rounded,
      );
    }
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
      children: [
        Row(children: [
          Expanded(child: TitreSection(tr('Recherches récentes', 'Recent searches'))),
          TextButton(onPressed: _effacerRecentes, child: Text(tr('Effacer', 'Clear'))),
        ]),
        for (final r in _recentes)
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: const Icon(Icons.history_rounded),
            title: Text(r),
            onTap: () {
              _champ.text = r;
              _lancer(r);
            },
          ),
      ],
    );
  }

  Widget _vueResultats(ThemeData theme) {
    final totalGroupes = _groupes.fold<int>(0, (s, g) => s + ((g['total'] as num?)?.toInt() ?? 0));
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(children: [
            if (_groupes.length > 1 || _categorie != null)
              _Puce(
                label: '${tr('Tous', 'All')} ($totalGroupes)',
                selectionnee: _categorie == null,
                onTap: () => _lancer(_requete!, garderGroupes: true),
              ),
            for (final g in _groupes)
              _Puce(
                label: '${g['libelle']} (${g['total']})',
                selectionnee: _categorie == g['code'],
                onTap: () => _lancer(_requete!, categorie: '${g['code']}', garderGroupes: true),
              ),
          ]),
        ),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 2, 16, 0),
        child: Row(children: [
          FilterChip(
            label: Text(tr('${Session.instance.nomVille} uniquement', '${Session.instance.nomVille} only')),
            selected: _villeSeule,
            labelStyle: TextStyle(color: _villeSeule ? Colors.white : theme.colorScheme.onSurface, fontWeight: FontWeight.w700, fontSize: 13),
            showCheckmark: false,
            visualDensity: VisualDensity.compact,
            onSelected: (v) {
              setState(() => _villeSeule = v);
              _lancer(_requete!, categorie: _categorie, garderGroupes: false);
            },
          ),
        ]),
      ),
      Expanded(
        child: _chargement
            ? const Chargement()
            : _erreur != null
                ? MessageVide(_erreur!, icone: Icons.cloud_off, onReessayer: () => _lancer(_requete!, categorie: _categorie))
                : _resultats.isEmpty
                    ? MessageVide(
                        tr('Aucun résultat pour « $_requete ». Vérifiez l\'orthographe ou essayez un mot plus simple.',
                            'No result for "$_requete". Check the spelling or try a simpler word.'),
                        icone: Icons.search_off)
                    : ListView.builder(
                        padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
                        itemCount: _resultats.length + 1,
                        itemBuilder: (_, i) {
                          if (i == _resultats.length) {
                            return Padding(
                              padding: const EdgeInsets.only(top: 10),
                              child: Text(
                                _total > _resultats.length
                                    ? tr('${_resultats.length} résultats affichés sur $_total · affinez votre recherche',
                                        '${_resultats.length} of $_total results shown · refine your search')
                                    : tr('$_total résultat${_total > 1 ? 's' : ''}', '$_total result${_total > 1 ? 's' : ''}'),
                                textAlign: TextAlign.center,
                                style: TextStyle(color: theme.colorScheme.onSurfaceVariant, fontSize: 12.5),
                              ),
                            );
                          }
                          return EtablissementTile(_resultats[i]);
                        },
                      ),
      ),
    ]);
  }
}

class _Puce extends StatelessWidget {
  const _Puce({required this.label, required this.selectionnee, required this.onTap});

  final String label;
  final bool selectionnee;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: ChoiceChip(
        label: Text(label),
        selected: selectionnee,
        labelStyle: TextStyle(color: selectionnee ? Colors.white : Theme.of(context).colorScheme.onSurface, fontWeight: FontWeight.w700, fontSize: 13),
        onSelected: (_) => onTap(),
        visualDensity: VisualDensity.compact,
      ),
    );
  }
}
