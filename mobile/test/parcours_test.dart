import 'package:bravido/app.dart';
import 'package:bravido/core/apparence.dart';
import 'package:bravido/core/cache.dart';
import 'package:bravido/core/langue.dart';
import 'package:bravido/core/outils.dart';
import 'package:geolocator/geolocator.dart';
import 'package:bravido/core/reglages.dart';
import 'package:bravido/core/session.dart';
import 'package:bravido/screens/alerte/sos_screen.dart';
import 'package:bravido/screens/etablissement_detail_screen.dart';
import 'package:bravido/screens/home_screen.dart';
import 'package:bravido/screens/recherche_screen.dart';
import 'package:bravido/screens/reglages_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/api_factice.dart';

Widget _appli(Widget ecran) => MaterialApp(home: ecran);

/// La police de test (Ahem) est plus large que la vraie : on ignore les débordements d'affichage purement visuels.
void _ignorerDebordements() {
  final ancien = FlutterError.onError;
  FlutterError.onError = (d) {
    if (d.exceptionAsString().contains('overflowed')) return;
    ancien?.call(d);
  };
  addTearDown(() => FlutterError.onError = ancien);
}

/// Position connue : évite tout appel au plugin de localisation (absent des tests).
void _positionConnue() {
  Localisation.derniere = Position(
    latitude: 5.33, longitude: -4.02, timestamp: DateTime(2026, 11, 12), accuracy: 10, altitude: 0, altitudeAccuracy: 0, heading: 0, headingAccuracy: 0, speed: 0, speedAccuracy: 0);
}

void _telephone(WidgetTester t) {
  _positionConnue();
  _ignorerDebordements();
  t.view.physicalSize = const Size(1080, 2200);
  t.view.devicePixelRatio = 2.75;
  addTearDown(t.view.reset);
}

const _pharmacie = {
  'id': 5,
  'nom': 'Pharmacie du Plateau',
  'commune': 'Plateau',
  'categorie': {'code': 'pharmacie', 'libelle': 'Pharmacie'},
  'telephone': '+2250701020304',
  'lat': 5.32,
  'lng': -4.02,
  'position_approx': true,
  'services': <String>[],
  'assurances': <String>[],
  'horaires': '8h-20h',
};

