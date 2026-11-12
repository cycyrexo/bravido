import 'package:flutter/material.dart';

import '../app.dart';
import '../core/api.dart';
import '../core/cache.dart';
import '../core/outils.dart';
import '../core/session.dart';
import '../widgets/carte.dart';
import '../widgets/communs.dart';
import 'compte/compte_screen.dart';
import 'compte/fiches_screen.dart';

/// Maquette « Fiche pharmacie » : panneau qui monte du bas (PHA-03, HOP-03).
Future<void> ouvrirFiche(BuildContext context, Map<String, dynamic> apercu) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    backgroundColor: Colors.white,
    shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
    builder: (_) => DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.72,
      maxChildSize: 0.95,
      minChildSize: 0.4,
      builder: (context, defilement) => FicheEtablissement(apercu: apercu, defilement: defilement),
    ),
  );
}

class FicheEtablissement extends StatefulWidget {
  const FicheEtablissement({super.key, required this.apercu, this.defilement});

  final Map<String, dynamic> apercu;
  final ScrollController? defilement;

  @override
  State<FicheEtablissement> createState() => _FicheEtablissementState();
}

class _FicheEtablissementState extends State<FicheEtablissement> {
  late Map<String, dynamic> _e = widget.apercu;
  bool _complet = false;
  late bool _favori = _favoris().contains(_id);

  int get _id => widget.apercu['id'] as int;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  List<int> _favoris() => (Cache.lireListe('favoris_ids') ?? const []).whereType<int>().toList();

  Future<void> _charger() async {
    try {
      final r = await Api.instance.get('/etablissements/$_id');
      if (!mounted) return;
      setState(() {
        // On garde la distance et la garde calculées par la liste
        _e = {...Map<String, dynamic>.from((r as Map)['data'] as Map), 'distance_m': widget.apercu['distance_m'], 'position_approx': (r['data'] as Map)['position_approx'] ?? widget.apercu['position_approx'], 'garde': widget.apercu['garde'] ?? (r['data'] as Map)['garde']};
        _complet = true;
      });
    } catch (_) {
      // hors ligne : on affiche l'aperçu de la liste
    }
  }

  Future<void> _basculerFavori() async {
    final ids = _favoris();
    try {
      if (_favori) {
        await Api.instance.delete('/favoris/$_id');
        ids.remove(_id);
      } else {
        await Api.instance.post('/favoris', {'etablissement_id': _id});
        ids.add(_id);
      }
      await Cache.ecrire('favoris_ids', ids);
      if (mounted) setState(() => _favori = !_favori);
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    }
  }

