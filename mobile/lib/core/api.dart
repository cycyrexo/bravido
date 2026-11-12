import 'package:dio/dio.dart';

import '../config.dart';
import 'session.dart';

class ApiException implements Exception {
  ApiException(this.message, {this.status, this.horsLigne = false});

  final String message;
  final int? status;
  final bool horsLigne;

  @override
  String toString() => message;
}

/// Client REST de l'API BRAVIDO (jeton Sanctum).
class Api {
  Api._() {
    _dio = Dio(BaseOptions(
      baseUrl: Config.apiUrl,
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 20),
      headers: {'Accept': 'application/json'},
    ));
    _dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
      final token = Session.instance.token;
      if (token != null) options.headers['Authorization'] = 'Bearer $token';
      handler.next(options);
    }));
  }

  static final Api instance = Api._();
  late final Dio _dio;

  Future<dynamic> get(String chemin, {Map<String, dynamic>? query}) =>
      _executer(() => _dio.get(chemin, queryParameters: _nettoyer(query)));

  Future<dynamic> post(String chemin, [Object? data]) => _executer(() => _dio.post(chemin, data: data));

  Future<dynamic> put(String chemin, [Object? data]) => _executer(() => _dio.put(chemin, data: data));

  Future<dynamic> patch(String chemin, [Object? data]) => _executer(() => _dio.patch(chemin, data: data));

  Future<dynamic> delete(String chemin) => _executer(() => _dio.delete(chemin));

  Map<String, dynamic>? _nettoyer(Map<String, dynamic>? q) {
    if (q == null) return null;
    return Map.of(q)..removeWhere((_, v) => v == null || v == '');
  }

  Future<dynamic> _executer(Future<Response<dynamic>> Function() requete) async {
    try {
      final reponse = await requete();
      return reponse.data;
    } on DioException catch (e) {
      final r = e.response;
      if (r == null) {
        throw ApiException('Pas de connexion. Vérifiez votre réseau.', horsLigne: true);
      }
      if (r.statusCode == 401) {
        await Session.instance.deconnecterLocalement();
        throw ApiException('Session expirée. Reconnectez-vous.', status: 401);
      }
      if (r.statusCode == 429) {
        throw ApiException('Trop de tentatives. Patientez un instant.', status: 429);
      }

      var message = 'Une erreur est survenue (${r.statusCode}).';
      final data = r.data;
      if (data is Map) {
        final erreurs = data['errors'];
        if (erreurs is Map && erreurs.isNotEmpty) {
          final premiere = erreurs.values.first;
          message = premiere is List && premiere.isNotEmpty ? '${premiere.first}' : '$premiere';
        } else if (data['message'] is String && (data['message'] as String).isNotEmpty) {
          message = data['message'] as String;
        }
      }
      throw ApiException(message, status: r.statusCode);
    }
  }
}
