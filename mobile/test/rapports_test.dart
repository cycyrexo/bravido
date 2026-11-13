import 'package:bravido/core/rapports.dart';
import 'package:bravido/screens/rapports_section.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/api_factice.dart';

void main() {
  setUp(() async {
    await ApiFactice.preparerCache();
    RapportsErreurs.fournisseur = () => null; // pas de plugin natif dans les tests
  });

  test('désactivé par défaut, choix mémorisé, aucune exception sans Crashlytics', () async {
    expect(RapportsErreurs.instance.actif, isFalse);
    await RapportsErreurs.instance.definir(true);
    expect(RapportsErreurs.instance.actif, isTrue);
    // Rechargement à froid des préférences
    await ApiFactice.preparerCache({'rapports_erreurs': true});
    expect(RapportsErreurs.instance.actif, isTrue);
    await RapportsErreurs.instance.definir(false);
    expect(RapportsErreurs.instance.actif, isFalse);
    // Une erreur signalée sans Crashlytics ne lève rien
    RapportsErreurs.instance.signalerPourTest(StateError('x'), StackTrace.current);
  });

  testWidgets('interrupteur des réglages : texte explicatif et bascule', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.75;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: CarteRapportsErreurs())));
    expect(find.text('Envoyer les rapports d\'erreurs anonymes'), findsOneWidget);
    expect(find.textContaining('ne contient ni votre nom'), findsOneWidget);
    expect(tester.widget<Switch>(find.byType(Switch)).value, isFalse);
    await tester.tap(find.byType(Switch));
    await tester.pump(const Duration(milliseconds: 100));
    expect(tester.widget<Switch>(find.byType(Switch)).value, isTrue);
    expect(RapportsErreurs.instance.actif, isTrue);
  });
}
