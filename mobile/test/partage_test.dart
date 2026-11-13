import 'package:bravido/core/langue.dart';
import 'package:bravido/core/partage.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  setUp(() => Langue.code = 'fr');

  final precise = <String, dynamic>{
    'nom': 'PHARMACIE DES ARTS',
    'categorie': {'code': 'pharmacie', 'libelle': 'Pharmacies'},
    'adresse': 'Rue des Jardins',
    'repere': 'Face à la mairie',
    'commune': 'Cocody',
    'ville': 'Abidjan',
    'tel1': '+2252722000000',
    'tel2': '+2250700000000',
    'lat': 5.3611,
    'lng': -3.9987,
    'position_approx': false,
  };

  test('position précise : lien par coordonnées', () {
    final m = messagePartage(precise);
    expect(m, contains('*PHARMACIE DES ARTS* (Pharmacies)'));
    expect(m, contains('Adresse : Rue des Jardins'));
    expect(m, contains('Repère : Face à la mairie'));
    expect(m, contains('Cocody, Abidjan'));
    expect(m, contains('Tél : +2252722000000 / +2250700000000'));
    expect(m, contains('https://www.google.com/maps/search/?api=1&query=5.3611%2C-3.9987'));
    expect(m.trim().endsWith('via BRAVIDO'), isTrue);
  });

  test('position approximative : recherche par nom et commune, jamais les coordonnées du centre', () {
    final m = messagePartage({...precise, 'position_approx': true});
    expect(m, isNot(contains('5.3611')));
    final lien = RegExp(r'https://\S+').firstMatch(m)!.group(0)!;
    final uri = Uri.parse(lien);
    expect(uri.queryParameters['query'], 'PHARMACIE DES ARTS, Cocody, Abidjan');
  });

  test('pharmacie de garde : période incluse', () {
    final m = messagePartage(precise, garde: {'du': '2026-11-09', 'au': '2026-11-15'});
    expect(m, contains('Pharmacie de garde du 09/11 au 15/11'));
    expect(messagePartage(precise), isNot(contains('de garde')));
  });

  test('champs vides ignorés, commune = ville dédoublonnée', () {
    final m = messagePartage({'nom': 'X', 'commune': 'Bouaké', 'ville': 'Bouaké', 'tel1': '  ', 'adresse': '', 'position_approx': true});
    expect(m, isNot(contains('Tél')));
    expect(m, isNot(contains('Adresse')));
    expect(m, contains('\nBouaké\n'));
    expect(m, isNot(contains('Bouaké, Bouaké')));
  });

  test('anglais', () {
    Langue.code = 'en';
    expect(messagePartage(precise), contains('Phone: +2252722000000'));
  });
}
