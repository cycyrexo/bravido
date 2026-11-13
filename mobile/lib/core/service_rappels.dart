import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:timezone/data/latest_10y.dart' as tzdata;
import 'package:timezone/timezone.dart' as tz;

import 'cache.dart';
import 'langue.dart';
import 'rappels.dart';

/// F14 — Rappels locaux : stockage (SharedPreferences, jamais envoyé au serveur) et programmation
/// des notifications (canal « rappels », fuseau Africa/Abidjan). Les notifications programmées sont
/// reprogrammées par le système après un redémarrage (ScheduledNotificationBootReceiver).
class ServiceRappels extends ChangeNotifier {
  ServiceRappels._();
  static final ServiceRappels instance = ServiceRappels._();

  static const _cle = 'rappels_locaux';
  static const _cleProchainId = 'rappels_prochain_id';
  static const canalId = 'rappels';

  final _plugin = FlutterLocalNotificationsPlugin();
  bool _pret = false;
  tz.Location? _zone;

  List<Rappel> rappels = [];

  /// Heure exacte autorisée (Android 12+ : « Alarmes et rappels »). Sinon : rappels approximatifs (quelques minutes).
  bool alarmesExactes = true;
  bool notificationsAutorisees = true;

  AndroidFlutterLocalNotificationsPlugin? get _android => _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();

  Future<void> init() async {
    if (_pret) return;
    try {
      tzdata.initializeTimeZones();
      _zone = tz.getLocation('Africa/Abidjan');
      await _plugin.initialize(settings: const InitializationSettings(android: AndroidInitializationSettings('@mipmap/ic_launcher')));
      await _android?.createNotificationChannel(const AndroidNotificationChannel(
        canalId,
        'Rappels',
        description: 'Rappels de médicaments et de rendez-vous',
        importance: Importance.high,
      ));
      _pret = true;
    } catch (e) {
      debugPrint('Rappels : initialisation impossible ($e)');
    }
    _charger();
    await reprogrammer();
  }

  void _charger() {
    final brut = Cache.lire(_cle);
    rappels = brut is List ? brut.map(Rappel.depuisJson).whereType<Rappel>().toList() : [];
  }

  Future<void> _sauver() => Cache.ecrire(_cle, rappels.map((r) => r.toJson()).toList());

  int nouvelId() {
    final n = (Cache.prefs.getInt(_cleProchainId) ?? 1);
    Cache.prefs.setInt(_cleProchainId, n + 1);
    return n;
  }

  /// Demande l'autorisation d'afficher des notifications (Android 13+) ; renvoie true si accordée.
  Future<bool> demanderAutorisation() async {
    try {
      final android = _android;
      if (android == null) return true;
      final ok = await android.areNotificationsEnabled() ?? true;
      if (ok) {
        notificationsAutorisees = true;
        return true;
      }
      notificationsAutorisees = await android.requestNotificationsPermission() ?? false;
      notifyListeners();
      return notificationsAutorisees;
    } catch (_) {
      return true;
    }
  }

  /// Ouvre l'écran système « Alarmes et rappels » pour obtenir des horaires exacts.
  Future<void> demanderAlarmesExactes() async {
    try {
      await _android?.requestExactAlarmsPermission();
    } catch (_) {}
    await actualiserEtat();
  }

  Future<void> actualiserEtat() async {
    try {
      alarmesExactes = await _android?.canScheduleExactNotifications() ?? true;
      notificationsAutorisees = await _android?.areNotificationsEnabled() ?? true;
    } catch (_) {}
    notifyListeners();
  }

  Future<void> enregistrer(Rappel r) async {
    final i = rappels.indexWhere((x) => x.id == r.id);
    if (i >= 0) {
      rappels[i] = r;
    } else {
      rappels.add(r);
    }
    await _sauver();
    await _programmer(r);
    notifyListeners();
  }

  Future<void> supprimer(Rappel r) async {
    rappels.removeWhere((x) => x.id == r.id);
    await _sauver();
    await _annuler(r);
    notifyListeners();
  }

  Future<void> basculer(Rappel r, bool actif) => enregistrer(r.copieAvec(actif: actif));

  static int idNotification(int idRappel, int indexHeure) => idRappel * 10 + indexHeure;

  Future<void> _annuler(Rappel r) async {
    if (!_pret) return;
    for (var i = 0; i < 10; i++) {
      try {
        await _plugin.cancel(id: idNotification(r.id, i));
      } catch (_) {}
    }
  }

  /// Reprogramme tout (au démarrage de l'application, après modification de l'état des alarmes).
  Future<void> reprogrammer() async {
    if (!_pret) return;
    await actualiserEtat();
    for (final r in rappels) {
      await _programmer(r);
    }
  }

  Future<void> _programmer(Rappel r) async {
    if (!_pret || _zone == null) return;
    await _annuler(r);
    if (!r.actif) return;

    final mode = alarmesExactes ? AndroidScheduleMode.exactAllowWhileIdle : AndroidScheduleMode.inexactAllowWhileIdle;
    final maintenant = DateTime.now().toUtc();
    final heures = [...r.heures]..sort();
    for (var i = 0; i < heures.length && i < 10; i++) {
      final unique = r.copieAvec(heures: [heures[i]]);
      final prochaine = prochainesEcheances(unique, maintenant).firstOrNull;
      if (prochaine == null) continue;
      final quand = tz.TZDateTime(_zone!, prochaine.year, prochaine.month, prochaine.day, prochaine.hour, prochaine.minute);
      try {
        await _plugin.zonedSchedule(
          id: idNotification(r.id, i),
          title: r.titre,
          body: _texte(r),
          scheduledDate: quand,
          notificationDetails: NotificationDetails(
            android: AndroidNotificationDetails(
              canalId,
              'Rappels',
              channelDescription: 'Rappels de médicaments et de rendez-vous',
              importance: Importance.high,
              priority: Priority.high,
              icon: '@mipmap/ic_launcher',
              styleInformation: BigTextStyleInformation(_texte(r)),
            ),
          ),
          androidScheduleMode: mode,
          matchDateTimeComponents: switch (r.repetition) {
            Repetition.aucune => null,
            Repetition.quotidien => DateTimeComponents.time,
            Repetition.hebdo => DateTimeComponents.dayOfWeekAndTime,
          },
        );
      } catch (e) {
        debugPrint('Rappel ${r.id} : programmation impossible ($e)');
      }
    }
  }

  static String _texte(Rappel r) {
    if (r.note.trim().isNotEmpty) return r.note.trim();
    if (r.type == TypeRappel.medicament) return tr('Il est l\'heure de votre traitement.', 'Time for your medication.');
    return switch (r.avantMinutes) {
      0 => tr('Votre rendez-vous est maintenant.', 'Your appointment is now.'),
      60 => tr('Rendez-vous dans 1 heure.', 'Appointment in 1 hour.'),
      1440 => tr('Rendez-vous demain.', 'Appointment tomorrow.'),
      final m => tr('Rendez-vous dans $m minutes.', 'Appointment in $m minutes.'),
    };
  }
}
