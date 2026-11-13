import 'package:bravido/core/rappels.dart';
import 'package:flutter_test/flutter_test.dart';

Rappel r(List<String> heures, {String jour = '2026-11-09', Repetition rep = Repetition.quotidien, int avant = 0, bool actif = true, TypeRappel type = TypeRappel.medicament}) =>
    Rappel(id: 1, titre: 'Test', heures: heures, jour: jour, repetition: rep, avantMinutes: avant, actif: actif, type: type);

DateTime t(int jour, int h, [int m = 0]) => DateTime.utc(2026, 11, jour, h, m); // 9 nov 2026 = lundi

void main() {
  group('quotidien', () {
    test('prochaine heure du jour', () => expect(prochainesEcheances(r(['08:00', '20:00']), t(9, 9)).single, t(9, 20)));
    test('après la dernière heure : lendemain', () => expect(prochainesEcheances(r(['08:00', '20:00']), t(9, 21)).single, t(10, 8)));
    test('strictement après maintenant', () => expect(prochainesEcheances(r(['08:00']), t(9, 8)).single, t(10, 8)));
    test('plusieurs échéances triées', () {
      expect(prochainesEcheances(r(['20:00', '08:00', '14:00']), t(9, 7), max: 4), [t(9, 8), t(9, 14), t(9, 20), t(10, 8)]);
    });
    test('pas avant le premier jour', () => expect(prochainesEcheances(r(['08:00'], jour: '2026-11-20'), t(9, 9)).single, t(20, 8)));
    test('longtemps après le début', () => expect(prochainesEcheances(r(['08:00'], jour: '2026-01-01'), t(9, 9)).single, t(10, 8)));
    test('passage de mois', () => expect(prochainesEcheances(r(['23:30']), DateTime.utc(2026, 11, 30, 23, 45)).single, DateTime.utc(2026, 12, 1, 23, 30)));
  });

  group('hebdomadaire', () {
    test('même jour de semaine que le premier jour (lundi)', () {
      expect(prochainesEcheances(r(['08:00'], rep: Repetition.hebdo), t(9, 9)).single, t(16, 8));
      expect(prochainesEcheances(r(['08:00'], rep: Repetition.hebdo), t(12, 9)).single, t(16, 8)); // jeudi
      expect(prochainesEcheances(r(['08:00'], rep: Repetition.hebdo), t(9, 7)).single, t(9, 8));
    });
    test('échéances successives espacées de 7 jours', () {
      expect(prochainesEcheances(r(['08:00'], rep: Repetition.hebdo), t(9, 9), max: 3), [t(16, 8), t(23, 8), t(30, 8)]);
    });
  });

  group('une seule fois (rendez-vous)', () {
    test('à venir', () => expect(prochainesEcheances(r(['10:30'], jour: '2026-11-12', rep: Repetition.aucune, type: TypeRappel.rendezvous), t(9, 9)).single, t(12, 10, 30)));
    test('passé : aucune échéance', () => expect(prochainesEcheances(r(['10:30'], jour: '2026-11-08', rep: Repetition.aucune), t(9, 9)), isEmpty));
    test('préavis d’une heure', () => expect(prochainesEcheances(r(['10:30'], jour: '2026-11-12', rep: Repetition.aucune, avant: 60), t(9, 9)).single, t(12, 9, 30)));
    test('préavis la veille (1440 min)', () => expect(prochainesEcheances(r(['10:30'], jour: '2026-11-12', rep: Repetition.aucune, avant: 1440), t(9, 9)).single, t(11, 10, 30)));
    test('préavis déjà dépassé : plus d’échéance', () => expect(prochainesEcheances(r(['10:30'], jour: '2026-11-12', rep: Repetition.aucune, avant: 60), t(12, 10)), isEmpty));
  });

  test('désactivé : aucune échéance', () => expect(prochainesEcheances(r(['08:00'], actif: false), t(9, 9)), isEmpty));
  test('horaire invalide ignoré', () => expect(prochainesEcheances(r(['25:00', 'abc', '07:15']), t(9, 6)).single, t(9, 7, 15)));

  test('heure convertie en UTC depuis un instant local', () {
    // 10h le 9 nov à +02:00 = 08:00 UTC : la prochaine heure 09:00 est dans une heure (Abidjan)
    final local = DateTime.parse('2026-11-09T10:00:00+02:00');
    expect(prochainesEcheances(r(['09:00']), local).single, t(9, 9));
  });

  test('sérialisation JSON aller-retour et rejet des entrées invalides', () {
    final a = Rappel(id: 7, titre: 'Paracétamol', note: '1 comprimé', heures: ['08:00', '20:00'], jour: '2026-11-09', repetition: Repetition.hebdo, actif: false);
    final b = Rappel.depuisJson(a.toJson())!;
    expect(b.toJson(), a.toJson());
    expect(Rappel.depuisJson({'id': 1, 'titre': 'x', 'heures': ['zz'], 'jour': '2026-11-09'}), isNull);
    expect(Rappel.depuisJson('bof'), isNull);
  });

  test('libellé d’échéance', () {
    String l(DateTime e) => libelleEcheance(e, t(9, 9), aujourdhui: "Aujourd'hui", demain: 'Demain');
    expect(l(t(9, 20)), "Aujourd'hui 20:00");
    expect(l(t(10, 8, 5)), 'Demain 08:05');
    expect(l(t(15, 9)), '15/11 09:00');
  });
}
