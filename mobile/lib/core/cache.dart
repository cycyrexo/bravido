import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// Cache local JSON (hors ligne) : configuration, numéros d'urgence,
/// complices, gardes en cours de la ville.
class Cache {
  static late SharedPreferences prefs;

  static Future<void> init() async {
    prefs = await SharedPreferences.getInstance();
  }

  static Future<void> ecrire(String cle, Object? valeur) async {
    await prefs.setString(cle, jsonEncode(valeur));
    await prefs.setString('$cle@date', DateTime.now().toIso8601String());
  }

  static dynamic lire(String cle) {
    final brut = prefs.getString(cle);
    if (brut == null) return null;
    try {
      return jsonDecode(brut);
    } catch (_) {
      return null;
    }
  }

  static Map<String, dynamic>? lireMap(String cle) {
    final v = lire(cle);
    return v is Map<String, dynamic> ? v : null;
  }

  static List<dynamic>? lireListe(String cle) {
    final v = lire(cle);
    return v is List ? v : null;
  }

  static DateTime? dateDe(String cle) {
    final d = prefs.getString('$cle@date');
    return d == null ? null : DateTime.tryParse(d);
  }

  static Future<void> supprimer(String cle) async {
    await prefs.remove(cle);
    await prefs.remove('$cle@date');
  }
}
