import 'package:bravido/core/session.dart';
import 'package:bravido/screens/avis_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/api_factice.dart';

void main() {
  late ApiFactice api;

  setUp(() async {
    api = ApiFactice.installer();
    await ApiFactice.preparerCache();
  });

  void telephone(WidgetTester t) {
    t.view.physicalSize = const Size(1080, 2400);
    t.view.devicePixelRatio = 2.75;
    addTearDown(t.view.reset);
  }

  testWidgets('bloc d\'avis : moyenne, avis publiés et avis en cours de vérification', (tester) async {
    telephone(tester);
    api.repondre('GET /etablissements/5/avis', {
      'data': [
        {'id': 1, 'note': 4, 'commentaire': 'Accueil rapide', 'auteur': 'Awa T.', 'date': '2026-11-10', 'reponse': {'texte': 'Merci !', 'date': '2026-11-11'}},
      ],
      'meta': {
        'actif': true,
        'moyenne': 4.0,
        'total': 1,
        'repartition': {'5': 0, '4': 1, '3': 0, '2': 0, '1': 0},
        'mon_avis': {'id': 9, 'note': 3, 'commentaire': 'ok', 'statut': 'en_attente'},
      },
    });
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: SingleChildScrollView(child: BlocAvis(etablissementId: 5)))));
    await tester.pump(const Duration(milliseconds: 200));
    expect(find.text('Avis'), findsOneWidget);
    expect(find.text('Awa T.'), findsOneWidget);
    expect(find.text('Accueil rapide'), findsOneWidget);
    expect(find.text('Merci !'), findsOneWidget);
    expect(find.textContaining('en cours de vérification'), findsOneWidget);
    expect(find.text('Modifier mon avis'), findsOneWidget);
  });

  testWidgets('bloc d\'avis : masqué pour une catégorie d\'urgence', (tester) async {
    telephone(tester);
    api.repondre('GET /etablissements/7/avis', {
      'data': <dynamic>[],
      'meta': {'actif': false, 'moyenne': null, 'total': 0, 'mon_avis': null},
    });
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: BlocAvis(etablissementId: 7))));
    await tester.pump(const Duration(milliseconds: 200));
    expect(find.text('Donner mon avis'), findsNothing);
  });

  testWidgets('formulaire : note obligatoire, message de prudence médicale, envoi', (tester) async {
    telephone(tester);
    Session.instance.token = 'jeton';
    addTearDown(() => Session.instance.token = null);
    api.repondre('POST /etablissements/5/mon-avis', {'data': {'id': 1}}, statut: 201);

    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: FormulaireAvis(etablissementId: 5))));
    expect(find.textContaining('Ne mentionnez ni diagnostic ni information médicale'), findsOneWidget);

    await tester.tap(find.text('Envoyer'));
    await tester.pump();
    expect(find.text('Choisissez une note de 1 à 5.'), findsOneWidget);
    expect(api.appele('POST /etablissements/5/mon-avis'), isFalse);

    await tester.tap(find.bySemanticsLabel('4 étoiles').first, warnIfMissed: false);
    // Sélection directe via l'icône (la sémantique n'est pas activée dans ce test)
    await tester.tap(find.byIcon(Icons.star_outline_rounded).at(3));
    await tester.enterText(find.byType(TextField), 'Très bien');
    await tester.tap(find.text('Envoyer'));
    await tester.pump(const Duration(milliseconds: 200));
    expect(api.appele('POST /etablissements/5/mon-avis'), isTrue);
  });
}
