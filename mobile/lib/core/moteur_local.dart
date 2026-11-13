import 'dart:math';

import 'synonymes.dart';

/// F20 : moteur de requêtes sur les données téléchargées (mode hors ligne).
/// Reproduit, sur la liste d'établissements d'un export, les réponses des routes de l'API :
/// /etablissements, /pharmacies, /pharmacies/garde, /etablissements/{id}, /etablissements/carte,
/// /recherche et /recherche/suggestions (mêmes synonymes et même normalisation que le serveur).
/// Code Dart pur (sans Flutter) pour être testé facilement.
class MoteurLocal {
  MoteurLocal(Iterable<Map<String, dynamic>> etablissements, {DateTime? aujourdhui})
      : _etabs = etablissements.toList(),
        _jour = _formatJour(aujourdhui ?? DateTime.now());

  final List<Map<String, dynamic>> _etabs;
  final String _jour; // yyyy-MM-dd

  static const _maxResultats = 40;

  int get nombre => _etabs.length;

  static String _formatJour(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  // ---- Normalisation (identique à Recherche::normaliser côté serveur) ----

  static const _accents = {
    'à': 'a', 'á': 'a', 'â': 'a', 'ä': 'a', 'ã': 'a', 'å': 'a', 'ç': 'c', 'è': 'e', 'é': 'e', 'ê': 'e', 'ë': 'e',
    'ì': 'i', 'í': 'i', 'î': 'i', 'ï': 'i', 'ñ': 'n', 'ò': 'o', 'ó': 'o', 'ô': 'o', 'ö': 'o', 'õ': 'o', 'œ': 'oe',
    'ù': 'u', 'ú': 'u', 'û': 'u', 'ü': 'u', 'ý': 'y', 'ÿ': 'y', 'æ': 'ae',
  };

  static String normaliser(String s) {
    final b = StringBuffer();
    for (final c in s.toLowerCase().split('')) {
      b.write(_accents[c] ?? c);
    }
    return b.toString().replaceAll(RegExp(r'[^a-z0-9.\s]+'), ' ').replaceAll(RegExp(r'\s+'), ' ').trim();
  }

  // ---- Routage ----

  /// Réponse locale à `GET chemin?query`, ou null si la route n'est pas gérée hors ligne.
  Map<String, dynamic>? repondre(String chemin, Map<String, dynamic>? query) {
    final q = query ?? const {};
    final c = chemin.split('?').first;
    if (c == '/etablissements') return _liste(q);
    if (c == '/pharmacies') return _liste({...q, 'categorie': 'pharmacie'});
    if (c == '/pharmacies/garde') return _gardes(q);
    if (c == '/etablissements/carte') return _carte(q);
    if (c == '/recherche') return _recherche(q);
    if (c == '/recherche/suggestions') return {'data': suggestions('${q['q'] ?? ''}')};
    final m = RegExp(r'^/etablissements/(\d+)$').firstMatch(c);
    if (m != null) {
      final id = int.parse(m.group(1)!);
      final e = _etabs.where((e) => e['id'] == id).firstOrNull;
      return e == null ? null : {'data': _complet(e)};
    }
    return null;
  }

  Map<String, dynamic>? parId(int id) {
    final e = _etabs.where((e) => e['id'] == id).firstOrNull;
    return e == null ? null : _complet(e);
  }

  // ---- Listes ----

  static int? _entier(Object? v) => v == null || '$v'.isEmpty ? null : int.tryParse('$v');
  static double? _reel(Object? v) => v == null || '$v'.isEmpty ? null : double.tryParse('$v');
  static bool _vrai(Object? v) => v == true || v == 1 || v == '1' || v == 'true';

  List<String> _codes(Object? v) => '${v ?? ''}'.split(',').map((e) => e.trim()).where((e) => e.isNotEmpty).toList();

  String? _codeCategorie(Map<String, dynamic> e) {
    final c = e['categorie'];
    return c is Map ? c['code'] as String? : null;
  }

  bool _assure(Map<String, dynamic> e, int id) => ((e['assurances'] as List?) ?? const []).any((a) => a is Map && a['id'] == id);

  List<String> _services(Map<String, dynamic> e) => ((e['services'] as List?) ?? const []).map((s) => '$s').toList();

  Map<String, dynamic> _liste(Map<String, dynamic> f) {
    final codes = _codes(f['categorie']);
    final commune = _entier(f['commune_id']);
    final ville = _entier(f['ville_id']);
    final texte = normaliser('${f['q'] ?? ''}');
    final service = '${f['service'] ?? ''}';
    final assurance = _entier(f['assurance_id']);
    final lat = _reel(f['lat']);
    final lng = _reel(f['lng']);
    final rayon = _reel(f['rayon_km']);

    var res = _etabs.where((e) {
      if (codes.isNotEmpty && !codes.contains(_codeCategorie(e))) return false;
      if (commune != null) {
        if (e['commune_id'] != commune) return false;
      } else if (ville != null && e['ville_id'] != ville) {
        return false;
      }
      if (texte.isNotEmpty && !normaliser('${e['nom']}').contains(texte)) return false;
      if (service.isNotEmpty && !_services(e).contains(service)) return false;
      if (assurance != null && !_assure(e, assurance)) return false;
      if (_vrai(f['h24']) && e['h24'] != true) return false;
      return true;
    }).map((e) => _resume(e)).toList();

    if (lat != null && lng != null) {
      final sansZone = commune == null && ville == null;
      if (sansZone || rayon != null) {
        final km = rayon ?? 25;
        res = res.where((e) => e['position_approx'] != true && _distance(lat, lng, e) <= km * 1000).toList();
      }
      for (final e in res) {
        // Position approximative : pas de distance (jamais « 0 m »)
        if (e['position_approx'] != true && e['lat'] != null) e['distance_m'] = _distance(lat, lng, e).round();
      }
      res.sort((a, b) {
        final da = a['distance_m'] as int?;
        final db = b['distance_m'] as int?;
        if (da != null && db != null) return da.compareTo(db);
        if (da != null) return -1;
        if (db != null) return 1;
        return '${a['nom']}'.compareTo('${b['nom']}');
      });
    } else {
      res.sort((a, b) => normaliser('${a['nom']}').compareTo(normaliser('${b['nom']}')));
    }
    return _page(res, f);
  }

  Map<String, dynamic> _page(List<Map<String, dynamic>> res, Map<String, dynamic> f) {
    final parPage = (_entier(f['par_page']) ?? 30).clamp(1, 100);
    final page = max(1, _entier(f['page']) ?? 1);
    final dernier = max(1, (res.length / parPage).ceil());
    final debut = (page - 1) * parPage;
    return {
      'data': res.skip(debut).take(parPage).toList(),
      'meta': {'total': res.length, 'current_page': page, 'last_page': dernier, 'per_page': parPage},
    };
  }

  Map<String, dynamic> _gardes(Map<String, dynamic> f) {
    final ville = _entier(f['ville_id']);
    final assurance = _entier(f['assurance_id']);
    final lat = _reel(f['lat']);
    final lng = _reel(f['lng']);
    final res = <Map<String, dynamic>>[];
    for (final e in _etabs) {
      if (_codeCategorie(e) != 'pharmacie') continue;
      if (ville != null && e['ville_id'] != ville) continue;
      if (assurance != null && !_assure(e, assurance)) continue;
      final garde = ((e['gardes'] as List?) ?? const [])
          .whereType<Map>()
          .where((g) => '${g['du']}'.compareTo(_jour) <= 0 && '${g['au']}'.compareTo(_jour) >= 0)
          .firstOrNull;
      if (garde == null) continue;
      res.add({..._resume(e), 'garde': {'du': garde['du'], 'au': garde['au']}, 'de_garde': true});
    }
    if (lat != null && lng != null) {
      for (final e in res) {
        if (e['position_approx'] != true && e['lat'] != null) e['distance_m'] = _distance(lat, lng, e).round();
      }
      res.sort((a, b) {
        final da = a['distance_m'] as int?;
        final db = b['distance_m'] as int?;
        if (da != null && db != null) return da.compareTo(db);
        if (da != null) return -1;
        if (db != null) return 1;
        return '${a['nom']}'.compareTo('${b['nom']}');
      });
    } else {
      res.sort((a, b) => normaliser('${a['nom']}').compareTo(normaliser('${b['nom']}')));
    }
    final premier = res.isEmpty ? null : res.first['garde'] as Map;
    return {'data': res.take(300).toList(), 'periode': premier == null ? null : {'du': premier['du'], 'au': premier['au']}};
  }

  Map<String, dynamic> _carte(Map<String, dynamic> f) {
    final bbox = '${f['bbox'] ?? ''}'.split(',').map((e) => double.tryParse(e)).toList();
    if (bbox.length != 4 || bbox.contains(null)) return {'data': <dynamic>[], 'meta': {'total': 0, 'limit': 0, 'tronque': false, 'sans_position': 0}};
    final [s, o, n, est] = bbox.cast<double>();
    final codes = _codes(f['categorie']);
    final limite = _entier(f['limit']) ?? 300;
    final zone = _etabs.where((e) {
      final lat = _reel(e['lat']);
      final lng = _reel(e['lng']);
      if (lat == null || lng == null || lat < s || lat > n || lng < o || lng > est) return false;
      return codes.isEmpty || codes.contains(_codeCategorie(e));
    }).toList();
    final precis = zone.where((e) => e['position_approx'] != true).toList();
    return {
      'data': precis.take(limite).map((e) => {'id': e['id'], 'nom': e['nom'], 'categorie': _codeCategorie(e), 'lat': _reel(e['lat']), 'lng': _reel(e['lng']), 'h24': e['h24'] == true}).toList(),
      'meta': {'total': precis.length, 'limit': limite, 'tronque': precis.length > limite, 'sans_position': zone.length - precis.length},
    };
  }

  // ---- Recherche ----

  Map<String, dynamic> _analyser(String q) {
    final norm = normaliser(q);
    final jetons = norm.split(' ').where((t) => t.isNotEmpty && !motsVides.contains(t)).toList();
    final cats = <String>[];
    final svcs = <String>[];
    final restants = <String>[];
    final corriges = <String, String>{};
    for (final t in jetons) {
      String? cle = t;
      if (!synonymesRecherche.containsKey(cle)) {
        cle = _synonymeProche(t);
        if (cle != null) corriges[t] = cle;
      }
      if (cle != null && synonymesRecherche.containsKey(cle)) {
        cats.addAll(synonymesRecherche[cle]!['cat'] ?? const []);
        svcs.addAll(synonymesRecherche[cle]!['svc'] ?? const []);
      } else {
        restants.add(t);
      }
    }
    return {
      'norm': norm,
      'jetons': jetons,
      'alternatives': jetons.map((t) => [t]).toList(),
      'restants': restants,
      'categories': cats.toSet().toList(),
      'services': svcs.toSet().toList(),
      'corriges': corriges,
      'corrige': false,
    };
  }

  static int _levenshtein(String a, String b) {
    if (a == b) return 0;
    var prec = List<int>.generate(b.length + 1, (i) => i);
    for (var i = 1; i <= a.length; i++) {
      final cur = List<int>.filled(b.length + 1, 0)..[0] = i;
      for (var j = 1; j <= b.length; j++) {
        final cout = a[i - 1] == b[j - 1] ? 0 : 1;
        cur[j] = min(min(cur[j - 1] + 1, prec[j] + 1), prec[j - 1] + cout);
      }
      prec = cur;
    }
    return prec[b.length];
  }

  String? _synonymeProche(String t) {
    final len = t.length;
    if (len < 5) return null;
    final tolerance = len >= 8 ? 2 : 1;
    String? meilleur;
    var d0 = tolerance + 1;
    for (final cle in synonymesRecherche.keys) {
      if ((cle.length - len).abs() > tolerance) continue;
      final d = _levenshtein(t, cle);
      if (d < d0) {
        d0 = d;
        meilleur = cle;
      }
    }
    return meilleur;
  }

  Map<String, dynamic> _corrigerParVocabulaire(Map<String, dynamic> a) {
    final vocab = <String>{};
    for (final e in _etabs) {
      for (final m in normaliser('${e['nom']}').split(' ')) {
        if (m.length >= 4) vocab.add(m);
      }
    }
    final alts = <List<String>>[];
    for (final t in (a['jetons'] as List).cast<String>()) {
      final liste = <String>[t];
      final len = t.length;
      if (len >= 4) {
        final tol = len >= 8 ? 2 : 1;
        for (final m in vocab) {
          if ((m.length - len).abs() <= tol && _levenshtein(t, m) <= tol) liste.add(m);
        }
      }
      alts.add(liste.take(8).toList());
    }
    return {...a, 'alternatives': alts, 'corrige': true};
  }

  bool _nomContient(String nom, List<dynamic> alternatives) =>
      alternatives.every((alts) => (alts as List).any((m) => nom.contains(m as String)));

  List<Map<String, dynamic>> _trouver(Map<String, dynamic> a, int? ville) {
    final cats = (a['categories'] as List).cast<String>();
    final svcs = (a['services'] as List).cast<String>();
    final restants = (a['restants'] as List).cast<String>();
    final alternatives = a['alternatives'] as List;
    final restantsAlt = alternatives.where((alts) => restants.contains((alts as List).first)).toList();
    final aIntention = cats.isNotEmpty || svcs.isNotEmpty;
    final norm = a['norm'] as String;

    bool serviceContient(Map<String, dynamic> e, String mot) => _services(e).any((s) => normaliser(s).contains(mot));

    final res = _etabs.where((e) {
      if (ville != null && e['ville_id'] != ville) return false;
      final nom = normaliser('${e['nom']}');
      if (_nomContient(nom, alternatives)) return true;
      if (aIntention) {
        final intention = cats.contains(_codeCategorie(e)) || svcs.any((s) => serviceContient(e, s));
        return intention && _nomContient(nom, restantsAlt);
      }
      return serviceContient(e, norm);
    }).toList();
    res.sort((x, y) => normaliser('${x['nom']}').compareTo(normaliser('${y['nom']}')));
    return res.take(1500).toList();
  }

  double _score(Map<String, dynamic> e, Map<String, dynamic> a, double? distance) {
    final nom = normaliser('${e['nom']}');
    final q = a['norm'] as String;
    var score = 0.0;
    if (nom == q) {
      score = 1000;
    } else if (nom.startsWith(q)) {
      score = 800;
    } else if (RegExp('(^|\\s)${RegExp.escape(q)}').hasMatch(nom)) {
      score = 650;
    } else if (nom.contains(q)) {
      score = 500;
    } else {
      final jetons = a['jetons'] as List;
      final tous = jetons.isNotEmpty && (a['alternatives'] as List).every((alts) => (alts as List).any((m) => nom.contains(m as String)));
      if (tous) score = a['corrige'] == true ? 250 : 450;
    }
    final cats = (a['categories'] as List).cast<String>();
    final catOk = cats.isNotEmpty && cats.contains(_codeCategorie(e));
    if (score < 300 && catOk) score = 300;
    if (catOk && score >= 300) score += 20;
    if (score == 0) score = 200;
    if (e['position_approx'] != true) {
      score += 10;
      if (distance != null) score += 50 * exp(-distance / 5000);
    }
    return score;
  }

  Map<String, dynamic> _recherche(Map<String, dynamic> f) {
    var a = _analyser('${f['q'] ?? ''}');
    if ((a['jetons'] as List).isEmpty) return {'data': <dynamic>[], 'meta': {'total': 0, 'groupes': <dynamic>[]}};
    final ville = _entier(f['ville_id']);
    final lat = _reel(f['lat']);
    final lng = _reel(f['lng']);

    var ids = _trouver(a, ville);
    if (ids.isEmpty) {
      a = _corrigerParVocabulaire(a);
      ids = _trouver(a, ville);
    }
    final groupes = <String, Map<String, dynamic>>{};
    for (final e in ids) {
      final code = _codeCategorie(e) ?? '';
      final g = groupes.putIfAbsent(code, () => {'code': code, 'libelle': (e['categorie'] as Map?)?['libelle'] ?? '', 'total': 0});
      g['total'] = (g['total'] as int) + 1;
    }
    final liste = groupes.values.toList()..sort((x, y) => (y['total'] as int).compareTo(x['total'] as int));

    final codes = _codes(f['categorie']);
    var retenus = ids;
    if (codes.isNotEmpty) retenus = ids.where((e) => codes.contains(_codeCategorie(e))).toList();

    final notes = <Map<String, dynamic>, double>{};
    for (var i = 0; i < retenus.length; i++) {
      final e = retenus[i];
      final d = lat != null && lng != null && e['position_approx'] != true && e['lat'] != null ? _distance(lat, lng, e) : null;
      notes[e] = _score(e, a, d) - i / 1000;
    }
    final classes = [...retenus]..sort((x, y) => notes[y]!.compareTo(notes[x]!));
    return {
      'data': classes.take(_maxResultats).map((e) {
        final r = _resume(e);
        if (lat != null && lng != null && e['position_approx'] != true && e['lat'] != null) r['distance_m'] = _distance(lat, lng, e).round();
        return r;
      }).toList(),
      'meta': {'total': classes.length, 'groupes': liste},
    };
  }

  /// Suggestions rapides : catégories, services et noms (au plus 8), comme le serveur.
  List<Map<String, dynamic>> suggestions(String q) {
    final a = _analyser(q);
    final norm = a['norm'] as String;
    if (norm.length < 2) return [];
    final out = <Map<String, dynamic>>[];

    for (final code in (a['categories'] as List).cast<String>().take(2)) {
      final e = _etabs.where((e) => _codeCategorie(e) == code).firstOrNull;
      final libelle = (e?['categorie'] as Map?)?['libelle'];
      if (libelle != null) out.add({'type': 'categorie', 'libelle': libelle, 'valeur': code});
    }
    final services = <String>{};
    for (final e in _etabs) {
      for (final s in _services(e)) {
        if (s.length <= 40 && normaliser(s).contains(norm)) services.add(s);
      }
    }
    final tries = services.toList()..sort();
    for (final s in tries.take(3)) {
      out.add({'type': 'service', 'libelle': s, 'valeur': s});
    }
    final reste = 8 - out.length;
    final noms = _etabs.where((e) => normaliser('${e['nom']}').contains(norm)).toList()
      ..sort((x, y) {
        final px = normaliser('${x['nom']}').startsWith(norm) ? 0 : 1;
        final py = normaliser('${y['nom']}').startsWith(norm) ? 0 : 1;
        return px != py ? px.compareTo(py) : normaliser('${x['nom']}').compareTo(normaliser('${y['nom']}'));
      });
    for (final e in noms.take(max(2, reste))) {
      out.add({'type': 'etablissement', 'libelle': e['nom'], 'valeur': e['nom'], 'id': e['id'], 'categorie': _codeCategorie(e), 'commune': e['commune']});
    }
    return out.take(8).toList();
  }

  // ---- Fiches ----

  /// Version « liste » d'une fiche : mêmes clés que l'API (nombre d'assurances, de garde...).
  Map<String, dynamic> _resume(Map<String, dynamic> e) {
    final garde = ((e['gardes'] as List?) ?? const []).whereType<Map>().where((g) => '${g['du']}'.compareTo(_jour) <= 0 && '${g['au']}'.compareTo(_jour) >= 0);
    return {
      ...e,
      'nb_assurances': ((e['assurances'] as List?) ?? const []).length,
      'de_garde': garde.isNotEmpty,
      'services': e['services'] ?? <String>[],
    };
  }

  Map<String, dynamic> _complet(Map<String, dynamic> e) => {..._resume(e), 'assurances': e['assurances'] ?? <dynamic>[]};

  static double _distance(double lat, double lng, Map<String, dynamic> e) {
    final la = _reel(e['lat']);
    final ln = _reel(e['lng']);
    if (la == null || ln == null) return double.infinity;
    const r = 6371000.0;
    double rad(double d) => d * pi / 180;
    final dLat = rad(la - lat);
    final dLng = rad(ln - lng);
    final h = pow(sin(dLat / 2), 2) + cos(rad(lat)) * cos(rad(la)) * pow(sin(dLng / 2), 2);
    return 2 * r * asin(sqrt(h));
  }
}
