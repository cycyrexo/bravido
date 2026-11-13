import 'api.dart';
import 'outils.dart';
import 'session.dart';
import 'suivi_alerte.dart';

/// Déclenche la vraie alerte SOS sans aucun retour sonore, vibratoire ni visuel :
/// utilisé par le geste caché du faux appel et par le mode discret.
/// Envoie immédiatement, avec la dernière position connue (ou une position rapide).
class SosSilencieux {
  static bool _enCours = false;

  /// Identifiant de la dernière alerte envoyée en silence (null si rien n'est parti).
  static int? derniereAlerteId;

  /// Renvoie l'id de l'alerte créée, ou null (non connecté / échec). Réessaie en silence 3 fois.
  static Future<int?> envoyer({String motif = 'autre'}) async {
    if (_enCours || !Session.instance.connecte) return null;
    _enCours = true;
    try {
      var position = Localisation.derniere;
      position ??= await Localisation.position(demander: false, delai: const Duration(seconds: 3));
      for (var essai = 0; essai < 3; essai++) {
        try {
          final r = await Api.instance.post('/alertes', {
            'motif': motif,
            if (position != null) 'lat': position.latitude,
            if (position != null) 'lng': position.longitude,
            if (position != null) 'precision_m': position.accuracy.round(),
          }) as Map;
          final id = (r['data'] as Map)['id'] as int;
          derniereAlerteId = id;
          // Le suivi de position continue ; la notification Android « Alerte en cours » reste visible dans le volet.
          await SuiviAlerte.demarrer(id);
          return id;
        } on ApiException catch (e) {
          if (!e.horsLigne) return null;
          await Future<void>.delayed(const Duration(seconds: 4));
        }
      }
      return null;
    } finally {
      _enCours = false;
    }
  }
}
