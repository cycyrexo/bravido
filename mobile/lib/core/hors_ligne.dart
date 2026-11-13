import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';

import 'api.dart';
import 'cache.dart';
import 'moteur_local.dart';

/// Une ville téléchargée pour le mode hors ligne.
class VilleHorsLigne {
  VilleHorsLigne({required this.id, required this.nom, required this.version, required this.date, required this.nombre, required this.octets});

  final int id;
  final String nom;
  final String version;
  final DateTime date;
  final int nombre;
  final int octets;

  Map<String, dynamic> toJson() => {'id': id, 'nom': nom, 'version': version, 'date': date.toIso8601String(), 'nombre': nombre, 'octets': octets};

  static VilleHorsLigne? fromJson(Object? j) {
    if (j is! Map) return null;
    final date = DateTime.tryParse('${j['date']}');
    if (date == null || j['id'] is! int) return null;
    return VilleHorsLigne(
      id: j['id'] as int,
      nom: '${j['nom']}',
      version: '${j['version']}',
      date: date,
      nombre: (j['nombre'] as num?)?.toInt() ?? 0,
      octets: (j['octets'] as num?)?.toInt() ?? 0,
    );
  }
}

/// F20 : téléchargement et stockage local des établissements d'une ville (fichier JSON compact par ville),
/// et réponse locale aux requêtes de l'application quand le réseau est injoignable.
/// Les fonds de carte (tuiles) ne sont PAS mis en cache.
class HorsLigne extends ChangeNotifier {
  HorsLigne._();
  static final HorsLigne instance = HorsLigne._();

  static const _cleMeta = 'hors_ligne_villes';
  static const parPage = 400;

  /// Dossier de stockage (remplaçable dans les tests).
  Directory? dossier;

  final Map<int, VilleHorsLigne> _villes = {};
  final Map<int, List<Map<String, dynamic>>> _donnees = {};
  bool _charge = false;

  /// Date des données locales actuellement affichées à la place du réseau (null : on est en ligne).
  final ValueNotifier<DateTime?> donneesLocalesDu = ValueNotifier(null);

  /// Téléchargement en cours : ville, avancement 0..1.
  int? telechargementVille;
  double progression = 0;
  String? erreur;

  List<VilleHorsLigne> get villes => _villes.values.toList()..sort((a, b) => a.nom.compareTo(b.nom));
  VilleHorsLigne? ville(int id) => _villes[id];
  bool get aDesDonnees => _villes.isNotEmpty;
  int get octetsTotal => _villes.values.fold(0, (s, v) => s + v.octets);

  Future<Directory> _dossier() async {
    final d = dossier ?? Directory('${(await getApplicationSupportDirectory()).path}/hors_ligne');
    if (!d.existsSync()) d.createSync(recursive: true);
    return d;
  }

  File _fichier(Directory d, int id) => File('${d.path}/ville_$id.json');

