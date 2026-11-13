import 'package:flutter/material.dart';
import '../../core/langue.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../core/cache.dart';
import '../../core/outils.dart';
import '../../core/session.dart';
import '../../widgets/communs.dart';
import '../compte/complices_screen.dart';
import '../compte/compte_screen.dart';
import '../etablissements_screen.dart';
import 'alerte_active_screen.dart';
import 'alerte_recue_screen.dart';
import 'sos_screen.dart';

/// Maquette « Alerte & Urgence » (URG-05 à URG-07). Aucune publicité sur cet écran.
class AlerteScreen extends StatefulWidget {
  const AlerteScreen({super.key});

  @override
  State<AlerteScreen> createState() => _AlerteScreenState();
}

class _AlerteScreenState extends State<AlerteScreen> {
  Map<String, dynamic>? _alerteEnCours;
  List<Map<String, dynamic>> _recues = [];
  int _nbComplices = nombreComplicesAcceptes();
  int _max = 7;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  Future<void> _charger() async {
    if (!Session.instance.connecte) return;
    try {
      final r = await Future.wait([
        Api.instance.get('/alertes'),
        Api.instance.get('/alertes-recues'),
        Api.instance.get('/complices'),
      ]);
      final complices = r[2] as Map;
      await Cache.ecrire('complices', complices['data']);
      final actives = ((r[0] as Map)['data'] as List).cast<Map>().where((a) => a['statut'] == 'active');
      if (!mounted) return;
      setState(() {
        _alerteEnCours = actives.isEmpty ? null : Map<String, dynamic>.from(actives.first);
        _recues = ((r[1] as Map)['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _nbComplices = nombreComplicesAcceptes();
        _max = (complices['max'] as num?)?.toInt() ?? 7;
      });
    } catch (_) {
      // hors ligne : numéros d'urgence et complices en cache
    }
  }

  Future<void> _ouvrir(Widget ecran) async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => ecran));
    _charger();
  }

  /// Regroupe les numéros de même libellé (« Police secours · 110 · 111 · 170 »).
  List<({String libelle, String? description, List<String> numeros})> _numeros() {
    final groupes = <String, ({String libelle, String? description, List<String> numeros})>{};
    for (final n in Session.instance.numerosUrgence) {
      final libelle = '${n['libelle']}';
      final existant = groupes[libelle];
      if (existant == null) {
        groupes[libelle] = (libelle: libelle, description: n['description'] as String?, numeros: ['${n['numero']}']);
      } else {
        existant.numeros.add('${n['numero']}');
      }
    }
    return groupes.values.toList();
  }

  @override
  Widget build(BuildContext context) {
    final connecte = Session.instance.connecte;

    return Scaffold(
      appBar: BarreTitre(tr('Alerte & Urgence', 'Alert & Emergency')),
      body: RefreshIndicator(
        onRefresh: _charger,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            for (final a in _recues)
              Card(
                color: Couleurs.rougeClair,
                child: ListTile(
                  leading: Icon(Icons.warning_amber_rounded, color: Couleurs.encreRouge),
                  title: Text('${a['personne']?['nom']} a besoin d\'aide', style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text('${a['motif_libelle']} · depuis ${formatHeure('${a['started_at']}')}'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _ouvrir(AlerteRecueScreen(alerteId: a['id'] as int)),
                ),
              ),
            if (_alerteEnCours != null)
              Card(
                color: Couleurs.rougeClair,
                child: ListTile(
                  leading: Icon(Icons.sensors, color: Couleurs.encreRouge),
                  title: Text(tr('Votre alerte est en cours', 'Your alert is in progress'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text(tr('Voir le suivi de vos complices', 'View your accomplices\' status')),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _ouvrir(AlerteActiveScreen(alerte: _alerteEnCours!)),
                ),
              ),
            BoutonSos(
              texte: connecte && _nbComplices > 0 ? (_nbComplices > 1 ? tr('SOS · Alerter mes $_nbComplices complices', 'SOS · Alert my $_nbComplices accomplices') : tr('SOS · Alerter mon complice', 'SOS · Alert my accomplice')) : tr('SOS · Alerter mes complices', 'SOS · Alert my accomplices'),
              onPressed: () => _ouvrir(const SosScreen()),
            ),
            TitreSection(tr('Numéros d\'urgence', 'Emergency numbers')),
            for (final n in _numeros())
              Card(
                child: InkWell(
                  onTap: () => appeler(n.numeros.first),
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
                    child: Row(children: [
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(n.libelle, style: const TextStyle(fontWeight: FontWeight.w700)),
                          Text(n.numeros.length > 1 ? n.numeros.join(' · ') : (n.description ?? ''),
                              style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 13)),
                        ]),
                      ),
                      Container(
                        margin: const EdgeInsets.only(left: 10),
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        decoration: BoxDecoration(color: Couleurs.rouge, borderRadius: BorderRadius.circular(99)),
                        child: Row(mainAxisSize: MainAxisSize.min, children: [
                          const Icon(Icons.call_rounded, color: Colors.white, size: 15),
                          const SizedBox(width: 5),
                          Text(n.numeros.first, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
                        ]),
                      ),
                    ]),
                  ),
                ),
              ),
            const TitreSection('Services'),
            LigneMenu(
              badge: Text('$_nbComplices/$_max'),
              titre: tr('Mes complices BRAVIDO', 'My BRAVIDO accomplices'),
              sousTitre: tr('Ils reçoivent votre alerte et votre position', 'They receive your alert and location'),
              onTap: () => _ouvrir(connecte ? const ComplicesScreen() : const CompteScreen()),
            ),
            LigneMenu(
              badge: const Text('180', style: TextStyle(color: Color(0xFF2E9E3E))),
              couleurBadge: const Color(0xFFDDF3E0),
              titre: tr('Centres de secours', 'Rescue centres'),
              sousTitre: tr('GSPM et ONPC les plus proches', 'Closest GSPM and ONPC units'),
              onTap: () => _ouvrir(EtablissementsScreen(
                titre: tr('Centres de secours', 'Rescue centres'),
                categories: ['centre_secours'],
                introduction: tr('Triés du plus proche au plus loin de votre position.', 'Sorted from closest to farthest from your location.'),
              )),
            ),
            LigneMenu(
              badge: const Text('FGA', style: TextStyle(color: Color(0xFF9A7400))),
              couleurBadge: const Color(0xFFFFF1C2),
              titre: tr('Accident de la route : FGA', 'Road accident: FGA'),
              sousTitre: tr('Prise en charge gratuite des accidentés', 'Free care for accident victims'),
              onTap: () => _ouvrir(EtablissementsScreen(
                titre: tr('Bureaux FGA', 'FGA offices'),
                categories: ['bureau_fga'],
                introduction: tr('Le Fonds de Garantie Automobile prend en charge les frais médicaux des victimes '
                    'd\'accidents de la route dans les hôpitaux partenaires. En cas d\'accident, appelez d\'abord le 185 ou le 180.',
                    'The Automobile Guarantee Fund covers the medical costs of road accident victims '
                    'in partner hospitals. In case of an accident, call 185 or 180 first.'),
              )),
            ),
          ],
        ),
      ),
    );
  }
}
