import 'package:bravido/core/langue.dart';
import 'package:bravido/core/ouverture.dart';
import 'package:flutter_test/flutter_test.dart';

/// 2026-11-09 est un lundi ; l'heure fournie est UTC = heure de Côte d'Ivoire.
DateTime t(int jour, int h, [int m = 0]) => DateTime.utc(2026, 11, 9 + jour, h, m);

Ouverture? o(Map<String, dynamic> e, DateTime instant) => ouvertureEtablissement(e, maintenant: instant);

void main() {
  setUp(() => Langue.code = 'fr');

  group('h24', () {
    test('drapeau h24', () => expect(o({'h24': true}, t(0, 3))!.libelle, 'Ouvert 24h/24'));
    test('texte 24h/24', () => expect(o({'horaires': '24h/24'}, t(2, 3))!.etat, EtatOuverture.ouvert24h));
  });

  group('horaires_detail', () {
    final detail = {'lun': '08:00-20:00', 'mar': '08:00-20:00', 'mer': '08:00-12:00, 14:00-18:00', 'sam': '09:00-13:00', 'dim': 'Fermé'};
    Map<String, dynamic> e() => {'horaires_detail': detail};

    test('ouvert en journee', () {
      final r = o(e(), t(0, 12))!;
      expect(r.etat, EtatOuverture.ouvert);
      expect(r.libelle, 'Ouvert · ferme à 20:00');
    });
    test('ferme bientot', () {
      final r = o(e(), t(0, 19, 15))!;
      expect(r.etat, EtatOuverture.fermeBientot);
      expect(r.libelle, 'Ferme bientôt · 20:00');
    });
    test('ferme le matin avant ouverture', () {
      final r = o(e(), t(0, 6))!;
      expect(r.etat, EtatOuverture.ferme);
      expect(r.libelle, 'Fermé · ouvre à 08:00');
    });
    test('apres la fermeture : ouvre demain', () {
      final r = o(e(), t(0, 21))!;
      expect(r.libelle, 'Fermé · ouvre demain à 08:00');
    });
    test('deux plages, pause du midi', () {
      final r = o(e(), t(2, 13))!;
      expect(r.etat, EtatOuverture.ferme);
      expect(r.heure, '14:00');
    });
    test('Fermé le dimanche, ouvre lundi', () {
      final r = o(e(), t(5, 14))!; // samedi après 13h, dimanche fermé
      expect(r.etat, EtatOuverture.ferme);
      expect(r.libelle, 'Fermé · ouvre lun. à 08:00');
    });
    test('jour absent du detail : on ne devine pas', () => expect(o(e(), t(3, 10)), isNull)); // jeudi
    test('valeur illisible : null', () => expect(o({'horaires_detail': {'lun': 'sur rendez-vous'}}, t(0, 10)), isNull));
    test('anglais', () {
      Langue.code = 'en';
      expect(o(e(), t(0, 12))!.libelle, 'Open · closes at 20:00');
      expect(o(e(), t(5, 14))!.libelle, 'Closed · opens Mon at 08:00');
    });
  });

  group('texte libre', () {
    test('Lun-Sam 8h-20h', () {
      final e = {'horaires': 'Lun-Sam 8h-20h'};
      expect(o(e, t(0, 12))!.etat, EtatOuverture.ouvert);
      expect(o(e, t(5, 12))!.etat, EtatOuverture.ouvert); // samedi
      expect(o(e, t(0, 7))!.libelle, 'Fermé · ouvre à 08:00');
    });
    test('dimanche non cite : inconnu', () => expect(o({'horaires': 'Lun-Sam 8h-20h'}, t(6, 12)), isNull));
    test('7h-22h tous les jours', () {
      final e = {'horaires': '7h-22h'};
      expect(o(e, t(6, 10))!.etat, EtatOuverture.ouvert);
      expect(o(e, t(3, 22, 30))!.etat, EtatOuverture.ferme);
    });
    test('08:00 - 18:30 avec minutes', () {
      final r = o({'horaires': '08:00 - 18:30'}, t(1, 18, 0))!;
      expect(r.etat, EtatOuverture.fermeBientot);
      expect(r.heure, '18:30');
    });
    test('plusieurs groupes de jours', () {
      final e = {'horaires': 'Lun-Ven 8h-17h, Sam 8h-12h, Dim fermé'};
      expect(o(e, t(4, 16))!.etat, EtatOuverture.fermeBientot); // vendredi 16h : ferme dans 60 min
      expect(o(e, t(5, 14))!.etat, EtatOuverture.ferme);
      expect(o(e, t(6, 9))!.etat, EtatOuverture.ferme);
      expect(o(e, t(6, 9))!.demain, isTrue);
    });
    test('deux plages le meme jour', () {
      final e = {'horaires': 'Lun-Ven 8h-12h, 14h-18h'};
      expect(o(e, t(0, 13))!.libelle, 'Fermé · ouvre à 14:00');
    });
    test('« 8h à 20h » avec accents/mots', () => expect(o({'horaires': 'Ouvert de 8h à 20h'}, t(0, 10))!.etat, EtatOuverture.ouvert));
    test('traverse minuit : 20h-6h', () {
      final e = {'horaires': '20h-6h'};
      expect(o(e, t(0, 23))!.etat, EtatOuverture.ouvert);
      expect(o(e, t(1, 2))!.etat, EtatOuverture.ouvert); // 02:00 mardi, plage de lundi
      expect(o(e, t(1, 2))!.heure, '06:00');
      expect(o(e, t(1, 12))!.etat, EtatOuverture.ferme);
    });
    test('traverse minuit : fermeture proche', () => expect(o({'horaires': '20h-6h'}, t(1, 5, 30))!.etat, EtatOuverture.fermeBientot));
  });

  group('jamais de devinette : texte illisible', () {
    for (final txt in ['', '   ', 'Sur rendez-vous', 'Ouvert 7j/7 selon garde', 'Voir avec la pharmacie', '8h', 'matin et soir', '25h-30h', '8h-8h', 'Lun-Ven 8h-17h sauf jours feries', 'abc 123']) {
      test('« $txt »', () => expect(o({'horaires': txt}, t(0, 10)), isNull));
    }
    test('pas de donnees', () => expect(o({}, t(0, 10)), isNull));
    test('horaires null', () => expect(o({'horaires': null, 'h24': false}, t(0, 10)), isNull));
  });

  group('fuseau', () {
    test("l'instant est lu en UTC (Abidjan), pas en heure locale de l'appareil", () {
      // 19h30 UTC+2 (appareil) = 17h30 UTC : doit rester « ouvert » pour 8h-20h
      final instant = DateTime.parse('2026-11-09T19:30:00+02:00');
      final r = o({'horaires': '8h-20h'}, instant)!;
      expect(r.etat, EtatOuverture.ouvert);
      expect(r.heure, '20:00');
    });
    test('changement de jour en UTC', () {
      final instant = DateTime.parse('2026-11-10T00:30:00+01:00'); // = lundi 23:30 UTC
      expect(heureAbidjan(instant), (0, 23 * 60 + 30)); // lundi 23:30
    });
  });
}
