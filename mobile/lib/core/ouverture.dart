import 'langue.dart';

/// F12 — « Ouvert maintenant ». Fonctions pures : aucun accès réseau ni horloge cachée.
/// L'heure de référence est celle de Côte d'Ivoire (Africa/Abidjan = UTC+0, sans heure d'été),
/// calculée depuis l'instant UTC : le fuseau de l'appareil n'intervient jamais.
/// Règle d'or : si les horaires ne sont pas parfaitement lisibles, on ne dit RIEN (null) plutôt que de deviner.

enum EtatOuverture { ouvert24h, ouvert, ferme, fermeBientot }

class Ouverture {
  const Ouverture(this.etat, {this.heure, this.jourOuverture, this.demain = false});

  final EtatOuverture etat;

  /// « 20:00 » : heure de fermeture (ouvert) ou d'ouverture (fermé).
  final String? heure;

  /// Jour de la prochaine ouverture si ce n'est pas aujourd'hui : 0 = lundi … 6 = dimanche.
  final int? jourOuverture;

  /// La prochaine ouverture est demain.
  final bool demain;

  bool get estOuvert => etat != EtatOuverture.ferme;

  /// Délai (minutes) avant la fermeture sous lequel on affiche « Ferme bientôt ».
  static const seuilFermeBientot = 60;

  static const _joursCourts = [
    ('lun.', 'Mon'), ('mar.', 'Tue'), ('mer.', 'Wed'), ('jeu.', 'Thu'), ('ven.', 'Fri'), ('sam.', 'Sat'), ('dim.', 'Sun'),
  ];

  String get libelle {
    switch (etat) {
      case EtatOuverture.ouvert24h:
        return tr('Ouvert 24h/24', 'Open 24 hours');
      case EtatOuverture.ouvert:
        return tr('Ouvert · ferme à $heure', 'Open · closes at $heure');
      case EtatOuverture.fermeBientot:
        return tr('Ferme bientôt · $heure', 'Closing soon · $heure');
      case EtatOuverture.ferme:
        if (heure == null) return tr('Fermé', 'Closed');
        final quand = demain ? ' ${tr('demain', 'tomorrow')}' : jourOuverture == null ? '' : ' ${tr(_joursCourts[jourOuverture!].$1, _joursCourts[jourOuverture!].$2)}';
        return tr('Fermé · ouvre$quand à $heure', 'Closed · opens$quand at $heure');
    }
  }
}

typedef Plage = (int debut, int fin); // minutes depuis minuit ; fin <= debut : traverse minuit

/// Horaires hebdomadaires : index 0 (lundi) à 6 (dimanche). Jour absent = inconnu ; liste vide = fermé.
typedef Semaine = Map<int, List<Plage>>;

const _cles = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];

/// Instant UTC → (jour 0..6, minutes depuis minuit) en Côte d'Ivoire.
(int, int) heureAbidjan(DateTime instant) {
  final u = instant.toUtc();
  return (u.weekday - 1, u.hour * 60 + u.minute);
}

/// Calcule l'état d'ouverture d'un établissement (JSON de l'API) ; null si on ne peut pas l'affirmer.
Ouverture? ouvertureEtablissement(Map<String, dynamic> e, {DateTime? maintenant}) {
  if (e['h24'] == true) return const Ouverture(EtatOuverture.ouvert24h);
  Semaine? semaine;
  final detail = e['horaires_detail'];
  if (detail is Map && detail.isNotEmpty) semaine = semaineDepuisDetail(detail);
  if (semaine == null) {
    final texte = e['horaires'];
    if (texte is String && texte.trim().isNotEmpty) {
      if (_est24h(texte)) return const Ouverture(EtatOuverture.ouvert24h);
      semaine = semaineDepuisTexte(texte);
    }
  }
  if (semaine == null) return null;
  return evaluerOuverture(semaine, maintenant ?? DateTime.now());
}

