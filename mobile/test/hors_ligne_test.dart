import 'dart:io';

import 'package:bravido/core/api.dart';
import 'package:bravido/core/cache.dart';
import 'package:bravido/core/hors_ligne.dart';
import 'package:bravido/core/moteur_local.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/api_factice.dart';

Map<String, dynamic> _etab(int id, String nom, String cat, {String libelle = '', bool approx = false, double lat = 5.33, double lng = -4.02, List<String> services = const [], List<int> assurances = const [], List<Map<String, String>> gardes = const [], int ville = 1, int commune = 10, bool h24 = false}) => {
      'id': id,
      'nom': nom,
      'categorie': {'code': cat, 'libelle': libelle.isEmpty ? cat : libelle},
      'commune': 'Cocody',
      'ville_id': ville,
      'commune_id': commune,
      'lat': lat,
      'lng': lng,
      'position_approx': approx,
      'services': services,
      'assurances': [for (final a in assurances) {'id': a, 'nom': 'Assurance $a'}],
      'gardes': gardes,
      if (h24) 'h24': true,
    };

String _jour(int decalage) {
  final d = DateTime.now().add(Duration(days: decalage));
  return '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
}

void main() {
  final etabs = [
    _etab(1, 'Pharmacie de la Riviera', 'pharmacie', libelle: 'Pharmacie', gardes: [
      {'du': _jour(-1), 'au': _jour(2)}
    ], assurances: [7]),
    _etab(2, 'Pharmacie Saint-Michel', 'pharmacie', libelle: 'Pharmacie', lat: 5.40, lng: -4.00, approx: true),
    _etab(3, 'Clinique Dentaire Cocody', 'dentaire', libelle: 'Dentaire', services: ['Dentisterie', 'Orthodontie']),
    _etab(4, 'Hôpital Général d\'Abobo', 'hopital_public', libelle: 'Hôpital public', services: ['Maternité', 'Urgences'], ville: 1, commune: 11, h24: true),
    _etab(5, 'Centre Optique Vision', 'optique', libelle: 'Optique'),
    _etab(6, 'Pharmacie de Bouaké', 'pharmacie', libelle: 'Pharmacie', ville: 2, commune: 20, lat: 7.69, lng: -5.03, gardes: [
      {'du': _jour(-3), 'au': _jour(-1)}
    ]),
  ];
  late MoteurLocal m;
  setUp(() => m = MoteurLocal(etabs));

  group('MoteurLocal : normalisation et synonymes', () {
    test('normaliser retire accents, ponctuation et casse', () {
      expect(MoteurLocal.normaliser('  Hôpital  Général d\'Abobo! '), 'hopital general d abobo');
      expect(MoteurLocal.normaliser('Cœur'), 'coeur');
    });

    test('recherche « dentiste » : catégorie + services via les synonymes', () {
      final r = m.repondre('/recherche', {'q': 'dentiste'})!;
      final noms = (r['data'] as List).map((e) => e['nom']).toList();
      expect(noms, contains('Clinique Dentaire Cocody'));
      expect(noms, isNot(contains('Centre Optique Vision')));
    });

    test('recherche tolérante aux accents et aux fautes légères', () {
      final accent = m.repondre('/recherche', {'q': 'hopital'})!;
      expect((accent['data'] as List).first['nom'], contains('Hôpital'));
      // « pharmacy » inconnu, « pharmacie » mal écrit : correction vers le synonyme le plus proche
      final faute = m.repondre('/recherche', {'q': 'pharmcie'})!;
      expect((faute['data'] as List).length, greaterThanOrEqualTo(2));
    });

    test('recherche : classement nom exact > début de nom > contenu, et groupes par catégorie', () {
      final r = m.repondre('/recherche', {'q': 'pharmacie'})!;
      final data = r['data'] as List;
      expect(data.every((e) => e['categorie']['code'] == 'pharmacie'), isTrue);
      expect((r['meta']['groupes'] as List).first['code'], 'pharmacie');
      expect(r['meta']['total'], 3);

      final filtre = m.repondre('/recherche', {'q': 'pharmacie', 'ville_id': 2})!;
      expect((filtre['data'] as List).single['nom'], 'Pharmacie de Bouaké');
    });

    test('recherche vide ou trop courte', () {
      expect((m.repondre('/recherche', {'q': ''})!['data'] as List), isEmpty);
      expect(m.suggestions('a'), isEmpty);
    });

    test('suggestions : catégorie, services puis noms', () {
      final s = m.suggestions('dent');
      expect(s.first['type'], 'categorie');
      expect(s.any((x) => x['type'] == 'service' && x['libelle'] == 'Dentisterie'), isTrue);
      expect(s.any((x) => x['type'] == 'etablissement' && x['id'] == 3), isTrue);
    });
  });

  group('MoteurLocal : listes et filtres', () {
    test('filtre par catégorie, ville et assurance', () {
      final r = m.repondre('/etablissements', {'categorie': 'pharmacie', 'ville_id': 1})!;
      expect((r['data'] as List).map((e) => e['id']), unorderedEquals([1, 2]));
      final a = m.repondre('/etablissements', {'categorie': 'pharmacie', 'assurance_id': 7})!;
      expect((a['data'] as List).single['id'], 1);
      expect(m.repondre('/etablissements', {'h24': true})!['meta']['total'], 1);
      expect((m.repondre('/etablissements', {'service': 'Maternité'})!['data'] as List).single['id'], 4);
    });

    test('tri par distance : positions précises d\'abord, jamais de distance pour une position approximative', () {
      final r = m.repondre('/etablissements', {'ville_id': 1, 'lat': 5.33, 'lng': -4.02})!;
      final data = (r['data'] as List).cast<Map>();
      final approx = data.firstWhere((e) => e['id'] == 2);
      expect(approx.containsKey('distance_m'), isFalse);
      expect(data.first['distance_m'], isA<int>());
      final distances = data.where((e) => e['distance_m'] != null).map((e) => e['distance_m'] as int).toList();
      expect([...distances]..sort(), distances);
    });

    test('pagination', () {
      final r = m.repondre('/etablissements', {'par_page': 2, 'page': 2})!;
      expect((r['data'] as List).length, 2);
      expect(r['meta'], {'total': 6, 'current_page': 2, 'last_page': 3, 'per_page': 2});
    });

    test('pharmacies de garde : seulement les gardes en cours, période fournie', () {
      final r = m.repondre('/pharmacies/garde', {'ville_id': 1})!;
      final data = r['data'] as List;
      expect(data.single['id'], 1);
      expect(data.single['garde']['du'], _jour(-1));
      expect(r['periode'], {'du': _jour(-1), 'au': _jour(2)});
      // Garde terminée (Bouaké) : écartée
      expect((m.repondre('/pharmacies/garde', {'ville_id': 2})!['data'] as List), isEmpty);
    });

    test('fiche par identifiant, inconnue : null', () {
      expect(m.repondre('/etablissements/3', null)!['data']['nom'], 'Clinique Dentaire Cocody');
      expect(m.repondre('/etablissements/999', null), isNull);
      expect(m.repondre('/me', null), isNull);
    });

    test('carte : positions précises seulement, compteur « sans position »', () {
      final r = m.repondre('/etablissements/carte', {'bbox': '5.0,-4.5,5.6,-3.5'})!;
      expect((r['data'] as List).any((e) => e['id'] == 2), isFalse);
      expect(r['meta']['sans_position'], 1);
    });
  });

  group('HorsLigne : téléchargement, version, secours', () {
    late ApiFactice api;
    late Directory tmp;
    int telechargementsComplets = 0;

    setUp(() async {
      api = ApiFactice.installer();
      await ApiFactice.preparerCache();
      tmp = Directory.systemTemp.createTempSync('bravido_hl');
      HorsLigne.instance
        ..reinitialiser()
        ..dossier = tmp;
      telechargementsComplets = 0;
      api.repondreAvec('GET /villes/1/export', (o) {
        final q = o.queryParameters;
        if (q['version'] == 'v1') return Reponse({'a_jour': true, 'version': 'v1'});
        if ('${q['page']}' == '1') telechargementsComplets++;
        final page = int.parse('${q['page']}');
        final data = page == 1 ? etabs.take(3).toList() : etabs.skip(3).take(2).toList();
        return Reponse({
          'data': data,
          'meta': {'version': 'v1', 'ville': {'id': 1, 'nom': 'Abidjan'}, 'total': 5, 'page': page, 'pages': 2}
        });
      });
    });

    tearDown(() {
      if (tmp.existsSync()) tmp.deleteSync(recursive: true);
    });

    test('téléchargement paginé, stockage puis relecture à froid', () async {
      final ok = await HorsLigne.instance.telecharger(1, 'Abidjan');
      expect(ok, isTrue);
      expect(HorsLigne.instance.progression, 1);
      final v = HorsLigne.instance.ville(1)!;
      expect(v.nombre, 5);
      expect(v.octets, greaterThan(100));
      expect(v.version, 'v1');

      // Relecture à froid (nouvelle instance en mémoire, mêmes fichiers + préférences)
      HorsLigne.instance.reinitialiser();
      HorsLigne.instance.dossier = tmp;
      await HorsLigne.instance.charger();
      expect(HorsLigne.instance.ville(1)?.nombre, 5);
      expect(Cache.lireListe('hors_ligne_villes'), isNotEmpty);
    });

    test('mise à jour : version identique = aucun retéléchargement', () async {
      await HorsLigne.instance.telecharger(1, 'Abidjan');
      expect(telechargementsComplets, 1);
      final ok = await HorsLigne.instance.telecharger(1, 'Abidjan');
      expect(ok, isFalse);
      expect(HorsLigne.instance.erreur, isNull);
      expect(telechargementsComplets, 1); // seule la requête de version a été envoyée
      expect(api.nombreAppels('GET /villes/1/export'), 3); // 2 pages + 1 contrôle de version
    });

    test('secours : réseau coupé, la liste est servie localement avec la date des données', () async {
      await HorsLigne.instance.telecharger(1, 'Abidjan');
      api.horsLigne = true;
      final r = await Api.instance.get('/etablissements', query: {'ville_id': 1, 'categorie': 'pharmacie'}) as Map;
      expect((r['data'] as List).length, 2);
      expect(HorsLigne.instance.donneesLocalesDu.value, isNotNull);

      // Le réseau revient : le bandeau disparaît
      api.horsLigne = false;
      api.repondre('GET /config', {'villes': <dynamic>[]});
      await Api.instance.get('/config');
      expect(HorsLigne.instance.donneesLocalesDu.value, isNull);
    });

    test('secours : ville non téléchargée, route non gérée ou aucune donnée = erreur réseau normale', () async {
      api.horsLigne = true;
      await expectLater(Api.instance.get('/etablissements', query: {'ville_id': 1}), throwsA(isA<ApiException>()));

      api.horsLigne = false;
      await HorsLigne.instance.telecharger(1, 'Abidjan');
      api.horsLigne = true;
      await expectLater(Api.instance.get('/etablissements', query: {'ville_id': 2}), throwsA(isA<ApiException>()));
      await expectLater(Api.instance.get('/me'), throwsA(isA<ApiException>()));
      expect(HorsLigne.instance.donneesLocalesDu.value, isNull);
    });

    test('recherche locale hors ligne via Api.get et fiche', () async {
      await HorsLigne.instance.telecharger(1, 'Abidjan');
      api.horsLigne = true;
      final r = await Api.instance.get('/recherche', query: {'q': 'dentiste'}) as Map;
      expect((r['data'] as List).first['id'], 3);
      final f = await Api.instance.get('/etablissements/4') as Map;
      expect(f['data']['nom'], contains('Hôpital'));
      final fav = await HorsLigne.instance.favorisLocaux([1, 3, 999]);
      expect(fav.map((e) => e['id']), [1, 3]);
    });

    test('suppression : fichier et index effacés, plus de secours', () async {
      await HorsLigne.instance.telecharger(1, 'Abidjan');
      await HorsLigne.instance.supprimer(1);
      expect(HorsLigne.instance.aDesDonnees, isFalse);
      expect(tmp.listSync().where((f) => f.path.endsWith('.json')), isEmpty);
      api.horsLigne = true;
      await expectLater(Api.instance.get('/etablissements', query: {'ville_id': 1}), throwsA(isA<ApiException>()));
    });

    test('erreur réseau pendant le téléchargement : message, ancienne version conservée', () async {
      await HorsLigne.instance.telecharger(1, 'Abidjan');
      api.horsLigne = true;
      // Une version plus récente existe côté serveur, mais le réseau tombe : on garde l'ancienne
      final ok = await HorsLigne.instance.telecharger(1, 'Abidjan');
      expect(ok, isFalse);
      expect(HorsLigne.instance.ville(1)?.version, 'v1');
    });
  });
}