void main() {
  late ApiFactice api;

  setUp(() async {
    api = ApiFactice.installer();
    await ApiFactice.preparerCache();
    Langue.code = 'fr';
    Couleurs.sombre = false;
  });

  tearDown(() {
    Langue.code = 'fr';
    Couleurs.sombre = false;
  });

  testWidgets('accueil : s\'affiche avec la barre de recherche et sans réseau', (tester) async {
    _telephone(tester);
    api.horsLigne = true;
    await tester.pumpWidget(_appli(const HomeScreen()));
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.byType(BarreRecherche), findsOneWidget);
    expect(find.textContaining('Rechercher'), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  testWidgets('recherche : saisie, suggestions puis résultats', (tester) async {
    _telephone(tester);
    api.repondre('GET /recherche/suggestions*', {
      'data': [
        {'type': 'terme', 'libelle': 'dentiste'},
      ]
    });
    api.repondre('GET /recherche*', {
      'data': [
        {..._pharmacie, 'nom': 'Cabinet Dentaire Cocody', 'categorie': {'code': 'clinique', 'libelle': 'Clinique'}},
      ],
      'meta': {'total': 1, 'groupes': <dynamic>[]},
    });

    await tester.pumpWidget(_appli(const RechercheScreen()));
    await tester.enterText(find.byType(TextField), 'dentiste');
    await tester.pump(const Duration(milliseconds: 400));
    expect(api.appele('GET /recherche/suggestions'), isTrue);

    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pump(const Duration(milliseconds: 100));
    await tester.pump(const Duration(seconds: 4)); // délai de localisation éventuel
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('Cabinet Dentaire Cocody'), findsOneWidget);
    // La requête est mémorisée dans les recherches récentes
    expect(Cache.lire('recherches_recentes'), ['dentiste']);
  });

  testWidgets('favoris : ajout puis retrait (API + cache local)', (tester) async {
    _telephone(tester);
    api.repondre('GET /etablissements/5', {'data': _pharmacie});
    api.repondre('POST /favoris', {'ok': true}, statut: 201);
    api.repondre('DELETE /favoris/5', {'ok': true});
    api.repondre('GET /mon-etablissement', {'data': <dynamic>[]});
    Session.instance.token = 'jeton-test';
    addTearDown(() => Session.instance.token = null);
    api.repondre('POST /etablissements/5/evenements', {'ok': true});

    await tester.pumpWidget(_appli(const Scaffold(body: FicheEtablissement(apercu: _pharmacie))));
    await tester.pump(const Duration(milliseconds: 200));
    expect(find.text('Pharmacie du Plateau'), findsWidgets);

    await tester.tap(find.byTooltip('Ajouter aux favoris'));
    await tester.pump(const Duration(milliseconds: 200));
    expect(api.appele('POST /favoris'), isTrue);
    expect(Cache.lireListe('favoris_ids'), [5]);

    await tester.pump(const Duration(milliseconds: 200));
    await tester.tap(find.byTooltip('Retirer des favoris'));
    await tester.pump(const Duration(milliseconds: 200));
    expect(api.appele('DELETE /favoris/5'), isTrue);
    expect(Cache.lireListe('favoris_ids'), isEmpty);
  });

  testWidgets('fiche : position approximative signalée, position précise sans mention', (tester) async {
    _telephone(tester);
    api.repondre('GET /etablissements/5', {'data': _pharmacie});
    await tester.pumpWidget(_appli(const Scaffold(body: FicheEtablissement(apercu: _pharmacie))));
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('Position approximative'), findsWidgets);
    expect(find.textContaining('confirmer la position'), findsOneWidget);

    final precise = {..._pharmacie, 'position_approx': false};
    api.repondre('GET /etablissements/5', {'data': precise});
    await tester.pumpWidget(_appli(Scaffold(body: FicheEtablissement(key: const ValueKey('precise'), apercu: precise))));
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('Position approximative'), findsNothing);
    expect(find.textContaining('confirmer la position'), findsNothing);
  });

  testWidgets('fiche : hors ligne, l\'aperçu de la liste reste affiché', (tester) async {
    _telephone(tester);
    api.horsLigne = true;
    await tester.pumpWidget(_appli(const Scaffold(body: FicheEtablissement(apercu: _pharmacie))));
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('Pharmacie du Plateau'), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  testWidgets('SOS : annuler le compte à rebours n\'envoie aucune alerte', (tester) async {
    _telephone(tester);
    Session.instance.token = 'jeton-test';
    api.repondre('GET /complices', {'data': <dynamic>[]});
    api.repondre('POST /alertes', {'data': {'id': 1}}, statut: 201);

    await tester.pumpWidget(_appli(const SosScreen()));
    await tester.pump(const Duration(milliseconds: 300));

    // Appui prolongé de 3 s sur le bouton SOS
    final geste = await tester.startGesture(tester.getCenter(find.text('SOS').first));
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(seconds: 3, milliseconds: 100));
    await geste.up();
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('Envoi de l\'alerte dans'), findsOneWidget);

    await tester.tap(find.text('Annuler'));
    await tester.pump(const Duration(seconds: 7)); // au-delà du compte à rebours : rien ne doit partir
    await tester.pump(const Duration(milliseconds: 500));
    expect(api.appele('POST /alertes'), isFalse);
    expect(find.text('Envoi de l\'alerte dans'), findsNothing);
    Session.instance.token = null;
  });

  testWidgets('réglages : thème et texte mémorisés sur l\'appareil', (tester) async {
    _telephone(tester);
    await tester.pumpWidget(_appli(const ReglagesScreen()));
    await tester.pump(const Duration(milliseconds: 200));
    await tester.tap(find.text('Sombre'));
    await tester.pump(const Duration(milliseconds: 200));
    expect(Cache.prefs.getString('theme_mode'), 'dark');
    expect(Apparence.instance.theme, ThemeMode.dark);

    await tester.tap(find.text('Grand'));
    await tester.pump(const Duration(milliseconds: 200));
    expect(Cache.prefs.getDouble('taille_texte'), 1.15);

    // Relecture à froid : mêmes préférences, nouvelle instance de SharedPreferences
    final brut = {'theme_mode': 'dark', 'taille_texte': 1.15};
    await ApiFactice.preparerCache(Map<String, Object>.from(brut));
    expect(Apparence.instance.theme, ThemeMode.dark);
    expect(Apparence.instance.tailleTexte, 1.15);
  });

  test('réglages de sécurité : mode discret persistant', () async {
    expect(Reglages.instance.modeDiscret, isFalse);
    await Reglages.instance.definirModeDiscret(true);
    expect(Cache.prefs.getBool('mode_discret'), isTrue);
    await Reglages.instance.definirAppelant('  Papa ');
    expect(Reglages.instance.appelant, 'Papa');
  });

  test('configuration : le cache hors ligne est conservé quand l\'API est injoignable', () async {
    api.repondre('GET /config', {
      'villes': [
        {'id': 1, 'nom': 'Abidjan'}
      ],
      'numeros_urgence': <dynamic>[],
    });
    await Session.instance.rafraichirConfig();
    expect(Session.instance.villes.first['nom'], 'Abidjan');

    api.horsLigne = true;
    await Session.instance.rafraichirConfig(); // échec silencieux
    expect(Session.instance.villes.first['nom'], 'Abidjan');
    expect(Cache.lireMap('config')?['villes'], isNotNull);
    // Les numéros d'urgence par défaut restent disponibles
    expect(Session.instance.numerosUrgence, isNotEmpty);
  });
}
