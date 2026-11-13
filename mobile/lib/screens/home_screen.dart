import 'dart:math';

import 'package:flutter/material.dart';

import '../app.dart';
import '../core/api.dart';
import '../core/cache.dart';
import '../core/outils.dart';
import '../core/session.dart';
import '../widgets/carrousel_pub.dart';
import '../widgets/communs.dart';
import 'alerte/alerte_screen.dart';
import 'alerte/faux_appel_screen.dart';
import 'alerte/sos_screen.dart';
import 'alerte/trajet_screen.dart';
import 'compte/compte_screen.dart';
import 'compte/rappels_screen.dart';
import '../core/langue.dart';
import 'carte_screen.dart';
import 'etablissements_screen.dart';
import 'recherche_screen.dart';
import 'reglages_screen.dart';
import 'pharmacies_screen.dart';

/// Accueil (ACC-01 à ACC-04).
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  Map<String, dynamic>? _gardes; // {nb, du, au}
  int? _villeAffichee;

  @override
  void initState() {
    super.initState();
    Session.instance.addListener(_villeChangee);
    _chargerGardes();
  }

  @override
  void dispose() {
    Session.instance.removeListener(_villeChangee);
    super.dispose();
  }

  void _villeChangee() {
    if (Session.instance.villeId != _villeAffichee) _chargerGardes();
  }

  Future<void> _chargerGardes() async {
    final villeId = Session.instance.villeId;
    _villeAffichee = villeId;
    if (villeId == null) return;
    try {
      final r = await Api.instance.get('/pharmacies/garde', query: {'ville_id': villeId}) as Map;
      final items = r['data'] as List;
      await Cache.ecrire('gardes_$villeId', {'data': items, 'periode': r['periode']});
      if (!mounted) return;
      setState(() => _gardes = {'nb': items.length, ...?(r['periode'] as Map?)?.cast<String, dynamic>()});
    } catch (_) {
      final cache = Cache.lireMap('gardes_$villeId');
      if (cache != null && mounted) {
        setState(() => _gardes = {'nb': (cache['data'] as List).length, ...?(cache['periode'] as Map?)?.cast<String, dynamic>()});
      }
    }
  }

  void _ouvrir(Widget ecran) => Navigator.push(context, MaterialPageRoute(builder: (_) => ecran));

  @override
  Widget build(BuildContext context) {
    final session = Session.instance;

    return ListenableBuilder(
      listenable: session,
      builder: (context, _) => Scaffold(
        body: Stack(children: [
          // Halo sarcelle très léger derrière le logo
          Positioned(
            top: -140,
            left: -60,
            right: -60,
            child: Container(
              height: 380,
              decoration: const BoxDecoration(
                gradient: RadialGradient(colors: [Color(0x33339A99), Color(0x00339A99)], radius: 0.75),
              ),
            ),
          ),
          SafeArea(
            child: RefreshIndicator(
              onRefresh: () async {
                await session.rafraichirConfig();
                await _chargerGardes();
              },
              child: ListView(
                padding: const EdgeInsets.fromLTRB(18, 10, 18, 28),
                children: [
                  Row(children: [
                    Semantics(
                      button: true,
                      label: tr('Réglages', 'Settings'),
                      child: IconButton(
                        tooltip: tr('Réglages', 'Settings'),
                        icon: Icon(Icons.settings_rounded, color: Couleurs.texteSecondaire),
                        onPressed: () => _ouvrir(const ReglagesScreen()),
                      ),
                    ),
                    const Spacer(),
                    _PuceVille(nom: session.nomVille, onTap: () => choisirVille(context)),
                  ]),
                  const SizedBox(height: 6),
                  const Center(child: Logo()),
                  const SizedBox(height: 8),
                  Center(
                    child: Text(tr('Partout et tout le temps', 'Anywhere, anytime'),
                        style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 15, letterSpacing: 0.2)),
                  ),
                  const SizedBox(height: 16),
                  const BarreRecherche(),
                  const SizedBox(height: 16),
                  BoutonSos(
                    texte: tr('SOS · Alerter mes complices', 'SOS · Alert my accomplices'),
                    sousTitre: tr('Appui long de 3 s · votre position est partagée', 'Press and hold 3 s · your location is shared'),
                    onPressed: () => _ouvrir(const SosScreen()),
                  ),
                  const SizedBox(height: 18),
                  // IntrinsicHeight : les deux tuiles d'une ligne ont la même hauteur
                  IntrinsicHeight(
                    child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      _Tuile(tr('Alerte & Urgence', 'Alert & Emergency'), '185 · 180 · 110', Icons.emergency_rounded, Couleurs.rouge, Couleurs.rougeClair,
                          () => _ouvrir(const AlerteScreen())),
                      const SizedBox(width: 12),
                      _Tuile(tr('Pharmacies', 'Pharmacies'), tr('Jour & Garde', 'Day & On-duty'), Icons.local_pharmacy_rounded, const Color(0xFF2E9E3E), const Color(0xFFE2F5E5),
                          () => _ouvrir(const PharmaciesScreen())),
                    ]),
                  ),
                  const SizedBox(height: 12),
                  IntrinsicHeight(
                    child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      _Tuile(tr('Hôpitaux & Cliniques', 'Hospitals & Clinics'), tr('Publics, privés, militaires', 'Public, private, military'), Icons.local_hospital_rounded, Couleurs.bleu,
                          Couleurs.bleuClair, () => _ouvrir(const EtablissementsScreen.soins())),
                      const SizedBox(width: 12),
                      _Tuile(tr('Espace Utilisateur', 'My Account'), session.connecte ? tr('Compte et complices', 'Account and accomplices') : tr('Se connecter', 'Sign in'), Icons.person_rounded,
                          Couleurs.orange, Couleurs.orangeClair, () => _ouvrir(const CompteScreen())),
                    ]),
                  ),
                  const SizedBox(height: 12),
                  IntrinsicHeight(
                    child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      _Tuile(tr('Je rentre chez moi', 'Heading home'), tr('Trajet surveillé', 'Monitored trip'), Icons.directions_walk_rounded, Couleurs.vert, Couleurs.vertClair,
                          () => _ouvrir(const TrajetScreen())),
                      const SizedBox(width: 12),
                      _Tuile(tr('Faux appel', 'Fake call'), tr('Et mode discret', 'And discreet mode'), Icons.phone_callback_rounded, const Color(0xFF6B4FD6), const Color(0xFFEDE8FB),
                          () => _ouvrir(const FauxAppelScreen())),
                    ]),
                  ),
                  const SizedBox(height: 12),
                  IntrinsicHeight(
                    child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      _Tuile(tr('Carte', 'Map'), tr('Position exacte', 'Exact position'), Icons.map_rounded, const Color(0xFF0E8FB3),
                          const Color(0xFFDFF3F9), () => _ouvrir(const CarteScreen())),
                      const SizedBox(width: 12),
                      _Tuile(tr('Rappels', 'Reminders'), tr('Médicaments, rendez-vous', 'Medication, visits'), Icons.alarm_rounded, const Color(0xFFB0307A),
                          Couleurs.rose, () => _ouvrir(const RappelsScreen())),
                    ]),
                  ),
                  const SizedBox(height: 16),
                  CarrouselPub(villeId: session.villeId),
                  if (_gardes != null) ...[
                    const SizedBox(height: 4),
                    _CarteGardes(
                      periode: _gardes!['du'] != null ? tr('Du ${_jm(_gardes!['du'])} au ${_jm(_gardes!['au'])}', 'From ${_jm(_gardes!['du'])} to ${_jm(_gardes!['au'])}') : null,
                      resume: tr('${_gardes!['nb']} pharmacie${_gardes!['nb'] == 1 ? '' : 's'} de garde à ${session.nomVille}', '${_gardes!['nb']} on-duty pharmac${_gardes!['nb'] == 1 ? 'y' : 'ies'} in ${session.nomVille}'),
                      onTap: () => _ouvrir(const PharmaciesScreen()),
                    ),
                  ],
                  const SizedBox(height: 18),
                  Text(tr('Appel d\'urgence direct', 'Direct emergency call'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  const SizedBox(height: 10),
                  Row(children: [
                    const _AppelRapide('185', 'SAMU'),
                    const SizedBox(width: 10),
                    _AppelRapide('180', tr('Pompiers', 'Fire brigade')),
                    const SizedBox(width: 10),
                    _AppelRapide('110', tr('Police', 'Police')),
                  ]),
                ],
              ),
            ),
          ),
        ]),
      ),
    );
  }

  String _jm(dynamic date) {
    final d = DateTime.tryParse('$date');
    return d == null ? '' : '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}';
  }
}

