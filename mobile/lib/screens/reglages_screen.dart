import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../app.dart';
import '../core/apparence.dart';
import '../core/langue.dart';
import '../widgets/communs.dart';
import 'alerte/faux_appel_screen.dart';
import 'compte/rappels_screen.dart';
import 'hors_ligne_section.dart';
import 'rapports_section.dart';

/// F15 : réglages d'affichage (thème, taille du texte, langue) + mode discret.
/// Accessible depuis l'accueil, sans compte.
class ReglagesScreen extends StatelessWidget {
  const ReglagesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final apparence = Apparence.instance;
    return Scaffold(
      appBar: BarreTitre(tr('Réglages', 'Settings'), actions: const [SosAction()]),
      body: ListenableBuilder(
        listenable: Listenable.merge([apparence, Langue.instance]),
        builder: (context, _) => ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 32), children: [
          TitreSection(tr('Thème', 'Theme')),
          SegmentedButton<ThemeMode>(
            segments: [
              ButtonSegment(value: ThemeMode.system, icon: const Icon(Icons.brightness_auto_rounded), label: Text(tr('Système', 'System'))),
              ButtonSegment(value: ThemeMode.light, icon: const Icon(Icons.light_mode_rounded), label: Text(tr('Clair', 'Light'))),
              ButtonSegment(value: ThemeMode.dark, icon: const Icon(Icons.dark_mode_rounded), label: Text(tr('Sombre', 'Dark'))),
            ],
            selected: {apparence.theme},
            onSelectionChanged: (s) => apparence.definirTheme(s.first),
          ),
          TitreSection(tr('Taille du texte', 'Text size')),
          SegmentedButton<double>(
            segments: [
              for (final t in Apparence.taillesTexte) ButtonSegment(value: t, label: Text(_etiquetteTaille(t))),
            ],
            showSelectedIcon: false,
            selected: {apparence.tailleTexte},
            onSelectionChanged: (s) => apparence.definirTailleTexte(s.first),
          ),
          const SizedBox(height: 8),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Text(
                tr('Aperçu : en cas d\'urgence, appelez le 185 (SAMU).', 'Preview: in an emergency, call 185 (SAMU).'),
                style: TextStyle(fontSize: 15, color: theme.colorScheme.onSurface),
              ),
            ),
          ),
          TitreSection(tr('Langue', 'Language')),
          SegmentedButton<String>(
            segments: [
              ButtonSegment(value: 'auto', label: Text(tr('Auto', 'Auto'))),
              const ButtonSegment(value: 'fr', label: Text('Français')),
              const ButtonSegment(value: 'en', label: Text('English')),
            ],
            showSelectedIcon: false,
            selected: {Langue.choix},
            onSelectionChanged: (s) => Langue.instance.definir(s.first, ui.PlatformDispatcher.instance.locale.languageCode),
          ),
          Padding(
            padding: const EdgeInsets.only(top: 6, left: 2),
            child: Text(
              tr('« Auto » suit la langue du téléphone (français ou anglais). Les noms et services des établissements restent tels que publiés.',
                  '"Auto" follows the phone language (French or English). Facility names and services stay as published.'),
              style: TextStyle(color: theme.colorScheme.onSurfaceVariant, fontSize: 12.5, height: 1.35),
            ),
          ),
          TitreSection(tr('Sécurité', 'Safety')),
          const CarteModeDiscret(),
          const CarteRapportsErreurs(),
          const SectionHorsLigne(),
          TitreSection(tr('Autres', 'Other')),
          LigneMenu(
            badge: const Icon(Icons.alarm_rounded, size: 20, color: Color(0xFFB0307A)),
            couleurBadge: Couleurs.rose,
            titre: tr('Mes rappels', 'My reminders'),
            sousTitre: tr('Médicaments et rendez-vous', 'Medication and appointments'),
            onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const RappelsScreen())),
          ),
        ]),
      ),
    );
  }

  static String _etiquetteTaille(double t) => switch (t) {
        0.9 => tr('Petit', 'Small'),
        1.15 => tr('Grand', 'Large'),
        1.3 => tr('Très grand', 'X-Large'),
        _ => tr('Normal', 'Normal'),
      };
}
