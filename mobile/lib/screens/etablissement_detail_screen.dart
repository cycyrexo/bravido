import 'package:flutter/material.dart';

import '../app.dart';
import '../core/api.dart';
import '../core/cache.dart';
import '../core/langue.dart';
import '../core/ouverture.dart';
import '../core/partage.dart';
import '../core/outils.dart';
import '../core/session.dart';
import '../widgets/carte.dart';
import '../widgets/communs.dart';
import 'avis_widgets.dart';
import 'compte/compte_screen.dart';
import 'compte/fiches_screen.dart';
import 'compte/mon_etablissement_screen.dart';

/// Maquette « Fiche pharmacie » : panneau qui monte du bas (PHA-03, HOP-03).
Future<void> ouvrirFiche(BuildContext context, Map<String, dynamic> apercu) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    backgroundColor: Couleurs.surface,
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
  bool _positionConfirmee = false;
  bool _estResponsable = false;
  late bool _favori = _favoris().contains(_id);

  int get _id => widget.apercu['id'] as int;

  @override
  void initState() {
    super.initState();
    _charger();
    _verifierResponsable();
    _evenement('vue');
  }

  /// F17 : compteur anonyme (vue / appel / itinéraire), sans identifiant ; les erreurs sont ignorées.
  void _evenement(String type) {
    Api.instance.post('/etablissements/$_id/evenements', {'type': type}).catchError((_) => null);
  }

  /// F18 : l'utilisateur gère-t-il cet établissement ? (bouton « Gérer mon établissement »)
  Future<void> _verifierResponsable() async {
    if (!Session.instance.connecte) return;
    try {
      final r = await Api.instance.get('/mon-etablissement') as Map;
      final ids = (r['data'] as List).map((e) => (e as Map)['id']);
      if (mounted) setState(() => _estResponsable = ids.contains(_id));
    } catch (_) {}
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

  /// F2 : l'utilisateur présent sur place propose la position GPS de l'établissement (modérée au back-office).
  Future<void> _confirmerPosition() async {
    if (!Session.instance.connecte) {
      final navigateur = Navigator.of(context);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(tr('Connectez-vous pour confirmer la position.', 'Sign in to confirm the position.'))));
      navigateur.pop();
      navigateur.push(MaterialPageRoute(builder: (_) => const CompteScreen()));
      return;
    }
    final accord = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(tr('Je suis sur place', 'I am on site')),
        content: Text(tr('Votre position actuelle sera envoyée une seule fois pour corriger la fiche.', 'Your current location will be sent once to correct the profile.')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Annuler', 'Cancel'))),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(tr('Confirmer', 'Confirm'))),
        ],
      ),
    );
    if (accord != true || !mounted) return;
    afficherMessage(context, tr('Recherche de votre position…', 'Finding your location…'));
    final p = await Localisation.position(delai: const Duration(seconds: 15));
    if (!mounted) return;
    if (p == null || DateTime.now().difference(p.timestamp).inMinutes > 2) {
      afficherMessage(context, tr('Position indisponible. Activez la localisation et réessayez en extérieur.', 'Location unavailable. Turn on location and try again outdoors.'));
      return;
    }
    if (p.accuracy > 50) {
      afficherMessage(context, tr('Signal GPS trop imprécis (${p.accuracy.round()} m). Placez-vous en extérieur et réessayez.', 'GPS signal too inaccurate (${p.accuracy.round()} m). Move outdoors and try again.'));
      return;
    }
    try {
      await Api.instance.post('/etablissements/$_id/positions-proposees', {'lat': p.latitude, 'lng': p.longitude, 'precision_m': p.accuracy.ceil()});
      if (mounted) {
        setState(() => _positionConfirmee = true);
        afficherMessage(context, tr('Merci ! Votre position sera vérifiée avant publication.', 'Thank you! Your position will be checked before publication.'));
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      if (e.status == 409) setState(() => _positionConfirmee = true);
      afficherMessage(context, e.message);
    }
  }

  Future<void> _signalerErreur() async {
    if (!Session.instance.connecte) {
      final navigateur = Navigator.of(context);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(tr('Connectez-vous pour signaler une erreur.', 'Sign in to report an error.'))));
      navigateur.pop();
      navigateur.push(MaterialPageRoute(builder: (_) => const CompteScreen()));
      return;
    }
    final controleur = TextEditingController();
    final texte = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(tr('Signaler une erreur', 'Report an error')),
        content: TextField(
          controller: controleur,
          maxLines: 4,
          maxLength: 2000,
          decoration: InputDecoration(hintText: tr('Ex. : numéro erroné, établissement fermé…', 'E.g. wrong number, facility closed…')),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler', 'Cancel'))),
          FilledButton(onPressed: () => Navigator.pop(context, controleur.text.trim()), child: Text(tr('Envoyer', 'Send'))),
        ],
      ),
    );
    if (texte == null || texte.length < 5) return;
    try {
      await Api.instance.post('/messages', {'type': 'signalement', 'etablissement_id': _id, 'texte': texte});
      if (mounted) afficherMessage(context, tr('Merci ! Notre équipe va vérifier.', 'Thank you! Our team will check.'));
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
    final photos = ((e['photos'] as List?) ?? const []).map((p) => '${(p as Map)['url']}').toList();
    final horairesDetail = (e['horaires_detail'] as Map?) ?? const {};
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
                  child: Text(lieu, style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 13.5)),
                ),
            ]),
          ),
          IconButton(
            tooltip: tr('Partager', 'Share'),
            icon: Icon(Icons.share_outlined, color: Couleurs.accent, size: 24),
            onPressed: () => partagerEtablissement(e, garde: _gardeEnCours(periodeGarde)),
          ),
          if (Session.instance.connecte)
            IconButton(
              tooltip: _favori ? tr('Retirer des favoris', 'Remove from favourites') : tr('Ajouter aux favoris', 'Add to favourites'),
              icon: Icon(_favori ? Icons.star_rounded : Icons.star_border_rounded, color: Couleurs.encreOrange, size: 28),
              onPressed: _basculerFavori,
            ),
        ]),
        const SizedBox(height: 10),
        Wrap(spacing: 6, runSpacing: 6, children: [
          if (approx)
            Pastille.orange(tr('Position approximative', 'Approximate position'))
          else if (e['distance_m'] != null)
            Pastille.verte(tr('À ${formatDistance(e['distance_m'] as num)}', '${formatDistance(e['distance_m'] as num)} away')),
          if (periodeGarde != null) Pastille.verte('De garde du ${_jourMois(periodeGarde['du'])} au ${_jourMois(periodeGarde['au'])}'),
          if (ouvertureEtablissement(e) case final o?) PastilleOuverture(o),
          if (e['categorie'] is Map && periodeGarde == null) Pastille.grise('${e['categorie']['libelle']}'),
        ]),
        const SizedBox(height: 14),
        if (photos.isNotEmpty) ...[
          CarrouselPhotos(photos: photos),
          const SizedBox(height: 14),
        ],
        if (aPosition) CartePoint(lat: (e['lat'] as num).toDouble(), lng: (e['lng'] as num).toDouble(), hauteur: 150),
        if (aPosition && approx) ...[
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(children: [
              Icon(Icons.info_outline_rounded, size: 14, color: Couleurs.encreOrange),
              const SizedBox(width: 5),
              Expanded(
                child: Text(tr('Position indicative : centre de la commune', 'Indicative position: district centre'), style: TextStyle(color: Couleurs.encreOrange, fontSize: 12.5)),
              ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: OutlinedButton.icon(
              style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(46), foregroundColor: Couleurs.encreVerte),
              onPressed: _positionConfirmee ? null : _confirmerPosition,
              icon: Icon(_positionConfirmee ? Icons.check_circle_outline_rounded : Icons.my_location_rounded, size: 19),
              label: Text(_positionConfirmee ? tr('Merci, position transmise', 'Thanks, position sent') : tr('Je suis sur place : confirmer la position', 'I am on site: confirm the position')),
            ),
          ),
        ],
        const SizedBox(height: 10),
        _Ligne(Icons.call_outlined, tr('Téléphone', 'Phone'), telephones.isEmpty ? null : telephones.join(' · ')),
        _Ligne(Icons.phone_in_talk_outlined, tr('Autres n°', 'Other numbers'), telAutres),
        _Ligne(Icons.place_outlined, tr('Adresse', 'Address'), adresse),
        _Ligne(Icons.schedule_rounded, tr('Horaires', 'Opening hours'), e['h24'] == true ? tr('24h/24', '24 hours a day') : e['horaires'] as String?),
        if (horairesDetail.isNotEmpty) _HorairesDetail(horairesDetail),
        if (services.isNotEmpty) _Ligne(Icons.medical_services_outlined, tr('Services', 'Services'), services.join(', ')),
        _Ligne(Icons.verified_user_outlined, tr('Assurances', 'Insurers'), _complet ? (assurances.isEmpty ? tr('Non renseignées', 'Not provided') : assurances.join(', ')) : '…'),
        _Ligne(Icons.person_outline_rounded, tr('Responsable', 'Manager'), responsable),
        _Ligne(Icons.mail_outline_rounded, tr('E-mail', 'Email'), email, onTap: email == null ? null : () => ecrireEmail(email)),
        _Ligne(Icons.language_rounded, tr('Site web', 'Website'), siteWeb,
            onTap: siteWeb == null ? null : () => ouvrirLien(siteWeb.startsWith('http') ? siteWeb : 'https://$siteWeb')),
        _Ligne(Icons.event_available_outlined, tr('Vérifié le', 'Verified on'), e['verifie_at'] == null ? tr('Non vérifié', 'Not verified') : formatDate('${e['verifie_at']}')),
        if (e['description'] != null) _Ligne(Icons.notes_rounded, tr('Infos', 'Info'), '${e['description']}'),
        if (_texte(e['description_longue']) != null) _Ligne(Icons.article_outlined, tr('À propos', 'About'), '${e['description_longue']}'),
        // F13 : avis (absents des établissements d'urgence et des données hors ligne)
        if (_complet && e['avis'] is Map) BlocAvis(etablissementId: _id),
        const SizedBox(height: 16),
        Row(children: [
          if (telephones.isNotEmpty) ...[
            Expanded(
              child: FilledButton.icon(
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                onPressed: () {
                  _evenement('appel');
                  appeler(telephones.first);
                },
                icon: const Icon(Icons.call_rounded, size: 19),
                label: Text(tr('Appeler', 'Call')),
              ),
            ),
            const SizedBox(width: 12),
          ],
          Expanded(
            child: FilledButton.tonalIcon(
              style: FilledButton.styleFrom(
                backgroundColor: Couleurs.vertClair,
                foregroundColor: Couleurs.encreVerte,
                minimumSize: const Size.fromHeight(50),
              ),
              onPressed: () {
                _evenement('itineraire');
                if (approx || !aPosition) {
                  rechercherSurCarte([e['nom'], e['commune'], e['ville']].whereType<String>().join(', '));
                } else {
                  itineraire(e['lat'] as num, e['lng'] as num);
                }
              },
              icon: const Icon(Icons.directions_rounded, size: 19),
              label: Text(tr('Itinéraire', 'Directions')),
            ),
          ),
        ]),
        const SizedBox(height: 6),
        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          TextButton.icon(
            style: TextButton.styleFrom(foregroundColor: Couleurs.texteSecondaire, minimumSize: const Size(0, 46)),
            onPressed: _signalerErreur,
            icon: const Icon(Icons.flag_outlined, size: 18),
            label: Text(tr('Signaler une erreur', 'Report an error')),
          ),
          TextButton(
            style: TextButton.styleFrom(foregroundColor: Couleurs.texteSecondaire, minimumSize: const Size(0, 46)),
            onPressed: () => Navigator.pop(context),
            child: Text(tr('Fermer', 'Close')),
          ),
        ]),
        if (Session.instance.connecte && _complet) ...[
          if (_estResponsable)
            FilledButton.icon(
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(46), backgroundColor: Couleurs.orange),
              onPressed: () {
                final navigateur = Navigator.of(context);
                navigateur.pop();
                navigateur.push(MaterialPageRoute(builder: (_) => MonEtablissementDetailScreen(id: _id)));
              },
              icon: const Icon(Icons.storefront_rounded, size: 18),
              label: Text(tr('Gérer mon établissement', 'Manage my facility')),
            )
          else
            TextButton(
              style: TextButton.styleFrom(minimumSize: const Size(0, 46)),
              onPressed: () {
                final navigateur = Navigator.of(context);
                navigateur.pop();
                navigateur.push(MaterialPageRoute(builder: (_) => RevendicationScreen(etablissement: e)));
              },
              child: Text(tr('Je gère cet établissement', 'I manage this facility'), textAlign: TextAlign.center),
            ),
          TextButton(
            style: TextButton.styleFrom(minimumSize: const Size(0, 46), foregroundColor: Couleurs.texteSecondaire),
            onPressed: () {
              final navigateur = Navigator.of(context);
              navigateur.pop();
              navigateur.push(MaterialPageRoute(builder: (_) => FicheFormScreen(etablissement: e)));
            },
            child: Text(tr('Proposer une correction', 'Suggest a correction'), textAlign: TextAlign.center),
          ),
        ],
      ],
    );
  }

  /// Période de garde si elle couvre aujourd'hui (partage : on n'annonce pas une garde à venir comme en cours).
  Map? _gardeEnCours(Map? p) {
    if (p == null) return null;
    final du = DateTime.tryParse(''), au = DateTime.tryParse('');
    final auj = DateTime.now();
    final jour = DateTime(auj.year, auj.month, auj.day);
    if (du == null || au == null || jour.isBefore(du) || jour.isAfter(au)) return null;
    return p;
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
        SizedBox(width: 92, child: Text(libelle, style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 13.5))),
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

