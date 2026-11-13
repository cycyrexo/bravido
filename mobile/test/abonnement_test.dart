import 'package:bravido/screens/compte/abonnement_screen.dart';
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
    t.view.physicalSize = const Size(1080, 2600);
    t.view.devicePixelRatio = 2.75;
    addTearDown(t.view.reset);
  }

  const plan = {'code': 'famille_mensuel', 'nom': 'Famille mensuel', 'prix_xof': 1000, 'duree_jours': 30, 'profils_max': 4, 'a_confirmer': true};
  const fournisseurs = [
    {'code': 'sandbox', 'libelle': 'Paiement simulé (test)', 'disponible': true},
    {'code': 'wave', 'libelle': 'Wave', 'disponible': false},
  ];

  test('prix lisible', () {
    expect(prixLisible(1000), '1 000 FCFA');
    expect(prixLisible(250000), '250 000 FCFA');
    expect(prixLisible(500), '500 FCFA');
  });

  testWidgets('sans abonnement : offre, mention des alertes gratuites, prix à confirmer', (tester) async {
    telephone(tester);
    api.repondre('GET /abonnement', {'data': {'actif': false, 'fournisseurs': fournisseurs, 'paiements': <dynamic>[]}});
    api.repondre('GET /plans', {'data': [plan]});
    api.repondre('GET /abonnement/invitations', {'data': <dynamic>[]});
    await tester.pumpWidget(const MaterialApp(home: AbonnementScreen()));
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.textContaining('restent gratuites et identiques pour tous'), findsOneWidget);
    expect(find.text('Famille mensuel'), findsOneWidget);
    expect(find.text('Prix à confirmer'), findsOneWidget);
    expect(find.textContaining('1 000 FCFA'), findsOneWidget);
    expect(find.text('Souscrire'), findsOneWidget);
  });

  testWidgets('abonné : places, membres et limite de profils', (tester) async {
    telephone(tester);
    api.repondre('GET /abonnement', {
      'data': {
        'actif': true,
        'fournisseurs': fournisseurs,
        'abonnement': {'id': 1, 'plan': plan, 'fin': '2026-12-12', 'role': 'proprietaire', 'places_utilisees': 4, 'places_max': 4},
        'membres': [
          {'id': 1, 'nom': 'Awa', 'telephone': '+2250700000001', 'statut': 'accepte'},
          {'id': 2, 'nom': 'Koffi', 'telephone': '+2250700000002', 'statut': 'invite'},
        ],
        'paiements': <dynamic>[],
      }
    });
    api.repondre('GET /plans', {'data': [plan]});
    api.repondre('GET /abonnement/invitations', {'data': <dynamic>[]});
    await tester.pumpWidget(const MaterialApp(home: AbonnementScreen()));
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('Actif'), findsOneWidget);
    expect(find.text('Awa'), findsOneWidget);
    expect(find.text('Invitation en attente'), findsOneWidget);
    expect(find.text('Limite de profils atteinte'), findsOneWidget);
    expect(find.textContaining('Valable jusqu\'au 2026-12-12'), findsOneWidget);
  });
}
