/// Configuration de compilation.
///
/// URL de l'API : `flutter run --dart-define=API_URL=https://api.bravido.ci/api/v1`
/// Par défaut : WAMP sur la machine hôte, vue depuis l'émulateur Android (10.0.2.2).
class Config {
  static const apiUrl = String.fromEnvironment(
    'API_URL',
    defaultValue: 'http://10.0.2.2/bravido/backend/public/api/v1',
  );

  /// Numéros nationaux embarqués : disponibles hors ligne dès le premier lancement (URG-05).
  static const numerosUrgenceParDefaut = [
    {'libelle': 'SAMU', 'numero': '185', 'description': 'Urgences médicales'},
    {'libelle': 'Sapeurs-pompiers (GSPM / ONPC)', 'numero': '180', 'description': 'Incendie, accident, secours à personne'},
    {'libelle': 'Police secours', 'numero': '110', 'description': null},
    {'libelle': 'Police secours', 'numero': '111', 'description': null},
    {'libelle': 'Police secours', 'numero': '170', 'description': null},
  ];

  static const motifs = {
    'malaise': 'Malaise',
    'accident': 'Accident',
    'agression': 'Agression',
    'autre': 'Autre',
  };
}