bool _est24h(String texte) => RegExp(r'^\s*(ouvert\s*)?24\s*h?\s*(/|sur)\s*24(\s*h)?\s*$', caseSensitive: false).hasMatch(texte);

String _sansAccents(String s) {
  const a = 'àâäáãéèêëíìîïóòôöõúùûüçñ';
  const b = 'aaaaaeeeeiiiiooooouuuucn';
  final sb = StringBuffer();
  for (final r in s.toLowerCase().runes) {
    final c = String.fromCharCode(r);
    final i = a.indexOf(c);
    sb.write(i >= 0 ? b[i] : c);
  }
  return sb.toString();
}

/// Détail Premium : {lun: '08:00-20:00', dim: 'Fermé', ...}. Une valeur illisible = jour inconnu.
Semaine? semaineDepuisDetail(Map detail) {
  final semaine = <int, List<Plage>>{};
  for (var i = 0; i < 7; i++) {
    final brut = detail[_cles[i]];
    if (brut == null) continue;
    final plages = _plagesDuJour('$brut');
    if (plages != null) semaine[i] = plages;
  }
  return semaine.isEmpty ? null : semaine;
}

const _heure = r'(\d{1,2})\s*(?:h|:)\s*(\d{2})?';
final _plageRe = RegExp('$_heure\\s*(?:-|a|au)\\s*$_heure');

/// Une valeur de jour : « 08:00-20:00 », « 8h-12h, 14h-18h », « Fermé ». null si illisible.
List<Plage>? _plagesDuJour(String brut) {
  final t = _sansAccents(brut).replaceAll(RegExp('[–—]'), '-').trim();
  if (t.isEmpty) return null;
  if (RegExp(r'^(ferme|closed|fermee?s?)$').hasMatch(t)) return const [];
  final plages = <Plage>[];
  var reste = t;
  for (final m in _plageRe.allMatches(t)) {
    final p = _plage(m);
    if (p == null) return null;
    plages.add(p);
    reste = reste.replaceFirst(m.group(0)!, ' ');
  }
  if (plages.isEmpty) return null;
  if (reste.replaceAll(RegExp(r'[\s,;/&+]|\bet\b'), '').isNotEmpty) return null;
  return plages;
}

Plage? _plage(RegExpMatch m) {
  final h1 = int.parse(m.group(1)!), m1 = int.tryParse(m.group(2) ?? '0') ?? 0;
  final h2 = int.parse(m.group(3)!), m2 = int.tryParse(m.group(4) ?? '0') ?? 0;
  if (h1 > 24 || h2 > 24 || m1 > 59 || m2 > 59) return null;
  final debut = h1 * 60 + m1, fin = h2 * 60 + m2;
  if (debut == fin) return null; // « 8h-8h » : ambigu
  return (debut, fin);
}

const _jourRe = '\\b(lun|mar|mer|jeu|ven|sam|dim)[a-z]*\\.?';
final _jeton = RegExp(
  '(?<jours>$_jourRe(?:\\s*(?:-|a|au)\\s*$_jourRe)?)'
  '|(?<plage>$_heure\\s*(?:-|a|au)\\s*$_heure)'
  '|(?<ferme>\\bferme[es]?\\b|\\bclosed\\b)'
  '|(?<tous>tous\\s+les\\s+jours|7\\s*j?\\s*/\\s*7)',
);

