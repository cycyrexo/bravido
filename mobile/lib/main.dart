import 'package:flutter/material.dart';

import 'app.dart';
import 'core/cache.dart';
import 'core/push.dart';
import 'core/session.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Cache.init();
  await Session.instance.init();
  await Push.init(); // sans effet si Firebase n'est pas configuré
  runApp(const BravidoApp());
  Push.enregistrerToken();
}
