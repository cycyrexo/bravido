import 'package:flutter/material.dart';

import 'cache.dart';

/// F15 — Apparence : thème (système / clair / sombre) et taille du texte, mémorisés sur l'appareil.
class Apparence extends ChangeNotifier {
  Apparence._();
  static final Apparence instance = Apparence._();

  static const taillesTexte = [0.9, 1.0, 1.15, 1.3];

  ThemeMode get theme {
    final v = _lire(() => Cache.prefs.getString('theme_mode'));
    return switch (v) {
      'light' => ThemeMode.light,
      'dark' => ThemeMode.dark,
      _ => ThemeMode.system,
    };
  }

  double get tailleTexte {
    final v = _lire(() => Cache.prefs.getDouble('taille_texte'));
    return taillesTexte.contains(v) ? v! : 1.0;
  }

  T? _lire<T>(T? Function() f) {
    try {
      return f();
    } catch (_) {
      return null; // préférences non initialisées (tests)
    }
  }

  Future<void> definirTheme(ThemeMode m) async {
    await Cache.prefs.setString('theme_mode', m.name);
    notifyListeners();
  }

  Future<void> definirTailleTexte(double t) async {
    await Cache.prefs.setDouble('taille_texte', t);
    notifyListeners();
  }
}
