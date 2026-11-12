import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

import 'api.dart';

/// URG-03 : partage de la position pendant l'alerte, y compris application en arrière-plan
/// ou écran verrouillé.
/// - Android : service de premier plan (notification permanente « Alerte en cours »).
/// - iOS : mise à jour de position en arrière-plan (UIBackgroundModes = location).
/// Le suivi s'arrête à la clôture, à l'annulation, ou quand le serveur répond que l'alerte est terminée (1 h).
class SuiviAlerte {
  static StreamSubscription<Position>? _abonnement;
  static int? _alerteId;
  static DateTime? _dernierEssai;

  /// Heure du dernier envoi réussi (pour l'affichage).
  static final ValueNotifier<DateTime?> dernierEnvoi = ValueNotifier(null);

  static bool get actif => _abonnement != null;

  static Future<void> demarrer(int alerteId, {int intervalleS = 30}) async {
    if (_alerteId == alerteId && _abonnement != null) return;
    await arreter();
    _alerteId = alerteId;

    final LocationSettings reglages = Platform.isAndroid
        ? AndroidSettings(
            accuracy: LocationAccuracy.high,
            distanceFilter: 0,
            intervalDuration: Duration(seconds: intervalleS),
            foregroundNotificationConfig: const ForegroundNotificationConfig(
              notificationTitle: 'Alerte BRAVIDO en cours',
              notificationText: 'Votre position est partagée avec vos complices.',
              enableWakeLock: true,
              setOngoing: true,
            ),
          )
        : AppleSettings(
            accuracy: LocationAccuracy.high,
            distanceFilter: 0,
            activityType: ActivityType.other,
            pauseLocationUpdatesAutomatically: false,
            showBackgroundLocationIndicator: true,
            allowBackgroundLocationUpdates: true,
          );

    final intervalleMin = Duration(seconds: (intervalleS * 0.8).round());
    _abonnement = Geolocator.getPositionStream(locationSettings: reglages).listen(
      (p) => _envoyer(p, intervalleMin),
      onError: (Object e) => debugPrint('Suivi alerte : $e'),
    );
  }

  static Future<void> _envoyer(Position p, Duration intervalleMin) async {
    final id = _alerteId;
    if (id == null) return;
    final maintenant = DateTime.now();
    if (_dernierEssai != null && maintenant.difference(_dernierEssai!) < intervalleMin) return;
    _dernierEssai = maintenant;

    try {
      await Api.instance.post('/alertes/$id/positions', {
        'lat': p.latitude,
        'lng': p.longitude,
        'precision_m': p.accuracy.round(),
      });
      dernierEnvoi.value = maintenant;
    } on ApiException catch (e) {
      // 409 : alerte terminée côté serveur (clôture ou 1 h écoulée) ; 404 : alerte inconnue
      if (e.status == 409 || e.status == 404) await arreter();
      // Hors ligne : on réessaiera à la prochaine position
    }
  }

  static Future<void> arreter() async {
    await _abonnement?.cancel();
    _abonnement = null;
    _alerteId = null;
    _dernierEssai = null;
  }
}
