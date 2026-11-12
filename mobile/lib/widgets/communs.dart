import 'package:flutter/material.dart';

import '../app.dart';
import '../core/outils.dart';
import '../screens/alerte/sos_screen.dart';
import '../screens/etablissement_detail_screen.dart';

/// ACC-03 : accès SOS depuis les écrans de consultation.
class SosAction extends StatelessWidget {
  const SosAction({super.key});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(right: 12),
      child: Semantics(
        button: true,
        label: 'SOS, alerter mes complices',
        child: InkWell(
          customBorder: const StadiumBorder(),
          onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const SosScreen())),
          // Zone tactile de 48 px autour de la pastille
          child: SizedBox(
            height: 48,
            child: Center(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(colors: [Color(0xFFF04438), Couleurs.rougeFonce]),
                  borderRadius: BorderRadius.circular(99),
                  boxShadow: const [BoxShadow(color: Color(0x40E03131), blurRadius: 8, offset: Offset(0, 3))],
                ),
                // Le pictogramme « SOS » doublonnait avec le texte : on garde l'alerte + le libellé
                child: const ExcludeSemantics(
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Icon(Icons.warning_amber_rounded, color: Colors.white, size: 18),
                    SizedBox(width: 4),
                    Text('SOS', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 13)),
                  ]),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Barre de titre des maquettes : bouton retour rond gris + titre à gauche.
class BarreTitre extends StatelessWidget implements PreferredSizeWidget {
  const BarreTitre(this.titre, {super.key, this.actions, this.bas, this.couleur});

  final String titre;
  final List<Widget>? actions;
  final PreferredSizeWidget? bas;
  final Color? couleur;

  @override
  Size get preferredSize => Size.fromHeight(kToolbarHeight + (bas?.preferredSize.height ?? 0));

  @override
  Widget build(BuildContext context) {
    final peutRevenir = Navigator.of(context).canPop();
    return AppBar(
      backgroundColor: couleur,
      foregroundColor: couleur == null ? null : Colors.white,
      titleTextStyle: couleur == null ? null : const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w700),
      automaticallyImplyLeading: false,
      titleSpacing: peutRevenir ? 0 : 16,
      leading: peutRevenir
          ? Padding(
              padding: const EdgeInsets.all(10),
              child: Material(
                color: couleur == null ? Colors.white : Colors.white24,
                elevation: couleur == null ? 2 : 0,
                shadowColor: const Color(0x330B2A33),
                shape: const CircleBorder(),
                child: InkWell(
                  customBorder: const CircleBorder(),
                  onTap: () => Navigator.maybePop(context),
                  child: Icon(Icons.chevron_left, size: 22, color: couleur == null ? Couleurs.texte : Colors.white),
                ),
              ),
            )
          : null,
      title: Text(titre),
      actions: actions,
      bottom: bas,
    );
  }
}

/// Petit badge arrondi : « De garde », « 24h/24 », « 4 assurances », statuts...
class Pastille extends StatelessWidget {
  const Pastille(this.texte, {super.key, this.couleur = Couleurs.texteSecondaire, this.fond});

  final String texte;
  final Color couleur;
  final Color? fond;

  // Encres foncées : contraste ≥ 4,5:1 sur les fonds pastel
  const Pastille.verte(this.texte, {super.key}) : couleur = const Color(0xFF146A66), fond = Couleurs.vertClair;
  const Pastille.orange(this.texte, {super.key}) : couleur = const Color(0xFFA84300), fond = Couleurs.orangeClair;
  const Pastille.rouge(this.texte, {super.key}) : couleur = Couleurs.rougeFonce, fond = Couleurs.rougeClair;
  const Pastille.grise(this.texte, {super.key}) : couleur = const Color(0xFF55646C), fond = Couleurs.gris;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: fond ?? couleur.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(99)),
      child: Text(texte, style: TextStyle(color: couleur, fontSize: 11, fontWeight: FontWeight.w700)),
    );
  }
}

/// Encadré gris d'information en tête de liste.
class Info extends StatelessWidget {
  const Info(this.texte, {super.key, this.enrichi});

  final String texte;
  final InlineSpan? enrichi;

  @override
  Widget build(BuildContext context) {
    const style = TextStyle(color: Color(0xFF4A5A62), fontSize: 13, height: 1.35);
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      decoration: BoxDecoration(color: Couleurs.gris, borderRadius: BorderRadius.circular(14)),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Padding(
          padding: EdgeInsets.only(top: 1),
          child: Icon(Icons.info_outline_rounded, size: 16, color: Couleurs.vert),
        ),
        const SizedBox(width: 8),
        Expanded(child: enrichi != null ? Text.rich(enrichi!, style: style) : Text(texte, style: style)),
      ]),
    );
  }
}

/// Onglets en pastilles (« De garde | Toutes les pharmacies », « Publics | Privés »...).
class Puces<T> extends StatelessWidget {
  const Puces({super.key, required this.options, required this.valeur, required this.onChanged});