/// Texte libre : uniquement des motifs clairs (« Lun-Sam 8h-20h », « 7h-22h »,
/// « Lun-Ven 8h-17h, Sam 8h-12h »). Tout reste non reconnu → null. Un jour non cité reste inconnu.
Semaine? semaineDepuisTexte(String brut) {
  final t = _sansAccents(brut).replaceAll(RegExp('[–—]'), '-');
  final semaine = <int, List<Plage>>{};
  var jours = <int>{};
  var joursAcquis = false; // des plages ont déjà été rattachées au groupe de jours courant
  var aUnJour = false;
  var trouve = false;
  var reste = t;

  for (final m in _jeton.allMatches(t)) {
    reste = reste.replaceFirst(m.group(0)!, ' ');
    if (m.namedGroup('jours') != null) {
      final cites = _joursCites(m.namedGroup('jours')!);
      if (cites == null) return null;
      if (joursAcquis || !aUnJour) {
        jours = {};
        joursAcquis = false;
      }
      jours.addAll(cites);
      aUnJour = true;
    } else if (m.namedGroup('tous') != null) {
      jours = {0, 1, 2, 3, 4, 5, 6};
      aUnJour = true;
      joursAcquis = false;
    } else if (m.namedGroup('plage') != null) {
      final p = _plage(_plageRe.firstMatch(m.namedGroup('plage')!)!);
      if (p == null) return null;
      final cible = aUnJour ? jours : {0, 1, 2, 3, 4, 5, 6};
      for (final j in cible) {
        (semaine[j] ??= []).add(p);
      }
      joursAcquis = true;
      trouve = true;
    } else if (m.namedGroup('ferme') != null) {
      if (!aUnJour) return null;
      for (final j in jours) {
        semaine[j] = [];
      }
      joursAcquis = true;
      trouve = true;
    }
  }
  if (!trouve) return null;
  final residu = reste.replaceAll(RegExp(r'\b(de|du|au|a|et|les|ouvert|le|la|l)\b'), ' ').replaceAll(RegExp(r'[\s,;:/&+.\-]'), '');
  if (residu.isNotEmpty) return null;
  return semaine;
}

Set<int>? _joursCites(String txt) {
  const noms = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];
  final trouves = RegExp(_jourRe).allMatches(txt).map((m) => noms.indexOf(m.group(1)!)).toList();
  if (trouves.isEmpty) return null;
  if (trouves.length == 1) return {trouves.first};
  final res = <int>{};
  var i = trouves.first;
  while (true) {
    res.add(i);
    if (i == trouves.last) break;
    i = (i + 1) % 7;
  }
  return res;
}

/// Évalue la semaine à l'instant donné. null si le jour concerné est inconnu.
Ouverture? evaluerOuverture(Semaine semaine, DateTime instant) {
  final (jour, minutes) = heureAbidjan(instant);
  final veille = (jour + 6) % 7;

  // Plage de la veille qui déborde après minuit
  for (final (d, f) in semaine[veille] ?? const <Plage>[]) {
    if (f <= d && minutes < f) return _ouvertJusqua(f - minutes, f);
  }
  final auj = semaine[jour];
  if (auj == null) return null;
  for (final (d, f) in auj) {
    final fin = f <= d ? f + 1440 : f;
    if (minutes >= d && minutes < fin) return _ouvertJusqua(fin - minutes, f % 1440);
  }

  // Fermé : prochaine ouverture aujourd'hui, puis dans les 7 jours suivants
  final prochaines = [for (final (d, _) in auj) if (d > minutes) d]..sort();
  if (prochaines.isNotEmpty) return Ouverture(EtatOuverture.ferme, heure: _hhmm(prochaines.first));
  for (var k = 1; k <= 7; k++) {
    final j = (jour + k) % 7;
    final plages = semaine[j];
    if (plages == null) return const Ouverture(EtatOuverture.ferme);
    if (plages.isNotEmpty) {
      final d = ([for (final (d, _) in plages) d]..sort()).first;
      return Ouverture(EtatOuverture.ferme, heure: _hhmm(d), jourOuverture: k == 1 ? null : j, demain: k == 1);
    }
  }
  return const Ouverture(EtatOuverture.ferme);
}

Ouverture _ouvertJusqua(int minutesRestantes, int fin) => Ouverture(
      minutesRestantes <= Ouverture.seuilFermeBientot ? EtatOuverture.fermeBientot : EtatOuverture.ouvert,
      heure: _hhmm(fin % 1440),
    );

String _hhmm(int minutes) => '${(minutes ~/ 60).toString().padLeft(2, '0')}:${(minutes % 60).toString().padLeft(2, '0')}';
