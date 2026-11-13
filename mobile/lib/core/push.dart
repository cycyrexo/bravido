import 'dart:io';

import 'package:dio/dio.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../app.dart';
import '../config.dart';
import '../screens/alerte/alerte_recue_screen.dart';
import '../screens/alerte/trajet_screen.dart';
import 'api.dart';
import 'session.dart';

/// Isolat séparé (application en arrière-plan ou fermée) :
/// accuse réception de l'alerte pour éviter l'envoi du SMS de secours.
@pragma('vm:entry-point')
Future<void> _messageArrierePlan(RemoteMessage message) async {
  if (message.data['type'] != 'alerte') return;
  try {
    await Firebase.initializeApp();
    final token = await const FlutterSecureStorage().read(key: 'token');
    if (token == null) return;
    await Dio(BaseOptions(
      baseUrl: Config.apiUrl,
      headers: {'Accept': 'application/json', 'Authorization': 'Bearer $token'},
    )).post('/alertes/${message.data['alerte_id']}/accuse');
  } catch (_) {}
}

/// Notifications push (Firebase Cloud Messaging). L'application fonctionne sans Firebase :
/// les alertes partent alors par SMS.
class Push {
  static bool disponible = false;

  static Future<void> init() async {
    try {
      await Firebase.initializeApp();
      disponible = true;
    } catch (e) {
      debugPrint('Firebase non configuré (google-services.json / GoogleService-Info.plist) : $e');
      return;
    }

    FirebaseMessaging.onBackgroundMessage(_messageArrierePlan);
    await FirebaseMessaging.instance.requestPermission(alert: true, sound: true, badge: true);

    FirebaseMessaging.onMessage.listen(_premierPlan);
    FirebaseMessaging.onMessageOpenedApp.listen(_ouvrir);
    FirebaseMessaging.instance.onTokenRefresh.listen((_) => enregistrerToken());

    final initial = await FirebaseMessaging.instance.getInitialMessage();
    if (initial != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _ouvrir(initial));
    }
  }

  static Future<void> enregistrerToken() async {
    if (!disponible || !Session.instance.connecte) return;
    try {
      final token = await FirebaseMessaging.instance.getToken();
      if (token != null) {
        await Api.instance.post('/me/device-token', {
          'token': token,
          'platform': Platform.isIOS ? 'ios' : 'android',
        });
      }
    } catch (_) {}
  }

  static Future<void> _premierPlan(RemoteMessage message) async {
    final data = message.data;
    if (data['type'] == 'alerte') {
      _accuser(data['alerte_id']);
      _ouvrir(message);
      return;
    }
    final contexte = navigatorKey.currentContext;
    final titre = message.notification?.title;
    final corps = message.notification?.body;
    if (contexte != null && (titre != null || corps != null)) {
      ScaffoldMessenger.of(contexte).showSnackBar(SnackBar(content: Text([titre, corps].whereType<String>().join(' — '))));
    }
  }

  static void _ouvrir(RemoteMessage message) {
    final data = message.data;
    if (data['type'] == 'alerte' || data['type'] == 'alerte_audio') {
      final id = int.tryParse('${data['alerte_id']}');
      if (id != null) {
        _accuser(id);
        navigatorKey.currentState?.push(MaterialPageRoute(builder: (_) => AlerteRecueScreen(alerteId: id)));
      }
    } else if (data['type'] == 'trajet_rappel') {
      navigatorKey.currentState?.push(MaterialPageRoute(builder: (_) => const TrajetScreen()));
    }
  }

  static Future<void> _accuser(dynamic alerteId) async {
    try {
      await Api.instance.post('/alertes/$alerteId/accuse');
    } catch (_) {}
  }
}
