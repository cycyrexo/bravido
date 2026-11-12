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
import 'alerte/sos_screen.dart';
import 'compte/compte_screen.dart';
import 'etablissements_screen.dart';
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
                  Align(alignment: Alignment.centerRight, child: _PuceVille(nom: session.nomVille, onTap: () => choisirVille(context))),
                  const SizedBox(height: 6),
                  const Center(child: Logo()),
                  const SizedBox(height: 8),
                  const Center(
                    child: Text('Partout et tout le temps',
                        style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 15, letterSpacing: 0.2)),
                  ),
                  const SizedBox(height: 22),
                  BoutonSos(
                    texte: 'SOS · Alerter mes complices',
                    sousTitre: 'Appui long de 3 s · votre position est partagée',
                    onPressed: () => _ouvrir(const SosScreen()),
                  ),
                  const SizedBox(height: 18),
                  // IntrinsicHeight : les deux tuiles d'une ligne ont la même hauteur
                  IntrinsicHeight(
                    child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      _Tuile('Alerte & Urgence', '185 · 180 · 110', Icons.emergency_rounded, Couleurs.rouge, Couleurs.rougeClair,
                          () => _ouvrir(const AlerteScreen())),
                      const SizedBox(width: 12),
                      _Tuile('Pharmacies', 'Jour & Garde', Icons.local_pharmacy_rounded, const Color(0xFF2E9E3E), const Color(0xFFE2F5E5),
                          () => _ouvrir(const PharmaciesScreen())),
                    ]),
                  ),
                  const SizedBox(height: 12),
                  IntrinsicHeight(
                    child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      _Tuile('Hôpitaux & Cliniques', 'Publics, privés, militaires', Icons.local_hospital_rounded, Couleurs.bleu,
                          Couleurs.bleuClair, () => _ouvrir(const EtablissementsScreen.soins())),
                      const SizedBox(width: 12),
                      _Tuile('Espace Utilisateur', session.connecte ? 'Compte et complices' : 'Se connecter', Icons.person_rounded,
                          Couleurs.orange, Couleurs.orangeClair, () => _ouvrir(const CompteScreen())),
                    ]),
                  ),
                  const SizedBox(height: 16),
                  CarrouselPub(villeId: session.villeId),
                  if (_gardes != null) ...[
                    const SizedBox(height: 4),
                    _CarteGardes(
                      periode: _gardes!['du'] != null ? 'Du ${_jm(_gardes!['du'])} au ${_jm(_gardes!['au'])}' : null,
                      resume: '${_gardes!['nb']} pharmacie${_gardes!['nb'] == 1 ? '' : 's'} de garde à ${session.nomVille}',
                      onTap: () => _ouvrir(const PharmaciesScreen()),
                    ),
                  ],
                  const SizedBox(height: 18),
                  const Text('Appel d\'urgence direct', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  const SizedBox(height: 10),
                  const Row(children: [
                    _AppelRapide('185', 'SAMU'),
                    SizedBox(width: 10),
                    _AppelRapide('180', 'Pompiers'),
                    SizedBox(width: 10),
                    _AppelRapide('110', 'Police'),
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
    return Image.asset(
      'assets/images/logo-bravido.png',
      height: taille * 56 / 34,
      fit: BoxFit.contain,
      semanticLabel: 'BRAVIDO',
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
      color: Colors.white,
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
            const Icon(Icons.expand_more_rounded, size: 18, color: Couleurs.texteSecondaire),
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
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20), boxShadow: Couleurs.ombre),
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
                  decoration: BoxDecoration(color: fond, borderRadius: BorderRadius.circular(15)),
                  child: Icon(icone, color: couleur, size: 28),
                ),
                const SizedBox(height: 14),
                Text(titre, maxLines: 2, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, height: 1.2)),
                const SizedBox(height: 3),
                Text(sousTitre, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 12.5)),
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
                  const Text('Pharmacies de garde près de vous',
                      style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 15)),
                  const SizedBox(height: 2),
                  Text([if (periode != null) periode!, resume].join(' · '), style: const TextStyle(color: Colors.white, fontSize: 13)),
                ]),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(99)),
                child: const Text('Voir', style: TextStyle(color: Couleurs.vert, fontWeight: FontWeight.w800)),
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
        label: 'Appeler le $numero, $libelle',
        child: Container(
          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), boxShadow: Couleurs.ombre),
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
                      const Icon(Icons.call_rounded, size: 16, color: Couleurs.rouge),
                      const SizedBox(width: 4),
                      Text(numero, style: const TextStyle(color: Couleurs.rouge, fontWeight: FontWeight.w900, fontSize: 18)),
                    ]),
                    Text(libelle, style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 12.5)),
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
            const Text('Choisir une ville', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
            const SizedBox(height: 12),
            TextField(
              textInputAction: TextInputAction.search,
              decoration: const InputDecoration(hintText: 'Rechercher une ville', prefixIcon: Icon(Icons.search_rounded)),
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
                    decoration: const BoxDecoration(color: Couleurs.vertClair, shape: BoxShape.circle),
                    child: _localisation
                        ? const Padding(padding: EdgeInsets.all(11), child: CircularProgressIndicator(strokeWidth: 2))
                        : const Icon(Icons.my_location_rounded, color: Couleurs.vert, size: 20),
                  ),
                  title: const Text('Utiliser ma position', style: TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: const Text('Ville la plus proche de vous'),
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
                trailing: choisie ? const Icon(Icons.check_circle_rounded, color: Couleurs.vert) : null,
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
