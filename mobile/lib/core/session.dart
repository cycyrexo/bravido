import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../config.dart';
import 'api.dart';
import 'cache.dart';

/// État global : jeton, utilisateur, ville choisie, configuration (mise en cache hors ligne).
class Session extends ChangeNotifier {
  Session._();

  static final Session instance = Session._();
  static const _stockage = FlutterSecureStorage();

  String? token;
  Map<String, dynamic>? user;
  Map<String, dynamic>? config;
  int? villeId;

  bool get connecte => token != null;

  Future<void> init() async {
    token = await _stockage.read(key: 'token');
    user = Cache.lireMap('user');
    config = Cache.lireMap('config');
    villeId = Cache.prefs.getInt('ville_id');
    rafraichirConfig();
  }

  Future<void> rafraichirConfig() async {
    try {
      final data = await Api.instance.get('/config');
      config = Map<String, dynamic>.from(data as Map);
      await Cache.ecrire('config', config);
      villeId ??= _villeParDefaut();
      notifyListeners();
    } catch (_) {
      // hors ligne : on garde la configuration en cache
    }
  }

  // ---- Référentiels ----

  List<Map<String, dynamic>> _liste(String cle) =>
      ((config?[cle] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();

  List<Map<String, dynamic>> get villes => _liste('villes');
  List<Map<String, dynamic>> get categories => _liste('categories');
  List<Map<String, dynamic>> get assurances => _liste('assurances');
  List<String> get services => ((config?['services'] as List?) ?? const []).map((e) => '$e').toList();
  String get callCenter => '${config?['call_center'] ?? ''}';

  List<Map<String, dynamic>> get numerosUrgence {
    final l = _liste('numeros_urgence');
    return l.isNotEmpty ? l : Config.numerosUrgenceParDefaut.map(Map<String, dynamic>.from).toList();
  }

  Map<String, dynamic>? get ville =>
      villes.cast<Map<String, dynamic>?>().firstWhere((v) => v?['id'] == villeId, orElse: () => null);

  String get nomVille => '${ville?['nom'] ?? 'Choisir une ville'}';

  int? _villeParDefaut() {
    if (villes.isEmpty) return null;
    final abidjan = villes.where((v) => v['nom'] == 'Abidjan');
    return (abidjan.isNotEmpty ? abidjan.first : villes.first)['id'] as int;
  }

  Future<void> choisirVille(int id) async {
    villeId = id;
    await Cache.prefs.setInt('ville_id', id);
    notifyListeners();
    if (connecte) {
      try {
        await Api.instance.put('/me', {'ville_id': id});
      } catch (_) {}
    }
  }

  // ---- Compte ----

  Future<void> connecter(String nouveauToken, Map<String, dynamic> utilisateur) async {
    token = nouveauToken;
    user = utilisateur;
    await _stockage.write(key: 'token', value: nouveauToken);
    await Cache.ecrire('user', utilisateur);
    if (utilisateur['ville_id'] is int) {
      villeId = utilisateur['ville_id'] as int;
      await Cache.prefs.setInt('ville_id', villeId!);
    }
    notifyListeners();
  }

  Future<void> majUtilisateur(Map<String, dynamic> utilisateur) async {
    user = utilisateur;
    await Cache.ecrire('user', utilisateur);
    notifyListeners();
  }

  Future<void> deconnecter() async {
    try {
      await Api.instance.post('/auth/deconnexion');
    } catch (_) {}
    await deconnecterLocalement();
  }

  Future<void> deconnecterLocalement() async {
    token = null;
    user = null;
    await _stockage.delete(key: 'token');
    await Cache.supprimer('user');
    await Cache.supprimer('complices');
    notifyListeners();
  }
}
