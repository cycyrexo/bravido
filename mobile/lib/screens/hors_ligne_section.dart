import 'package:flutter/material.dart';

import '../app.dart';
import '../core/hors_ligne.dart';
import '../core/langue.dart';
import '../core/session.dart';
import '../widgets/bandeau_hors_ligne.dart';
import '../widgets/communs.dart';

String tailleLisible(int octets) {
  if (octets < 1024 * 1024) return '${(octets / 1024).ceil()} ${tr('Ko', 'KB')}';
  return '${(octets / (1024 * 1024)).toStringAsFixed(1).replaceAll('.', tr(',', '.'))} ${tr('Mo', 'MB')}';
}

/// F20 : section « Utiliser hors ligne » des Réglages : télécharger / mettre à jour / supprimer une ville.
class SectionHorsLigne extends StatefulWidget {
  const SectionHorsLigne({super.key});

  @override
  State<SectionHorsLigne> createState() => _SectionHorsLigneState();
}

class _SectionHorsLigneState extends State<SectionHorsLigne> {
  final _hl = HorsLigne.instance;
  int? _autre; // ville choisie dans la liste déroulante

  @override
  void initState() {
    super.initState();
    _hl.addListener(_maj);
    _hl.charger();
  }

  @override
  void dispose() {
    _hl.removeListener(_maj);
    super.dispose();
  }

  void _maj() {
    if (mounted) setState(() {});
  }

  Future<void> _telecharger(int id, String nom) async {
    final avait = _hl.ville(id);
    final ok = await _hl.telecharger(id, nom);
    if (!mounted) return;
    final texte = _hl.erreur != null
        ? _hl.erreur!
        : ok
            ? tr('$nom est disponible hors ligne.', '$nom is now available offline.')
            : tr('Données déjà à jour.', 'Data already up to date.');
    if (avait == null && !ok && _hl.erreur == null) return;
    afficherMessage(context, texte);
  }

  Future<void> _supprimer(VilleHorsLigne v) async {
    final oui = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(tr('Supprimer les données de ${v.nom} ?', 'Delete the data for ${v.nom}?')),
        content: Text(tr('Vous pourrez les télécharger à nouveau avec une connexion Internet.', 'You can download them again with an Internet connection.')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler', 'Cancel'))),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Supprimer', 'Delete'))),
        ],
      ),
    );
    if (oui == true) await _hl.supprimer(v.id);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final villes = Session.instance.villes;
    final courante = Session.instance.ville;
    final telechargees = _hl.villes;
    final choix = _autre != null ? villes.where((v) => v['id'] == _autre).firstOrNull : null;
    final cible = choix ?? courante;

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      TitreSection(tr('Utiliser hors ligne', 'Use offline')),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(
              tr('Téléchargez une ville pour consulter les établissements, la recherche, les fiches et les pharmacies de garde sans connexion.',
                  'Download a city to browse facilities, search, profiles and on-duty pharmacies without a connection.'),
              style: TextStyle(color: theme.colorScheme.onSurfaceVariant, fontSize: 13, height: 1.35),
            ),
            if (villes.length > 1) ...[
              const SizedBox(height: 10),
              DropdownButtonFormField<int>(
                initialValue: cible?['id'] as int?,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Ville', 'City')),
                items: [for (final v in villes) DropdownMenuItem(value: v['id'] as int, child: Text('${v['nom']}'))],
                onChanged: _hl.telechargementVille != null ? null : (v) => setState(() => _autre = v),
              ),
            ],
            const SizedBox(height: 12),
            if (cible == null)
              Text(tr('Connectez-vous à Internet pour charger la liste des villes.', 'Connect to the Internet to load the list of cities.'),
                  style: TextStyle(color: theme.colorScheme.onSurfaceVariant, fontSize: 13))
            else
              _actionVille(cible),
            if (_hl.telechargementVille != null) ...[
              const SizedBox(height: 12),
              LinearProgressIndicator(value: _hl.progression == 0 ? null : _hl.progression, minHeight: 6, borderRadius: BorderRadius.circular(4)),
              const SizedBox(height: 4),
              Text(tr('Téléchargement… ${(_hl.progression * 100).round()} %', 'Downloading… ${(_hl.progression * 100).round()}%'),
                  style: TextStyle(color: theme.colorScheme.onSurfaceVariant, fontSize: 12)),
            ],
            const SizedBox(height: 10),
            Text(
              tr('Les fonds de carte ne sont pas enregistrés : la carte nécessite une connexion. Les numéros d\'urgence et vos complices restent disponibles hors ligne.',
                  'Map tiles are not saved: the map needs a connection. Emergency numbers and your accomplices stay available offline.'),
              style: TextStyle(color: theme.colorScheme.onSurfaceVariant, fontSize: 12, height: 1.35),
            ),
          ]),
        ),
      ),
      if (telechargees.isNotEmpty) ...[
        const SizedBox(height: 8),
        for (final v in telechargees)
          Card(
            child: ListTile(
              leading: const Icon(Icons.download_done_rounded, color: Couleurs.vert),
              title: Text(v.nom, style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(tr('${v.nombre} établissements · ${tailleLisible(v.octets)} · données du ${BandeauHorsLigne.formater(v.date)}',
                  '${v.nombre} facilities · ${tailleLisible(v.octets)} · data from ${BandeauHorsLigne.formater(v.date)}')),
              trailing: IconButton(
                tooltip: tr('Supprimer', 'Delete'),
                icon: const Icon(Icons.delete_outline_rounded),
                onPressed: _hl.telechargementVille != null ? null : () => _supprimer(v),
              ),
            ),
          ),
        Padding(
          padding: const EdgeInsets.only(left: 2, top: 2),
          child: Text(tr('Espace utilisé : ${tailleLisible(_hl.octetsTotal)}', 'Space used: ${tailleLisible(_hl.octetsTotal)}'),
              style: TextStyle(color: theme.colorScheme.onSurfaceVariant, fontSize: 12)),
        ),
      ],
    ]);
  }

  Widget _actionVille(Map<String, dynamic> ville) {
    final id = ville['id'] as int;
    final nom = '${ville['nom']}';
    final existante = _hl.ville(id);
    final occupe = _hl.telechargementVille != null;
    if (existante == null) {
      return SizedBox(
        width: double.infinity,
        child: FilledButton.icon(
          onPressed: occupe ? null : () => _telecharger(id, nom),
          icon: const Icon(Icons.download_rounded),
          label: Text(tr('Télécharger $nom pour l\'utiliser hors ligne', 'Download $nom to use it offline')),
        ),
      );
    }
    return SizedBox(
      width: double.infinity,
      child: OutlinedButton.icon(
        onPressed: occupe ? null : () => _telecharger(id, nom),
        icon: const Icon(Icons.refresh_rounded),
        label: Text(tr('Mettre à jour $nom', 'Update $nom')),
      ),
    );
  }
}