  Future<void> _signalerErreur() async {
    if (!Session.instance.connecte) {
      final navigateur = Navigator.of(context);
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Connectez-vous pour signaler une erreur.')));
      navigateur.pop();
      navigateur.push(MaterialPageRoute(builder: (_) => const CompteScreen()));
      return;
    }
    final controleur = TextEditingController();
    final texte = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Signaler une erreur'),
        content: TextField(
          controller: controleur,
          maxLines: 4,
          maxLength: 2000,
          decoration: const InputDecoration(hintText: 'Ex. : numéro erroné, établissement fermé…'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Annuler')),
          FilledButton(onPressed: () => Navigator.pop(context, controleur.text.trim()), child: const Text('Envoyer')),
        ],
      ),
    );
    if (texte == null || texte.length < 5) return;
    try {
      await Api.instance.post('/messages', {'type': 'signalement', 'etablissement_id': _id, 'texte': texte});
      if (mounted) afficherMessage(context, 'Merci ! Notre équipe va vérifier.');
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final e = _e;
    final garde = e['garde'] as Map?;
    final gardes = ((e['gardes'] as List?) ?? const []).cast<Map>();
    final periodeGarde = garde ?? (gardes.isNotEmpty ? gardes.first : null);
    final assurances = ((e['assurances'] as List?) ?? const []).map((a) => '${(a as Map)['nom']}').toList();
    final services = ((e['services'] as List?) ?? const []).map((s) => '$s').toList();
    final telephones = [e['tel1'], e['tel2']].whereType<String>().where((t) => t.trim().isNotEmpty).toList();
    final telAutres = _texte(e['tel_autres'] is List ? (e['tel_autres'] as List).join(' · ') : e['tel_autres']);
    final email = _texte(e['email']);
    final siteWeb = _texte(e['site_web']);
    final responsable = _texte(e['responsable']);
    final approx = e['position_approx'] == true;
    final aPosition = e['lat'] != null && e['lng'] != null;

    final lieu = [e['commune'], e['ville']].whereType<String>().join(', ');
    final adresse = [e['adresse'], if (e['repere'] != null) 'Repère : ${e['repere']}'].whereType<String>().join(' — ');

    return ListView(
      controller: widget.defilement,
      padding: const EdgeInsets.fromLTRB(18, 0, 18, 24),
      children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          IconeCategorie(e['categorie'] is Map ? '${e['categorie']['code']}' : null, taille: 52),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${e['nom']}', style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800, height: 1.2, letterSpacing: -0.2)),
              if (lieu.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 3),
                  child: Text(lieu, style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 13.5)),
                ),
            ]),
          ),
          if (Session.instance.connecte)
            IconButton(
              tooltip: _favori ? 'Retirer des favoris' : 'Ajouter aux favoris',
              icon: Icon(_favori ? Icons.star_rounded : Icons.star_border_rounded, color: Couleurs.orange, size: 28),
              onPressed: _basculerFavori,
            ),
        ]),
        const SizedBox(height: 10),
        Wrap(spacing: 6, runSpacing: 6, children: [
          if (approx)
            const Pastille.orange('Position approximative')
          else if (e['distance_m'] != null)
            Pastille.verte('À ${formatDistance(e['distance_m'] as num)}'),
          if (periodeGarde != null) Pastille.verte('De garde du ${_jourMois(periodeGarde['du'])} au ${_jourMois(periodeGarde['au'])}'),
          if (e['h24'] == true) const Pastille.orange('24h/24'),
          if (e['categorie'] is Map && periodeGarde == null) Pastille.grise('${e['categorie']['libelle']}'),
        ]),
        const SizedBox(height: 14),
        if (aPosition) CartePoint(lat: (e['lat'] as num).toDouble(), lng: (e['lng'] as num).toDouble(), hauteur: 150),
        if (aPosition && approx)
          const Padding(
            padding: EdgeInsets.only(top: 6),
            child: Row(children: [
              Icon(Icons.info_outline_rounded, size: 14, color: Color(0xFFA84300)),
              SizedBox(width: 5),
              Expanded(
                child: Text('Position indicative : centre de la commune', style: TextStyle(color: Color(0xFFA84300), fontSize: 12.5)),
              ),
            ]),
          ),
        const SizedBox(height: 10),
        _Ligne(Icons.call_outlined, 'Téléphone', telephones.isEmpty ? null : telephones.join(' · ')),
        _Ligne(Icons.phone_in_talk_outlined, 'Autres n°', telAutres),
        _Ligne(Icons.place_outlined, 'Adresse', adresse),
        _Ligne(Icons.schedule_rounded, 'Horaires', e['h24'] == true ? '24h/24' : e['horaires'] as String?),
        if (services.isNotEmpty) _Ligne(Icons.medical_services_outlined, 'Services', services.join(', ')),
        _Ligne(Icons.verified_user_outlined, 'Assurances', _complet ? (assurances.isEmpty ? 'Non renseignées' : assurances.join(', ')) : '…'),
        _Ligne(Icons.person_outline_rounded, 'Responsable', responsable),
        _Ligne(Icons.mail_outline_rounded, 'E-mail', email, onTap: email == null ? null : () => ecrireEmail(email)),
        _Ligne(Icons.language_rounded, 'Site web', siteWeb,
            onTap: siteWeb == null ? null : () => ouvrirLien(siteWeb.startsWith('http') ? siteWeb : 'https://$siteWeb')),
        _Ligne(Icons.event_available_outlined, 'Vérifié le', e['verifie_at'] == null ? 'Non vérifié' : formatDate('${e['verifie_at']}')),
        if (e['description'] != null) _Ligne(Icons.notes_rounded, 'Infos', '${e['description']}'),
        const SizedBox(height: 16),
        Row(children: [
          if (telephones.isNotEmpty) ...[
            Expanded(
              child: FilledButton.icon(
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                onPressed: () => appeler(telephones.first),
                icon: const Icon(Icons.call_rounded, size: 19),
                label: const Text('Appeler'),
              ),
            ),
            const SizedBox(width: 12),
          ],
          Expanded(
            child: FilledButton.tonalIcon(
              style: FilledButton.styleFrom(
                backgroundColor: Couleurs.vertClair,
                foregroundColor: const Color(0xFF146A66),
                minimumSize: const Size.fromHeight(50),
              ),
              onPressed: () => approx || !aPosition
                  ? rechercherSurCarte([e['nom'], e['commune'], e['ville']].whereType<String>().join(', '))
                  : itineraire(e['lat'] as num, e['lng'] as num),
              icon: const Icon(Icons.directions_rounded, size: 19),
              label: const Text('Itinéraire'),
            ),
          ),
        ]),
        const SizedBox(height: 6),
        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          TextButton.icon(
            style: TextButton.styleFrom(foregroundColor: Couleurs.texteSecondaire, minimumSize: const Size(0, 46)),
            onPressed: _signalerErreur,
            icon: const Icon(Icons.flag_outlined, size: 18),
            label: const Text('Signaler une erreur'),
          ),
          TextButton(
            style: TextButton.styleFrom(foregroundColor: Couleurs.texteSecondaire, minimumSize: const Size(0, 46)),
            onPressed: () => Navigator.pop(context),
            child: const Text('Fermer'),
          ),
        ]),
        if (Session.instance.connecte && _complet)
          TextButton(
            style: TextButton.styleFrom(minimumSize: const Size(0, 46)),
            onPressed: () {
              final navigateur = Navigator.of(context);
              navigateur.pop();
              navigateur.push(MaterialPageRoute(builder: (_) => FicheFormScreen(etablissement: e)));
            },
            child: const Text('Je gère cet établissement : proposer une correction', textAlign: TextAlign.center),
          ),
      ],
    );
  }

  /// Champ texte facultatif de l'API : null si absent ou vide.
  String? _texte(dynamic v) {
    final t = v == null ? '' : '$v'.trim();
    return t.isEmpty ? null : t;
  }

  String _jourMois(dynamic date) {
    final d = DateTime.tryParse('$date');
    return d == null ? '' : '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}';
  }
}

class _Ligne extends StatelessWidget {
  const _Ligne(this.icone, this.libelle, this.valeur, {this.onTap});

  final IconData icone;
  final String libelle;
  final String? valeur;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    if (valeur == null || valeur!.isEmpty) return const SizedBox.shrink();
    final ligne = Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(
          padding: const EdgeInsets.only(top: 1),
          child: Icon(icone, size: 18, color: Couleurs.sarcelle),
        ),
        const SizedBox(width: 10),
        SizedBox(width: 92, child: Text(libelle, style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 13.5))),
        Expanded(
          child: Text(valeur!,
              style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14, height: 1.3, color: onTap == null ? Couleurs.texte : Couleurs.vert)),
        ),
      ]),
    );
    if (onTap == null) return ligne;
    return InkWell(
      borderRadius: BorderRadius.circular(8),
      onTap: onTap,
      child: ConstrainedBox(constraints: const BoxConstraints(minHeight: 44), child: Align(alignment: Alignment.centerLeft, child: ligne)),
    );
  }
}