/// Logo officiel « bravido ».
class Logo extends StatelessWidget {
  const Logo({super.key, this.taille = 34});

  final double taille;

  @override
  Widget build(BuildContext context) {
    // `taille` garde l'ancienne échelle : 34 -> 56 px de haut.
    final logo = Image.asset(
      'assets/images/logo-bravido.png',
      height: taille * 56 / 34,
      fit: BoxFit.contain,
      semanticLabel: 'BRAVIDO',
    );
    // Le logo a des lettres sombres : en thème sombre, on le pose sur une plaque claire
    if (!Couleurs.sombre) return logo;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 8),
      decoration: BoxDecoration(color: const Color(0xFFF4F7F8), borderRadius: BorderRadius.circular(18)),
      child: logo,
    );
  }
}

class _PuceVille extends StatelessWidget {
  const _PuceVille({required this.nom, required this.onTap});

  final String nom;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Couleurs.surface,
      elevation: 2,
      shadowColor: const Color(0x330B2A33),
      borderRadius: BorderRadius.circular(99),
      child: InkWell(
        borderRadius: BorderRadius.circular(99),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.place_rounded, size: 16, color: Couleurs.sarcelle),
            const SizedBox(width: 4),
            Text(nom, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            Icon(Icons.expand_more_rounded, size: 18, color: Couleurs.texteSecondaire),
          ]),
        ),
      ),
    );
  }
}

