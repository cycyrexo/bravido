// F14 — Rappels locaux (médicaments, rendez-vous). Rien n'est envoyé au serveur.
// Ce fichier ne contient que le modèle et le calcul des échéances (pur, testable) ;
// la programmation des notifications est dans `notifications_rappels.dart`.
// Heure de référence : Côte d'Ivoire (Africa/Abidjan = UTC+0) ; les heures saisies sont celles du mur.

enum TypeRappel { medicament, rendezvous }

enum Repetition { aucune, quotidien, hebdo }

class Rappel {
  Rappel({
    required this.id,
    required this.titre,
    this.note = '',
    this.type = TypeRappel.medicament,
    required this.heures,
    required this.jour,
    this.repetition = Repetition.quotidien,
    this.avantMinutes = 0,
    this.actif = true,
  });

  /// Identifiant stable (sert à dériver les identifiants de notification).
  final int id;
  final String titre;
  final String note;
  final TypeRappel type;

  /// Heures du jour au format « HH:mm » (un rendez-vous n'en a qu'une).
  final List<String> heures;

  /// Premier jour (yyyy-MM-dd) ; pour « hebdo », le jour de la semaine de cette date est utilisé.
  final String jour;
  final Repetition repetition;

  /// Rendez-vous : prévenir N minutes avant (0, 60, 1440…).
  final int avantMinutes;
  final bool actif;

  Rappel copieAvec({String? titre, String? note, TypeRappel? type, List<String>? heures, String? jour, Repetition? repetition, int? avantMinutes, bool? actif}) => Rappel(
        id: id,
        titre: titre ?? this.titre,
        note: note ?? this.note,
        type: type ?? this.type,
        heures: heures ?? this.heures,
        jour: jour ?? this.jour,
        repetition: repetition ?? this.repetition,
        avantMinutes: avantMinutes ?? this.avantMinutes,
        actif: actif ?? this.actif,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'titre': titre,
        'note': note,
        'type': type.name,
        'heures': heures,
        'jour': jour,
        'repetition': repetition.name,
        'avant': avantMinutes,
        'actif': actif,
      };

  static Rappel? depuisJson(Object? j) {
    if (j is! Map) return null;
    try {
      final heures = (j['heures'] as List).map((e) => '$e').where((h) => minutesDe(h) != null).toList();
      if (heures.isEmpty || DateTime.tryParse('${j['jour']}') == null) return null;
      return Rappel(
        id: (j['id'] as num).toInt(),
        titre: '${j['titre']}',
        note: '${j['note'] ?? ''}',
        type: TypeRappel.values.firstWhere((t) => t.name == j['type'], orElse: () => TypeRappel.medicament),
        heures: heures,
        jour: '${j['jour']}',
        repetition: Repetition.values.firstWhere((t) => t.name == j['repetition'], orElse: () => Repetition.aucune),
        avantMinutes: (j['avant'] as num?)?.toInt() ?? 0,
        actif: j['actif'] != false,
      );
    } catch (_) {
      return null;
    }
  }
}

/// « 08:30 » → minutes depuis minuit ; null si invalide.
int? minutesDe(String hhmm) {
  final m = RegExp(r'^(\d{1,2}):(\d{2})$').firstMatch(hhmm.trim());
  if (m == null) return null;
  final h = int.parse(m.group(1)!), mn = int.parse(m.group(2)!);
  return h > 23 || mn > 59 ? null : h * 60 + mn;
}

String formatJour(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

/// Échéance de notification pour une heure donnée à un jour donné (« mur » Abidjan, exprimé en UTC),
/// en retirant le préavis (rendez-vous).
DateTime _echeance(DateTime jour, int minutes, int avant) =>
    DateTime.utc(jour.year, jour.month, jour.day).add(Duration(minutes: minutes - avant));

/// Prochaines échéances (au plus [max]) strictement après [maintenant], triées. Vide si inactif ou terminé.
List<DateTime> prochainesEcheances(Rappel r, DateTime maintenant, {int max = 1}) {
  if (!r.actif) return const [];
  final now = maintenant.toUtc();
  final debut0 = DateTime.tryParse(r.jour);
  if (debut0 == null) return const [];
  final debut = DateTime.utc(debut0.year, debut0.month, debut0.day);
  final minutes = r.heures.map(minutesDe).whereType<int>().toList()..sort();
  if (minutes.isEmpty) return const [];

  final res = <DateTime>[];
  switch (r.repetition) {
    case Repetition.aucune:
      for (final m in minutes) {
        final e = _echeance(debut, m, r.avantMinutes);
        if (e.isAfter(now)) res.add(e);
      }
    case Repetition.quotidien:
    case Repetition.hebdo:
      final pas = r.repetition == Repetition.quotidien ? 1 : 7;
      // Premier jour candidat : le dernier jour de série ≤ aujourd'hui − 1 (préavis éventuel), au plus tôt le début
      var jour = debut;
      final ecartJours = DateTime.utc(now.year, now.month, now.day).difference(debut).inDays - 2;
      if (ecartJours > 0) jour = debut.add(Duration(days: (ecartJours ~/ pas) * pas));
      for (var n = 0; n < 400 && res.length < max; n++, jour = jour.add(Duration(days: pas))) {
        final duJour = [for (final m in minutes) _echeance(jour, m, r.avantMinutes)].where((e) => e.isAfter(now)).toList()..sort();
        res.addAll(duJour);
      }
  }
  res.sort();
  return res.take(max).toList();
}

/// Libellé court d'une échéance (« Aujourd'hui 08:00 », « Demain 20:30 », « 15/11 09:00 »).
String libelleEcheance(DateTime e, DateTime maintenant, {required String aujourdhui, required String demain}) {
  final n = maintenant.toUtc();
  final jourN = DateTime.utc(n.year, n.month, n.day);
  final jourE = DateTime.utc(e.year, e.month, e.day);
  final hh = '${e.hour.toString().padLeft(2, '0')}:${e.minute.toString().padLeft(2, '0')}';
  final ecart = jourE.difference(jourN).inDays;
  if (ecart == 0) return '$aujourdhui $hh';
  if (ecart == 1) return '$demain $hh';
  return '${e.day.toString().padLeft(2, '0')}/${e.month.toString().padLeft(2, '0')} $hh';
}
