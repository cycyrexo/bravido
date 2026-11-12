import 'dart:async';

import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../core/outils.dart';
import '../../widgets/carte.dart';
import '../../widgets/communs.dart';

/// Vue du complice : position de la personne en danger et réponse (URG-04).
class AlerteRecueScreen extends StatefulWidget {
  const AlerteRecueScreen({super.key, required this.alerteId});

  final int alerteId;

  @override
  State<AlerteRecueScreen> createState() => _AlerteRecueScreenState();
}

class _AlerteRecueScreenState extends State<AlerteRecueScreen> {
  Map<String, dynamic>? _alerte;
  String? _erreur;
  Timer? _minuteur;
  String? _maReponse;

  @override
  void initState() {
    super.initState();
    _charger();
    Api.instance.post('/alertes/${widget.alerteId}/vu').catchError((_) => null);
    _minuteur = Timer.periodic(const Duration(seconds: 15), (_) => _charger());
  }

  @override
  void dispose() {
    _minuteur?.cancel();
    super.dispose();
  }

  Future<void> _charger() async {
    try {
      final r = await Api.instance.get('/alertes/${widget.alerteId}');
      if (!mounted) return;
      setState(() {
        _alerte = Map<String, dynamic>.from((r as Map)['data'] as Map);
        _erreur = null;
      });
      if (_alerte!['statut'] != 'active') _minuteur?.cancel();
    } on ApiException catch (e) {
      if (mounted) setState(() => _erreur = e.message);
    }
  }

  Future<void> _repondre(String reponse) async {
    try {
      await Api.instance.post('/alertes/${widget.alerteId}/reponse', {'reponse': reponse});
      if (!mounted) return;
      setState(() => _maReponse = reponse);
      afficherMessage(context, 'Votre réponse a été transmise.');
      if (reponse == 'appelle_secours') await appeler('185');
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final a = _alerte;
    final active = a?['statut'] == 'active';
    final personne = a?['personne'] as Map?;
    final position = a?['position'] as Map?;

    return Scaffold(
      appBar: BarreTitre(active ? 'Alerte d\'un proche' : 'Alerte', couleur: active ? Couleurs.rouge : null),
      body: a == null
          ? (_erreur != null ? MessageVide(_erreur!, onReessayer: _charger) : const Chargement())
          : ListView(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
              children: [
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), boxShadow: Couleurs.ombre),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Avatar(
                      personne?['nom'] as String?,
                      taille: 52,
                      couleur: active ? Couleurs.rougeFonce : Couleurs.vert,
                      fond: active ? Couleurs.rougeClair : Couleurs.vertClair,
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(
                          active ? '${personne?['nom']} a besoin d\'aide' : 'Alerte terminée',
                          style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800, height: 1.2, letterSpacing: -0.2),
                        ),
                        const SizedBox(height: 8),
                        Wrap(spacing: 6, runSpacing: 6, children: [
                          Pastille.rouge('${a['motif_libelle']}'),
                          Pastille.grise('Depuis ${formatHeure('${a['started_at']}')}'),
                          if (personne?['groupe_sanguin'] != null) Pastille.orange('Groupe ${personne!['groupe_sanguin']}'),
                        ]),
                      ]),
                    ),
                  ]),
                ),
                const SizedBox(height: 14),
                if (active && position != null) ...[
                  CartePoint(
                    lat: (position['lat'] as num).toDouble(),
                    lng: (position['lng'] as num).toDouble(),
                    hauteur: 260,
                    couleur: Couleurs.rouge,
                  ),
                  const SizedBox(height: 8),
                  Row(children: [
                    const Icon(Icons.my_location_rounded, size: 15, color: Couleurs.texteSecondaire),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(
                        'Position de ${formatHeure('${position['recorded_at']}')}'
                        '${position['precision_m'] != null ? ' (± ${position['precision_m']} m)' : ''}',
                        style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 13),
                      ),
                    ),
                  ]),
                ] else if (active)
                  const Bandeau('Position pas encore disponible.', icone: Icons.location_off),
                if (!active) const Info('Cette personne a clôturé l\'alerte. Sa position n\'est plus partagée.'),
                const SizedBox(height: 16),
                if (active) ...[
                  Row(children: [
                    Expanded(
                      child: FilledButton.icon(
                        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52), padding: const EdgeInsets.symmetric(horizontal: 10)),
                        onPressed: _maReponse == 'j_arrive' ? null : () => _repondre('j_arrive'),
                        icon: const Icon(Icons.directions_run_rounded, size: 19),
                        label: const Text('J\'arrive'),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: FilledButton.icon(
                        style: FilledButton.styleFrom(
                          backgroundColor: Couleurs.rouge,
                          minimumSize: const Size.fromHeight(52),
                          padding: const EdgeInsets.symmetric(horizontal: 10),
                        ),
                        onPressed: () => _repondre('appelle_secours'),
                        icon: const Icon(Icons.call_rounded, size: 19),
                        label: const FittedBox(fit: BoxFit.scaleDown, child: Text('Appeler les secours')),
                      ),
                    ),
                  ]),
                  const SizedBox(height: 10),
                  if (position != null)
                    FilledButton.tonalIcon(
                      style: FilledButton.styleFrom(
                        backgroundColor: Couleurs.vertClair,
                        foregroundColor: const Color(0xFF146A66),
                        minimumSize: const Size.fromHeight(48),
                      ),
                      onPressed: () => itineraire(position['lat'] as num, position['lng'] as num),
                      icon: const Icon(Icons.directions_rounded, size: 19),
                      label: const Text('Itinéraire'),
                    ),
                  const SizedBox(height: 8),
                ],
                if (personne != null && personne['telephone'] != null)
                  OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48), backgroundColor: Colors.white),
                    onPressed: () => appeler('${personne['telephone']}'),
                    icon: const Icon(Icons.call_rounded, size: 19),
                    label: Text('Appeler ${personne['nom']}'),
                  ),
              ],
            ),
    );
  }
}