  final Map<T, String> options;
  final T valeur;
  final ValueChanged<T> onChanged;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(children: [
        for (final e in options.entries)
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: ChoiceChip(
              label: Text(e.value),
              selected: e.key == valeur,
              labelStyle: TextStyle(color: e.key == valeur ? Colors.white : Couleurs.texte, fontWeight: FontWeight.w700, fontSize: 13),
              onSelected: (_) => onChanged(e.key),
              visualDensity: VisualDensity.compact,
            ),
          ),
      ]),
    );
  }
}

/// Titre de section en petites capitales grises (« NUMÉROS D'URGENCE », « VOS COMPLICES »).
class TitreSection extends StatelessWidget {
  const TitreSection(this.texte, {super.key});

  final String texte;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(2, 18, 0, 8),
        child: Text(texte.toUpperCase(),
            style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 12, fontWeight: FontWeight.w800, letterSpacing: 0.6)),
      );
}

/// Ligne « icône carrée colorée + titre + sous-titre » (services, espace utilisateur).
class LigneMenu extends StatelessWidget {
  const LigneMenu({super.key, required this.badge, required this.titre, this.sousTitre, this.onTap, this.couleurBadge = Couleurs.gris});

  final Widget badge;
  final String titre;
  final String? sousTitre;
  final VoidCallback? onTap;
  final Color couleurBadge;

  @override
  Widget build(BuildContext context) {
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Container(
              width: 44,
              height: 44,
              alignment: Alignment.center,
              decoration: BoxDecoration(color: couleurBadge, borderRadius: BorderRadius.circular(14)),
              child: DefaultTextStyle.merge(style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13), child: badge),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(titre, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                if (sousTitre != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: Text(sousTitre!, style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 13)),
                  ),
              ]),
            ),
            if (onTap != null) const Icon(Icons.chevron_right, color: Color(0xFFB4BEC3)),
          ]),
        ),
      ),
    );
  }
}

/// Grand bouton SOS : dégradé rouge, ombre portée, pictogramme.
class BoutonSos extends StatelessWidget {
  const BoutonSos({super.key, required this.texte, required this.onPressed, this.sousTitre});

  final String texte;
  final String? sousTitre;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: texte,
      child: Container(
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            colors: [Color(0xFFF04438), Couleurs.rougeFonce],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(18),
          boxShadow: const [BoxShadow(color: Color(0x55E03131), blurRadius: 18, offset: Offset(0, 8))],
        ),
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            borderRadius: BorderRadius.circular(18),
            onTap: onPressed,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
              child: Row(children: [
                Container(
                  width: 46,
                  height: 46,
                  decoration: const BoxDecoration(color: Colors.white24, shape: BoxShape.circle),
                  child: const Icon(Icons.sos_rounded, color: Colors.white, size: 28),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: ExcludeSemantics(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(texte, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 16)),
                      if (sousTitre != null)
                        Text(sousTitre!, style: const TextStyle(color: Colors.white70, fontSize: 13)),
                    ]),
                  ),
                ),
                const Icon(Icons.arrow_forward_rounded, color: Colors.white),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

/// Pictogramme rond coloré selon la catégorie d'établissement.
class IconeCategorie extends StatelessWidget {
  const IconeCategorie(this.code, {super.key, this.taille = 44});

  final String? code;
  final double taille;

  @override
  Widget build(BuildContext context) {
    final (icone, couleur, fond) = switch (code) {
      'pharmacie' => (Icons.local_pharmacy_rounded, const Color(0xFF2E9E3E), const Color(0xFFE2F5E5)),
      'hopital_public' => (Icons.local_hospital_rounded, Couleurs.bleu, Couleurs.bleuClair),
      'hopital_prive' => (Icons.medical_services_rounded, Couleurs.vert, Couleurs.vertClair),
      'militaire' => (Icons.shield_rounded, const Color(0xFF5B6B3A), const Color(0xFFEDF1E2)),
      'veterinaire' => (Icons.pets_rounded, const Color(0xFF8A5A2B), const Color(0xFFF5EBDD)),
      'optique' => (Icons.visibility_rounded, const Color(0xFF6B4FD8), const Color(0xFFECE8FB)),
      'dentaire' => (Icons.mood_rounded, const Color(0xFF0E8FB3), const Color(0xFFDFF3F9)),
      'imagerie' => (Icons.document_scanner_rounded, const Color(0xFF3F51B5), const Color(0xFFE5E8F7)),
      'laboratoire' => (Icons.science_rounded, const Color(0xFFB0307A), const Color(0xFFFBE4F0)),
      'centre_secours' => (Icons.fire_truck_rounded, Couleurs.rouge, Couleurs.rougeClair),
      'bureau_fga' => (Icons.car_crash_rounded, const Color(0xFFB98900), const Color(0xFFFFF4CC)),
      _ => (Icons.place_rounded, Couleurs.texteSecondaire, Couleurs.gris),
    };
    return Container(
      width: taille,
      height: taille,
      decoration: BoxDecoration(color: fond, shape: BoxShape.circle),
      child: Icon(icone, color: couleur, size: taille * 0.5),
    );
  }
}