  /// Lit l'index des villes téléchargées et leurs données (appelé au démarrage et avant chaque secours).
  Future<void> charger() async {
    if (_charge) return;
    _charge = true;
    final meta = Cache.lire(_cleMeta);
    if (meta is! List) return;
    try {
      final d = await _dossier();
      for (final m in meta) {
        final v = VilleHorsLigne.fromJson(m);
        if (v == null) continue;
        final f = _fichier(d, v.id);
        if (!f.existsSync()) continue;
        try {
          final j = jsonDecode(await f.readAsString()) as Map;
          _donnees[v.id] = (j['etablissements'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
          _villes[v.id] = v;
        } catch (_) {
          // fichier corrompu : ville ignorée (à retélécharger)
        }
      }
    } catch (_) {}
    notifyListeners();
  }

  Future<void> _sauverMeta() => Cache.ecrire(_cleMeta, _villes.values.map((v) => v.toJson()).toList());

  /// Télécharge (ou met à jour) une ville. Retourne false si les données étaient déjà à jour.
  Future<bool> telecharger(int villeId, String nom) async {
    await charger();
    if (telechargementVille != null) return false;
    telechargementVille = villeId;
    progression = 0;
    erreur = null;
    notifyListeners();
    try {
      final actuelle = _villes[villeId];
      final toutes = <Map<String, dynamic>>[];
      String? version;
      var pages = 1;
      for (var page = 1; page <= pages; page++) {
        final r = await Api.instance.get('/villes/$villeId/export', query: {
          'page': page,
          'par_page': parPage,
          // Seule la 1re requête teste la version : si elle est identique, rien à retélécharger
          if (page == 1 && actuelle != null) 'version': actuelle.version,
        }) as Map;
        if (r['a_jour'] == true) return false;
        final meta = r['meta'] as Map;
        if (version != null && version != meta['version']) throw ApiException('Les données ont changé pendant le téléchargement. Réessayez.');
        version = '${meta['version']}';
        pages = (meta['pages'] as num).toInt();
        toutes.addAll((r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)));
        progression = page / pages;
        notifyListeners();
      }
      final d = await _dossier();
      final contenu = jsonEncode({'version': version, 'ville': {'id': villeId, 'nom': nom}, 'etablissements': toutes});
      // Écriture atomique : fichier temporaire puis renommage (jamais de pack à moitié écrit)
      final tmp = File('${_fichier(d, villeId).path}.tmp');
      await tmp.writeAsString(contenu, flush: true);
      await tmp.rename(_fichier(d, villeId).path);
      _donnees[villeId] = toutes;
      _villes[villeId] = VilleHorsLigne(id: villeId, nom: nom, version: version!, date: DateTime.now(), nombre: toutes.length, octets: utf8.encode(contenu).length);
      await _sauverMeta();
      return true;
    } on ApiException catch (e) {
      erreur = e.message;
      return false;
    } catch (e) {
      erreur = 'Téléchargement impossible.';
      return false;
    } finally {
      telechargementVille = null;
      notifyListeners();
    }
  }

  Future<void> supprimer(int villeId) async {
    await charger();
    _villes.remove(villeId);
    _donnees.remove(villeId);
    try {
      final f = _fichier(await _dossier(), villeId);
      if (f.existsSync()) f.deleteSync();
    } catch (_) {}
    await _sauverMeta();
    notifyListeners();
  }

  /// Efface tout (déconnexion / réinitialisation).
  Future<void> toutSupprimer() async {
    for (final id in _villes.keys.toList()) {
      await supprimer(id);
    }
  }

  // ---- Secours hors ligne ----

  /// Appelé par `Api.get` après un échec réseau : réponse locale ou null (erreur normale affichée).
  Future<Map<String, dynamic>?> repondre(String chemin, Map<String, dynamic>? query) async {
    await charger();
    if (_villes.isEmpty) return null;
    final villeDemandee = int.tryParse('${query?['ville_id'] ?? ''}');
    // Ville demandée non téléchargée : on n'invente pas de résultats vides
    if (villeDemandee != null && !_villes.containsKey(villeDemandee)) return null;
    final etabs = [for (final e in _donnees.entries) ...e.value];
    final r = MoteurLocal(etabs).repondre(chemin, query);
    if (r != null) {
      final dates = (villeDemandee != null ? [_villes[villeDemandee]!] : _villes.values).map((v) => v.date);
      donneesLocalesDu.value = dates.reduce((a, b) => a.isBefore(b) ? a : b);
    }
    return r;
  }

  /// Une requête réseau a réussi : le bandeau « Hors ligne » disparaît.
  void enLigne() {
    if (donneesLocalesDu.value != null) donneesLocalesDu.value = null;
  }

  /// Fiches des favoris (identifiants en cache), lues dans les données locales.
  Future<List<Map<String, dynamic>>> favorisLocaux(List<int> ids) async {
    await charger();
    final etabs = [for (final e in _donnees.entries) ...e.value];
    final moteur = MoteurLocal(etabs);
    return [for (final id in ids) if (moteur.parId(id) != null) moteur.parId(id)!];
  }

  /// Remise à zéro des données en mémoire (tests).
  @visibleForTesting
  void reinitialiser() {
    _villes.clear();
    _donnees.clear();
    _charge = false;
    telechargementVille = null;
    erreur = null;
    donneesLocalesDu.value = null;
  }
}
