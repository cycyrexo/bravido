import 'package:share_plus/share_plus.dart';

import 'langue.dart';

/// F16 — Partage d'une fiche par la feuille de partage du système (WhatsApp, SMS, e-mail…).

/// Lien Google Maps : position précise → coordonnées ; position approximative → recherche par nom + commune.
String lienCarte(Map<String, dynamic> e) {
  final precise = e['position_approx'] != true && e['lat'] != null && e['lng'] != null;
  final requete = precise
      ? '${e['lat']},${e['lng']}'
      : [e['nom'], e['commune'], e['ville']].whereType<String>().where((s) => s.trim().isNotEmpty).join(', ');
  return Uri.https('www.google.com', '/maps/search/', {'api': '1', 'query': requete}).toString();
}

String _jm(dynamic date) {
  final d = DateTime.tryParse('$date');
  return d == null ? '' : '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}';
}

/// Texte du message. [garde] : période de garde en cours ({du, au}) si la pharmacie est de garde.
String messagePartage(Map<String, dynamic> e, {Map? garde}) {
  String? texte(dynamic v) {
    final t = v == null ? '' : '$v'.trim();
    return t.isEmpty ? null : t;
  }

  final categorie = e['categorie'] is Map ? texte((e['categorie'] as Map)['libelle']) : null;
  final lieu = [texte(e['commune']), texte(e['ville'])].whereType<String>().toList();
  if (lieu.length == 2 && lieu[0] == lieu[1]) lieu.removeLast();
  final tels = [texte(e['tel1']), texte(e['tel2'])].whereType<String>().toList();
  final adresse = texte(e['adresse']);
  final repere = texte(e['repere']);

  final l = <String>[];
  l.add('*${texte(e['nom']) ?? ''}*${categorie == null ? '' : ' ($categorie)'}');
  if (garde != null && garde['du'] != null) {
    l.add(tr('Pharmacie de garde du ${_jm(garde['du'])} au ${_jm(garde['au'])}', 'On-duty pharmacy from ${_jm(garde['du'])} to ${_jm(garde['au'])}'));
  }
  if (e['h24'] == true) l.add(tr('Ouvert 24h/24', 'Open 24 hours'));
  if (adresse != null) l.add(tr('Adresse : $adresse', 'Address: $adresse'));
  if (repere != null) l.add(tr('Repère : $repere', 'Landmark: $repere'));
  if (lieu.isNotEmpty) l.add(lieu.join(', '));
  if (tels.isNotEmpty) l.add(tr('Tél : ${tels.join(' / ')}', 'Phone: ${tels.join(' / ')}'));
  l.add(tr('Itinéraire : ${lienCarte(e)}', 'Directions: ${lienCarte(e)}'));
  l.add('');
  l.add(tr('via BRAVIDO', 'via BRAVIDO'));
  return l.join('\n');
}

Future<void> partagerEtablissement(Map<String, dynamic> e, {Map? garde}) {
  return SharePlus.instance.share(ShareParams(text: messagePartage(e, garde: garde), subject: '${e['nom'] ?? 'BRAVIDO'}'));
}