class Chargement extends StatelessWidget {
  const Chargement({super.key});

  @override
  Widget build(BuildContext context) => const Center(child: Padding(padding: EdgeInsets.all(32), child: CircularProgressIndicator()));
}

class MessageVide extends StatelessWidget {
  const MessageVide(this.texte, {super.key, this.icone = Icons.info_outline, this.onReessayer});

  final String texte;
  final IconData icone;
  final VoidCallback? onReessayer;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Container(
            width: 76,
            height: 76,
            decoration: const BoxDecoration(color: Couleurs.vertClair, shape: BoxShape.circle),
            child: Icon(icone, size: 34, color: Couleurs.vert),
          ),
          const SizedBox(height: 14),
          Text(texte, textAlign: TextAlign.center, style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 14.5, height: 1.35)),
          if (onReessayer != null) ...[
            const SizedBox(height: 14),
            OutlinedButton.icon(onPressed: onReessayer, icon: const Icon(Icons.refresh_rounded, size: 18), label: const Text('Réessayer')),
          ],
        ]),
      ),
    );
  }
}

class Bandeau extends StatelessWidget {
  const Bandeau(this.texte, {super.key, this.couleur = Couleurs.orange, this.icone = Icons.cloud_off});

  final String texte;
  final Color couleur;
  final IconData icone;

  @override
  Widget build(BuildContext context) {
    // Texte assombri : contraste suffisant sur le fond teinté
    final encre = Color.lerp(couleur, Colors.black, 0.4)!;
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(color: couleur.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(14)),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      child: Row(children: [
        Icon(icone, size: 18, color: encre),
        const SizedBox(width: 8),
        Expanded(child: Text(texte, style: TextStyle(color: encre, fontWeight: FontWeight.w600, fontSize: 13.5))),
      ]),
    );
  }
}

/// Pastille ronde avec l'initiale d'une personne (profil, complices, alertes).
class Avatar extends StatelessWidget {
  const Avatar(this.nom, {super.key, this.taille = 44, this.couleur = Couleurs.vert, this.fond = Couleurs.vertClair});

  final String? nom;
  final double taille;
  final Color couleur;
  final Color fond;

  @override
  Widget build(BuildContext context) {
    final propre = (nom ?? '').trim();
    // Un numéro de téléphone n'a pas d'initiale parlante
    final initiale = propre.isEmpty || RegExp(r'^[0-9+ ]').hasMatch(propre) ? null : propre.characters.first.toUpperCase();
    return ExcludeSemantics(
      child: Container(
        width: taille,
        height: taille,
        alignment: Alignment.center,
        decoration: BoxDecoration(color: fond, shape: BoxShape.circle),
        child: initiale == null
            ? Icon(Icons.person_rounded, color: couleur, size: taille * 0.55)
            : Text(initiale, style: TextStyle(color: couleur, fontWeight: FontWeight.w800, fontSize: taille * 0.42)),
      ),
    );
  }
}

/// Carte d'établissement des maquettes : nom, commune · services, distance à droite,
/// pastilles « De garde », « 24h/24 », « N assurances ».
class EtablissementTile extends StatelessWidget {
  const EtablissementTile(this.e, {super.key, this.onTap});

  final Map<String, dynamic> e;

  /// Par défaut : fiche en panneau bas (maquette « Fiche pharmacie »).
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final services = ((e['services'] as List?) ?? const []).map((s) => '$s').toList();
    final sousTitre = [
      e['commune'],
      if (services.isNotEmpty) services.take(2).join(', ') else if (e['repere'] != null) e['repere'],
    ].whereType<String>().join(' · ');
    final nbAssurances = (e['nb_assurances'] as num?)?.toInt() ?? (e['assurances'] as List?)?.length;

    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap ?? () => ouvrirFiche(context, e),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            IconeCategorie(e['categorie'] is Map ? '${e['categorie']['code']}' : null),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${e['nom']}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                if (sousTitre.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: Text(sousTitre, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 13)),
                  ),
                const SizedBox(height: 7),
                Wrap(spacing: 5, runSpacing: 4, children: [
                  if (e['garde'] != null || e['de_garde'] == true) const Pastille.verte('De garde'),
                  if (e['h24'] == true) const Pastille.orange('24h/24'),
                  if (nbAssurances != null && nbAssurances > 0) Pastille.grise('$nbAssurances assurance${nbAssurances > 1 ? 's' : ''}'),
                ]),
              ]),
            ),
            // Position approximative : la distance au centre de la commune n'a pas de sens
            if (e['distance_m'] != null && e['position_approx'] != true)
              Container(
                margin: const EdgeInsets.only(left: 8),
                padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                decoration: BoxDecoration(color: Couleurs.vertClair, borderRadius: BorderRadius.circular(99)),
                child: Text(formatDistance(e['distance_m'] as num),
                    style: const TextStyle(color: Color(0xFF146A66), fontWeight: FontWeight.w800, fontSize: 12.5)),
              ),
          ]),
        ),
      ),
    );
  }
}

void afficherMessage(BuildContext context, String texte) {
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(texte)));
}