/// Fiche Premium : photos en carrousel (glissement horizontal + points).
class CarrouselPhotos extends StatefulWidget {
  const CarrouselPhotos({super.key, required this.photos});

  final List<String> photos;

  @override
  State<CarrouselPhotos> createState() => _CarrouselPhotosState();
}

class _CarrouselPhotosState extends State<CarrouselPhotos> {
  int _page = 0;

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      ClipRRect(
        borderRadius: BorderRadius.circular(16),
        child: SizedBox(
          height: 180,
          child: PageView.builder(
            itemCount: widget.photos.length,
            onPageChanged: (i) => setState(() => _page = i),
            itemBuilder: (_, i) => Image.network(
              widget.photos[i],
              fit: BoxFit.cover,
              width: double.infinity,
              loadingBuilder: (c, enfant, progression) => progression == null ? enfant : Container(color: Couleurs.gris, child: const Chargement()),
              errorBuilder: (_, __, ___) => Container(color: Couleurs.gris, child: Icon(Icons.broken_image_outlined, color: Couleurs.texteSecondaire)),
            ),
          ),
        ),
      ),
      if (widget.photos.length > 1)
        Padding(
          padding: const EdgeInsets.only(top: 8),
          child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            for (var i = 0; i < widget.photos.length; i++)
              Container(
                width: i == _page ? 18 : 7,
                height: 7,
                margin: const EdgeInsets.symmetric(horizontal: 3),
                decoration: BoxDecoration(color: i == _page ? Couleurs.vert : Couleurs.bord, borderRadius: BorderRadius.circular(99)),
              ),
          ]),
        ),
    ]);
  }
}

/// Horaires détaillés par jour (fiche Premium).
class _HorairesDetail extends StatelessWidget {
  const _HorairesDetail(this.horaires);

  final Map horaires;

  static const _jours = {'lun': 'Lundi', 'mar': 'Mardi', 'mer': 'Mercredi', 'jeu': 'Jeudi', 'ven': 'Vendredi', 'sam': 'Samedi', 'dim': 'Dimanche'};

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Padding(padding: EdgeInsets.only(top: 1), child: Icon(Icons.calendar_month_outlined, size: 18, color: Couleurs.sarcelle)),
        const SizedBox(width: 10),
        SizedBox(width: 92, child: Text(tr('Horaires détaillés', 'Detailed hours'), style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 13.5))),
        Expanded(
          child: Column(children: [
            for (final j in _jours.entries)
              if (horaires[j.key] != null)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 1.5),
                  child: Row(children: [
                    SizedBox(width: 92, child: Text(trd(j.value), style: TextStyle(fontSize: 13.5, color: Couleurs.texteSecondaire))),
                    Expanded(child: Text('${horaires[j.key]}', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14))),
                  ]),
                ),
          ]),
        ),
      ]),
    );
  }
}
