import 'dart:ui' as ui;

import 'package:firebase_crashlytics/firebase_crashlytics.dart';
import 'package:flutter/foundation.dart';

import 'cache.dart';

/// F21 : rapports d'erreurs anonymes (Firebase Crashlytics).
/// - Désactivés par défaut ; l'utilisateur les active dans Réglages (choix mémorisé sur l'appareil).
/// - Aucune donnée personnelle : jamais d'identifiant utilisateur, de téléphone ni de contenu d'alerte.
/// - Tout est protégé par try/catch : l'application ne doit JAMAIS échouer parce que Crashlytics est absent
///   ou non activé dans la console Firebase.
class RapportsErreurs extends ChangeNotifier {
  RapportsErreurs._();
  static final RapportsErreurs instance = RapportsErreurs._();

  static const _cle = 'rapports_erreurs';

  /// Remplaçable dans les tests (aucun plugin natif).
  @visibleForTesting
  static FirebaseCrashlytics? Function() fournisseur = _parDefaut;

  static FirebaseCrashlytics? _parDefaut() {
    try {
      return FirebaseCrashlytics.instance;
    } catch (_) {
      return null;
    }
  }

  bool _branche = false;

  bool get actif {
    try {
      return Cache.prefs.getBool(_cle) ?? false;
    } catch (_) {
      return false; // préférences non initialisées
    }
  }

  /// À appeler au démarrage, après `Firebase.initializeApp()` (sans effet si Firebase est absent).
  Future<void> init({required bool firebaseDisponible}) async {
    if (_branche) return;
    _branche = true;
    final ancienFlutter = FlutterError.onError;
    FlutterError.onError = (details) {
      if (ancienFlutter != null) {
        ancienFlutter(details);
      } else {
        FlutterError.presentError(details);
      }
      _envoyerFlutter(details);
    };
    final ancienPlateforme = ui.PlatformDispatcher.instance.onError;
    ui.PlatformDispatcher.instance.onError = (erreur, pile) {
      _envoyer(erreur, pile);
      return ancienPlateforme?.call(erreur, pile) ?? false;
    };
    if (firebaseDisponible) await _appliquer();
  }

  Future<void> definir(bool valeur) async {
    await Cache.prefs.setBool(_cle, valeur);
    await _appliquer();
    notifyListeners();
  }

  /// Active ou coupe réellement la collecte (la collecte coupée n'envoie rien, même en cas de plantage).
  Future<void> _appliquer() async {
    try {
      await fournisseur()?.setCrashlyticsCollectionEnabled(actif);
    } catch (e) {
      debugPrint('Crashlytics indisponible : $e');
    }
  }

  void _envoyerFlutter(FlutterErrorDetails d) {
    if (!actif) return;
    try {
      fournisseur()?.recordFlutterFatalError(d);
    } catch (_) {}
  }

  void _envoyer(Object erreur, StackTrace pile) {
    if (!actif) return;
    try {
      fournisseur()?.recordError(erreur, pile, fatal: true);
    } catch (_) {}
  }

  /// Hors tests : permet de vérifier que rien n'est transmis quand la collecte est coupée.
  @visibleForTesting
  void signalerPourTest(Object erreur, StackTrace pile) => _envoyer(erreur, pile);
}
