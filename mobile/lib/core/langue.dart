import 'package:flutter/foundation.dart';

import 'cache.dart';

/// Langue de l'interface (F15) : « fr » ou « en ». Traduction légère : `tr('français', 'English')`.
/// La langue par défaut est celle de l'appareil si elle est fr/en, sinon le français.
class Langue extends ChangeNotifier {
  Langue._();
  static final Langue instance = Langue._();

  /// Code courant ; lisible sans contexte (helpers purs, tests).
  static String code = 'fr';

  /// Choix mémorisé : 'auto' (appareil), 'fr' ou 'en'.
  static String get choix {
    try {
      return Cache.prefs.getString('langue') ?? 'auto';
    } catch (_) {
      return 'auto';
    }
  }

  /// À appeler au démarrage, après [Cache.init].
  static void charger(String langueAppareil) {
    code = resoudre(choix, langueAppareil);
  }

  static String resoudre(String choix, String langueAppareil) {
    if (choix == 'fr' || choix == 'en') return choix;
    return langueAppareil.toLowerCase().startsWith('en') ? 'en' : 'fr';
  }

  Future<void> definir(String nouveauChoix, String langueAppareil) async {
    await Cache.prefs.setString('langue', nouveauChoix);
    code = resoudre(nouveauChoix, langueAppareil);
    notifyListeners();
  }
}

/// Traduction : renvoie le texte anglais si la langue courante est « en », sinon le français.
String tr(String fr, String en) => Langue.code == 'en' ? en : fr;

/// Dictionnaire des libellés fixes (titres d'écran, onglets, catégories) : renvoie la traduction
/// anglaise si elle existe, sinon le texte tel quel (les données serveur ne sont jamais traduites).
String trd(String fr) => Langue.code == 'en' ? (_dico[fr] ?? fr) : fr;

const _dico = <String, String>{
  'Alerte & Urgence': 'Alert & Emergency',
  'Espace Utilisateur': 'My Account',
  'Faux appel': 'Fake call',
  'Fiche établissement': 'Facility profile',
  'Je gère cet établissement': 'I manage this facility',
  'Je rentre chez moi': 'Heading home',
  'Ma fiche médicale': 'My medical card',
  'Mes complices': 'My accomplices',
  'Mes fiches établissement': 'My facility profiles',
  'Mes messages': 'My messages',
  'Modifier ma fiche': 'Edit my profile',
  'Mon établissement': 'My facility',
  'Alerte en cours': 'Alert in progress',
  'Alerte terminée': 'Alert ended',
  'Alerte d\'un proche': 'Alert from a contact',
  'Alerte': 'Alert',
  'Hôpitaux & Cliniques': 'Hospitals & Clinics',
  'Publics': 'Public',
  'Privés': 'Private',
  'Militaires': 'Military',
  'Vétérinaires': 'Veterinary',
  'Optique': 'Optical',
  'Dentaires': 'Dental',
  'Imagerie': 'Imaging',
  'Laboratoires': 'Laboratories',
  'Centres de secours': 'Rescue centres',
  'Pompiers': 'Fire brigade',
  'Bureaux FGA': 'FGA offices',
  'Pharmacies': 'Pharmacies',
  'Hôpitaux': 'Hospitals',
  'Malaise': 'Feeling unwell',
  'Accident': 'Accident',
  'Agression': 'Assault',
  'Autre': 'Other',
  'Lundi': 'Monday',
  'Mardi': 'Tuesday',
  'Mercredi': 'Wednesday',
  'Jeudi': 'Thursday',
  'Vendredi': 'Friday',
  'Samedi': 'Saturday',
  'Dimanche': 'Sunday',
};
