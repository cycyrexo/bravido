import 'dart:math' as math;

import 'package:bravido/app.dart';
import 'package:bravido/core/apparence.dart';
import 'package:bravido/core/langue.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

double _luminance(Color c) {
  double canal(double v) => v <= 0.03928 ? v / 12.92 : math.pow((v + 0.055) / 1.055, 2.4).toDouble();
  return 0.2126 * canal(c.r) + 0.7152 * canal(c.g) + 0.0722 * canal(c.b);
}

double _contraste(Color a, Color b) {
  final la = _luminance(a), lb = _luminance(b);
  return (math.max(la, lb) + 0.05) / (math.min(la, lb) + 0.05);
}

void main() {
  tearDown(() {
    Langue.code = 'fr';
    Couleurs.sombre = false;
  });

  group('Langue', () {
    test('choix automatique : langue de l\'appareil si fr/en, sinon français', () {
      expect(Langue.resoudre('auto', 'en'), 'en');
      expect(Langue.resoudre('auto', 'en_US'), 'en');
      expect(Langue.resoudre('auto', 'fr'), 'fr');
      expect(Langue.resoudre('auto', 'es'), 'fr');
      expect(Langue.resoudre('auto', 'ar'), 'fr');
    });

    test('choix explicite prioritaire sur l\'appareil', () {
      expect(Langue.resoudre('fr', 'en'), 'fr');
      expect(Langue.resoudre('en', 'fr'), 'en');
    });

    test('tr et trd suivent la langue courante', () {
      expect(tr('Annuler', 'Cancel'), 'Annuler');
      expect(trd('Publics'), 'Publics');
      Langue.code = 'en';
      expect(tr('Annuler', 'Cancel'), 'Cancel');
      expect(trd('Publics'), 'Public');
      expect(trd('Libellé inconnu du serveur'), 'Libellé inconnu du serveur');
    });
  });

  group('Apparence', () {
    test('valeurs par défaut sans préférences : thème système, texte 1.0', () {
      expect(Apparence.instance.theme, ThemeMode.system);
      expect(Apparence.instance.tailleTexte, 1.0);
      expect(Apparence.taillesTexte, [0.9, 1.0, 1.15, 1.3]);
    });
  });

  group('Contraste WCAG AA (>= 4,5:1)', () {
    void verifier(String nom) {
      expect(_contraste(Couleurs.texte, Couleurs.fond), greaterThanOrEqualTo(4.5), reason: '$nom texte/fond');
      expect(_contraste(Couleurs.texte, Couleurs.surface), greaterThanOrEqualTo(4.5), reason: '$nom texte/surface');
      expect(_contraste(Couleurs.texteSecondaire, Couleurs.surface), greaterThanOrEqualTo(4.5), reason: '$nom secondaire/surface');
      expect(_contraste(Couleurs.texteSecondaire, Couleurs.fond), greaterThanOrEqualTo(4.5), reason: '$nom secondaire/fond');
      expect(_contraste(Couleurs.texteSecondaire, Couleurs.gris), greaterThanOrEqualTo(4.5), reason: '$nom secondaire/gris');
      expect(_contraste(Couleurs.accent, Couleurs.surface), greaterThanOrEqualTo(4.5), reason: '$nom accent/surface');
      expect(_contraste(Couleurs.encreVerte, Couleurs.vertClair), greaterThanOrEqualTo(4.5), reason: '$nom encre verte');
      expect(_contraste(Couleurs.encreOrange, Couleurs.orangeClair), greaterThanOrEqualTo(4.5), reason: '$nom encre orange');
      expect(_contraste(Couleurs.encreRouge, Couleurs.rougeClair), greaterThanOrEqualTo(4.5), reason: '$nom encre rouge');
      expect(_contraste(Couleurs.encreRouge, Couleurs.surface), greaterThanOrEqualTo(4.5), reason: '$nom rouge/surface');
      expect(_contraste(Couleurs.encreGrise, Couleurs.gris), greaterThanOrEqualTo(4.5), reason: '$nom encre grise');
    }

    test('thème clair', () {
      Couleurs.sombre = false;
      verifier('clair');
    });

    test('thème sombre', () {
      Couleurs.sombre = true;
      verifier('sombre');
    });
  });
}
