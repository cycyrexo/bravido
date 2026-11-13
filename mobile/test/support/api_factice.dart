import 'dart:convert';
import 'dart:typed_data';

import 'package:bravido/core/api.dart';
import 'package:bravido/core/cache.dart';
import 'package:bravido/core/session.dart';
import 'package:dio/dio.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Réponse simulée : corps JSON + code HTTP. `horsLigne` simule l'absence de réseau.
class Reponse {
  Reponse(this.corps, {this.statut = 200});
  final Object? corps;
  final int statut;
}

typedef Gestionnaire = Reponse Function(RequestOptions requete);

/// Couche API simulée pour les tests : remplace l'adaptateur HTTP de `Api.instance`.
/// Routes sous la forme `'GET /etablissements/5'` ; correspondance exacte, puis par préfixe (`'GET /recherche*'`).
class ApiFactice implements HttpClientAdapter {
  final Map<String, Gestionnaire> _routes = {};
  final List<String> appels = [];
  bool horsLigne = false;

  void repondre(String route, Object? corps, {int statut = 200}) => _routes[route] = (_) => Reponse(corps, statut: statut);

  void repondreAvec(String route, Gestionnaire g) => _routes[route] = g;

  bool appele(String route) => appels.any((a) => a == route || (route.endsWith('*') && a.startsWith(route.substring(0, route.length - 1))));

  int nombreAppels(String prefixe) => appels.where((a) => a.startsWith(prefixe)).length;

  /// Installe la simulation, vide les préférences et la session.
  static ApiFactice installer({Map<String, Object> prefs = const {}}) {
    final f = ApiFactice();
    Api.instance.dio.httpClientAdapter = f;
    SharedPreferences.setMockInitialValues(prefs);
    return f;
  }

  static Future<void> preparerCache([Map<String, Object> prefs = const {}]) async {
    SharedPreferences.setMockInitialValues(prefs);
    await Cache.init();
    final s = Session.instance;
    s.token = null;
    s.user = null;
    s.config = null;
    s.villeId = null;
  }

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    final cle = '${options.method} ${options.path}';
    appels.add(cle);
    if (horsLigne) {
      throw DioException.connectionError(requestOptions: options, reason: 'hors ligne simulé');
    }
    final g = _routes[cle] ?? _routes['${cle.split('?').first}*'] ?? _parPrefixe(cle);
    final r = g?.call(options) ?? Reponse({'message': 'Route non simulée : $cle'}, statut: 404);
    final octets = utf8.encode(jsonEncode(r.corps));
    return ResponseBody.fromBytes(octets, r.statut, headers: {
      Headers.contentTypeHeader: ['application/json; charset=utf-8'],
    });
  }

  Gestionnaire? _parPrefixe(String cle) {
    for (final e in _routes.entries) {
      if (e.key.endsWith('*') && cle.startsWith(e.key.substring(0, e.key.length - 1))) return e.value;
    }
    return null;
  }

  @override
  void close({bool force = false}) {}
}
