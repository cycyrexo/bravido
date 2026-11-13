import 'package:flutter/material.dart';

import '../core/langue.dart';
import '../core/rapports.dart';
import '../widgets/communs.dart';

/// F21 : interrupteur « Envoyer les rapports d'erreurs anonymes » (désactivé par défaut).
class CarteRapportsErreurs extends StatelessWidget {
  const CarteRapportsErreurs({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final rapports = RapportsErreurs.instance;
    return ListenableBuilder(
      listenable: rapports,
      builder: (context, _) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        TitreSection(tr('Confidentialité', 'Privacy')),
        Card(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 8, 8, 8),
            child: Row(children: [
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr('Envoyer les rapports d\'erreurs anonymes', 'Send anonymous error reports'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  const SizedBox(height: 4),
                  Text(
                    tr('Si l\'application plante, un rapport technique est envoyé pour nous aider à la corriger. Il ne contient ni votre nom, ni votre numéro, ni vos complices, ni votre position, ni aucun message. Désactivé par défaut.',
                        'If the app crashes, a technical report is sent to help us fix it. It contains no name, phone number, accomplices, location or message. Off by default.'),
                    style: TextStyle(color: theme.colorScheme.onSurfaceVariant, fontSize: 12.5, height: 1.35),
                  ),
                ]),
              ),
              Switch(
                value: rapports.actif,
                onChanged: (v) => rapports.definir(v),
              ),
            ]),
          ),
        ),
      ]),
    );
  }
}
