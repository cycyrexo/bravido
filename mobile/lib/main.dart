import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import 'app.dart';
import 'core/cache.dart';
import 'core/hors_ligne.dart';
import 'core/langue.dart';
import 'core/push.dart';
import 'core/rapports.dart';
import 'core/service_rappels.dart';
import 'core/session.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Cache.init();
  HorsLigne.instance.charger(); // lit les villes téléchargées (F20), sans bloquer le démarrage
  Langue.charger(ui.PlatformDispatcher.instance.locale.languageCode);
  await Session.instance.init();
  await Push.init(); // sans effet si Firebase n'est pas configuré
  try {
    await RapportsErreurs.instance.init(firebaseDisponible: Push.disponible); // F21 : collecte coupée par défaut
  } catch (_) {}
  await ServiceRappels.instance.init(); // rappels locaux (F14)
  runApp(const BravidoApp());
  Push.enregistrerToken();
}
