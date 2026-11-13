import 'package:flutter/foundation.dart';

import 'cache.dart';

/// Réglages locaux de sécurité (stockés sur l'appareil, jamais envoyés au serveur).
class Reglages extends ChangeNotifier {
  Reglages._();
  static final Reglages instance = Reglages._();

  /// Mode discret : le compte à rebours du SOS devient silencieux (3 s, sans vibration ni son,
  /// écran neutre et assombri). L'application reste visible : ce n'est pas un mode « invisible ».
  bool get modeDiscret => Cache.prefs.getBool('mode_discret') ?? false;

  /// Nom affiché sur l'écran de faux appel.
  String get appelant {
    final nom = Cache.prefs.getString('faux_appel_nom')?.trim() ?? '';
    return nom.isEmpty ? 'Maman' : nom;
  }

  bool get sonnerie => Cache.prefs.getBool('faux_appel_sonnerie') ?? true;
  bool get vibration => Cache.prefs.getBool('faux_appel_vibration') ?? true;

  Future<void> definirModeDiscret(bool v) async {
    await Cache.prefs.setBool('mode_discret', v);
    notifyListeners();
  }

  Future<void> definirAppelant(String nom) async {
    await Cache.prefs.setString('faux_appel_nom', nom.trim());
    notifyListeners();
  }

  Future<void> definirSonnerie(bool v) async {
    await Cache.prefs.setBool('faux_appel_sonnerie', v);
    notifyListeners();
  }

  Future<void> definirVibration(bool v) async {
    await Cache.prefs.setBool('faux_appel_vibration', v);
    notifyListeners();
  }
}
