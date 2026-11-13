import 'package:flutter/material.dart';

import '../app.dart';
import '../core/hors_ligne.dart';
import '../core/langue.dart';

/// F20 : bandeau « Hors ligne · données du jj/mm/aaaa » affiché tant que les écrans lisent les données téléchargées.
class BandeauHorsLigne extends StatelessWidget {
  const BandeauHorsLigne({super.key});

  static String formater(DateTime d) {
    String deux(int n) => n.toString().padLeft(2, '0');
    return '${deux(d.day)}/${deux(d.month)}/${d.year}';
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<DateTime?>(
      valueListenable: HorsLigne.instance.donneesLocalesDu,
      builder: (context, date, _) {
        if (date == null) return const SizedBox.shrink();
        final fond = Couleurs.sombre ? const Color(0xFF4A3410) : const Color(0xFFFFE9C7);
        final encre = Couleurs.sombre ? const Color(0xFFFFD79A) : const Color(0xFF5A3200);
        return Semantics(
          liveRegion: true,
          child: Material(
            color: fond,
            child: SafeArea(
              bottom: false,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                child: Row(children: [
                  Icon(Icons.cloud_off_rounded, size: 16, color: encre),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      tr('Hors ligne · données du ${formater(date)}', 'Offline · data from ${formater(date)}'),
                      style: TextStyle(color: encre, fontSize: 12.5, fontWeight: FontWeight.w700),
                    ),
                  ),
                ]),
              ),
            ),
          ),
        );
      },
    );
  }
}
