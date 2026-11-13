import 'package:bravido/core/cache.dart';
import 'package:bravido/core/reglages.dart';
import 'package:bravido/core/sos_silencieux.dart';
import 'package:bravido/screens/alerte/faux_appel_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await Cache.init();
  });

  test('réglages par défaut : appelant « Maman », mode discret désactivé', () async {
    expect(Reglages.instance.appelant, 'Maman');
    expect(Reglages.instance.modeDiscret, isFalse);
    await Reglages.instance.definirModeDiscret(true);
    await Reglages.instance.definirAppelant('  Tante Awa ');
    expect(Reglages.instance.modeDiscret, isTrue);
    expect(Reglages.instance.appelant, 'Tante Awa');
    await Reglages.instance.definirAppelant('   ');
    expect(Reglages.instance.appelant, 'Maman');
  });

  test('SOS silencieux : sans compte connecté, rien n\'est envoyé et rien ne plante', () async {
    expect(await SosSilencieux.envoyer(), isNull);
  });

  testWidgets('faux appel : préparation, appel entrant, réponse, chronomètre, raccrocher', (tester) async {
    await Reglages.instance.definirSonnerie(false);
    await Reglages.instance.definirVibration(false);
    await tester.pumpWidget(const MaterialApp(home: FauxAppelScreen()));

    expect(find.text('Faux appel'), findsOneWidget);
    expect(find.textContaining('Mode discret'), findsOneWidget);
    await tester.enterText(find.byType(TextField), 'Papa');
    await tester.scrollUntilVisible(find.text('Lancer le faux appel'), 200, scrollable: find.byType(Scrollable).first);
    await tester.tap(find.text('Lancer le faux appel'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('Appel entrant'), findsOneWidget);
    expect(find.text('Papa'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.call_rounded));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(seconds: 3));
    expect(find.textContaining('00:0'), findsOneWidget);

    // Appui long de 2 s sur le chronomètre : aucun retour visible (pas de snackbar, pas de dialogue)
    final geste = await tester.startGesture(tester.getCenter(find.textContaining('00:0')));
    await tester.pump(const Duration(seconds: 2, milliseconds: 100));
    await geste.up();
    expect(find.byType(SnackBar), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);

    await tester.tap(find.byIcon(Icons.call_end_rounded));
    await tester.pumpAndSettle();
    expect(find.text('Faux appel'), findsOneWidget);
  });
}