class _Tuile extends StatelessWidget {
  const _Tuile(this.titre, this.sousTitre, this.icone, this.couleur, this.fond, this.onTap);

  final String titre;
  final String sousTitre;
  final IconData icone;
  final Color couleur;
  final Color fond;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Container(
        decoration: BoxDecoration(color: Couleurs.surface, borderRadius: BorderRadius.circular(20), boxShadow: Couleurs.ombre),
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            borderRadius: BorderRadius.circular(20),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Container(
                  width: 50,
                  height: 50,
                  decoration: BoxDecoration(color: Couleurs.sombre ? couleur.withValues(alpha: 0.22) : fond, borderRadius: BorderRadius.circular(15)),
                  child: Icon(icone, color: Couleurs.sombre ? Color.lerp(couleur, Colors.white, 0.4) : couleur, size: 28),
                ),
                const SizedBox(height: 14),
                Text(titre, maxLines: 2, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, height: 1.2)),
                const SizedBox(height: 3),
                Text(sousTitre, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 12.5)),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

class _CarteGardes extends StatelessWidget {
  const _CarteGardes({required this.periode, required this.resume, required this.onTap});

  final String? periode;
  final String resume;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        gradient: const LinearGradient(colors: [Couleurs.sarcelle, Couleurs.vert], begin: Alignment.topLeft, end: Alignment.bottomRight),
        borderRadius: BorderRadius.circular(20),
        boxShadow: const [BoxShadow(color: Color(0x40339A99), blurRadius: 18, offset: Offset(0, 8))],
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(20),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(children: [
              Container(
                width: 46,
                height: 46,
                decoration: const BoxDecoration(color: Colors.white24, shape: BoxShape.circle),
                child: const Icon(Icons.nightlight_round, color: Colors.white),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr('Pharmacies de garde près de vous', 'On-duty pharmacies near you'),
                      style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 15)),
                  const SizedBox(height: 2),
                  Text([if (periode != null) periode!, resume].join(' · '), style: const TextStyle(color: Colors.white, fontSize: 13)),
                ]),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                decoration: BoxDecoration(color: Couleurs.surface, borderRadius: BorderRadius.circular(99)),
                child: Text(tr('Voir', 'View'), style: TextStyle(color: Couleurs.accent, fontWeight: FontWeight.w800)),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}

class _AppelRapide extends StatelessWidget {
  const _AppelRapide(this.numero, this.libelle);

  final String numero;
  final String libelle;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Semantics(
        button: true,
        label: tr('Appeler le $numero, $libelle', 'Call $numero, $libelle'),
        child: Container(
          decoration: BoxDecoration(color: Couleurs.surface, borderRadius: BorderRadius.circular(16), boxShadow: Couleurs.ombre),
          child: Material(
            color: Colors.transparent,
            child: InkWell(
              borderRadius: BorderRadius.circular(16),
              onTap: () => appeler(numero),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 12),
                child: ExcludeSemantics(
                  child: Column(children: [
                    Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                      Icon(Icons.call_rounded, size: 16, color: Couleurs.encreRouge),
                      const SizedBox(width: 4),
                      Text(numero, style: TextStyle(color: Couleurs.encreRouge, fontWeight: FontWeight.w900, fontSize: 18)),
                    ]),
                    Text(libelle, style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 12.5)),
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

/// ACC-04 : choix de la ville ou détection GPS, mémorisé.
Future<void> choisirVille(BuildContext context) async {
  final session = Session.instance;

  // Panneau défilant : la liste compte plusieurs dizaines de villes
  final choix = await showModalBottomSheet<int>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    showDragHandle: true,
    builder: (_) => DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.8,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      builder: (context, defilement) => _ChoixVille(defilement: defilement),
    ),
  );

  if (choix != null) await session.choisirVille(choix);
}

/// Minuscules sans accents : « seguela » trouve « Séguéla ».
String _cleRecherche(String texte) {
  const avec = 'àâäéèêëîïôöùûüçñ';
  const sans = 'aaaeeeeiioouuucn';
  final b = StringBuffer();
  for (final c in texte.toLowerCase().split('')) {
    final i = avec.indexOf(c);
    b.write(i < 0 ? c : sans[i]);
  }
  return b.toString();
}

class _ChoixVille extends StatefulWidget {
  const _ChoixVille({required this.defilement});

  final ScrollController defilement;

  @override
  State<_ChoixVille> createState() => _ChoixVilleState();
}

class _ChoixVilleState extends State<_ChoixVille> {
  final List<Map<String, dynamic>> _villes = [...Session.instance.villes];
  String _recherche = '';
  bool _localisation = false;

  Future<void> _maPosition() async {
    setState(() => _localisation = true);
    final p = await Localisation.position();
    if (!mounted) return;
    if (p == null || _villes.isEmpty) {
      setState(() => _localisation = false);
      afficherMessage(context, 'Position indisponible. Choisissez votre ville dans la liste.');
      return;
    }
    final proches = [..._villes]
      ..sort((a, b) => _distance(p.latitude, p.longitude, a).compareTo(_distance(p.latitude, p.longitude, b)));
    Navigator.pop(context, proches.first['id'] as int);
  }

  @override
  Widget build(BuildContext context) {
    final actuelle = Session.instance.villeId;
    final q = _cleRecherche(_recherche.trim());
    final villes = q.isEmpty ? _villes : _villes.where((v) => _cleRecherche('${v['nom']}').contains(q)).toList();

    return Padding(
      // Le clavier ne masque pas la fin de la liste
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(18, 0, 18, 10),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('Choisir une ville', 'Choose a city'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
            const SizedBox(height: 12),
            TextField(
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(hintText: tr('Rechercher une ville', 'Search a city'), prefixIcon: const Icon(Icons.search_rounded)),
              onChanged: (v) => setState(() => _recherche = v),
            ),
          ]),
        ),
        Expanded(
          child: ListView.builder(
            controller: widget.defilement,
            padding: const EdgeInsets.fromLTRB(8, 0, 8, 16),
            itemCount: villes.length + 1,
            itemBuilder: (context, i) {
              if (i == 0) {
                if (_villes.isEmpty) {
                  return const MessageVide('Connectez-vous à Internet pour charger la liste des villes.', icone: Icons.cloud_off);
                }
                if (q.isNotEmpty) {
                  return villes.isEmpty
                      ? const MessageVide('Aucune ville ne correspond à votre recherche.', icone: Icons.search_off)
                      : const SizedBox.shrink();
                }
                return ListTile(
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  leading: Container(
                    width: 40,
                    height: 40,
                    decoration: BoxDecoration(color: Couleurs.vertClair, shape: BoxShape.circle),
                    child: _localisation
                        ? const Padding(padding: EdgeInsets.all(11), child: CircularProgressIndicator(strokeWidth: 2))
                        : Icon(Icons.my_location_rounded, color: Couleurs.accent, size: 20),
                  ),
                  title: Text(tr('Utiliser ma position', 'Use my location'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text(tr('Ville la plus proche de vous', 'Closest city to you')),
                  onTap: _localisation ? null : _maPosition,
                );
              }
              final v = villes[i - 1];
              final choisie = v['id'] == actuelle;
              return ListTile(
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                leading: Icon(Icons.place_rounded, color: choisie ? Couleurs.vert : const Color(0xFFB4C0C4)),
                title: Text('${v['nom']}',
                    style: TextStyle(fontWeight: choisie ? FontWeight.w800 : FontWeight.w500, color: choisie ? Couleurs.vert : null)),
                trailing: choisie ? Icon(Icons.check_circle_rounded, color: Couleurs.accent) : null,
                onTap: () => Navigator.pop(context, v['id'] as int),
              );
            },
          ),
        ),
      ]),
    );
  }
}

double _distance(double lat, double lng, Map<String, dynamic> ville) {
  final vLat = (ville['lat'] as num?)?.toDouble() ?? 0;
  final vLng = (ville['lng'] as num?)?.toDouble() ?? 0;
  return (pow(lat - vLat, 2) + pow((lng - vLng) * cos(lat * pi / 180), 2)).toDouble();
}
