import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:geolocator/geolocator.dart';

import '../../app.dart';
import '../../config.dart';
import '../../core/api.dart';
import '../../core/cache.dart';
import '../../core/outils.dart';
import '../../core/session.dart';
import '../../widgets/communs.dart';
import '../compte/complices_screen.dart';
import '../compte/compte_screen.dart';
import 'alerte_active_screen.dart';

/// Maquettes « SOS : appui long 3 s » et « Compte à rebours annulable » (URG-02, URG-03).
/// Aucune publicité sur cet écran.
class SosScreen extends StatefulWidget {
  const SosScreen({super.key});

  @override
  State<SosScreen> createState() => _SosScreenState();
}

class _SosScreenState extends State<SosScreen> with TickerProviderStateMixin {
  late final AnimationController _appui = AnimationController(vsync: this, duration: const Duration(seconds: 3))
    ..addStatusListener((statut) {
      if (statut == AnimationStatus.completed) _compteARebours();
    });

  /// Halo « pouls » : deux anneaux qui s'étendent et s'effacent, en boucle douce.
  late final AnimationController _pouls = AnimationController(vsync: this, duration: const Duration(milliseconds: 2200));

  String _motif = 'malaise';
  bool _envoi = false;
  bool _presse = false;
  bool _animations = true;
  int _nbComplices = nombreComplicesAcceptes();

  @override
  void initState() {
    super.initState();
    _rafraichirComplices();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // Respecte « Supprimer les animations » du système
    _animations = !MediaQuery.disableAnimationsOf(context);
    _majPouls();
  }

  @override
  void dispose() {
    _appui.dispose();
    _pouls.dispose();
    super.dispose();
  }

  /// Le halo s'arrête pendant l'appui et pendant l'envoi.
  void _majPouls() {
    if (_animations && !_envoi && !_presse) {
      if (!_pouls.isAnimating) _pouls.repeat();
    } else {
      _pouls.stop();
      _pouls.value = 0;
    }
  }

  void _appuyer(bool presse) {
    if (_presse == presse) return;
    setState(() => _presse = presse);
    _majPouls();
  }

  Future<void> _rafraichirComplices() async {
    if (!Session.instance.connecte) return;
    try {
      final r = await Api.instance.get('/complices') as Map;
      await Cache.ecrire('complices', r['data']);
      if (mounted) setState(() => _nbComplices = nombreComplicesAcceptes());
    } catch (_) {}
  }

  Future<void> _compteARebours() async {
    HapticFeedback.heavyImpact();
    _appui.reset();
    final confirme = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (_) => const _CompteARebours(),
    );
    if (confirme == true) await _envoyer();
  }

  Future<void> _envoyer() async {
    if (!Session.instance.connecte) {
      _dialogue('Connectez-vous pour prévenir vos complices',
          'L\'alerte aux complices nécessite un compte. En cas d\'urgence, appelez le 185 (SAMU) ou le 180 (pompiers).',
          actionCompte: true);
      return;
    }

    setState(() => _envoi = true);
    _majPouls();
    final position = await Localisation.position(delai: const Duration(seconds: 8));

    try {
      final r = await Api.instance.post('/alertes', {
        'motif': _motif,
        if (position != null) 'lat': position.latitude,
        if (position != null) 'lng': position.longitude,
        if (position != null) 'precision_m': position.accuracy.round(),
      });
      final alerte = Map<String, dynamic>.from((r as Map)['data'] as Map);
      if (!mounted) return;
      await Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => AlerteActiveScreen(alerte: alerte)));
    } on ApiException catch (e) {
      if (e.horsLigne) {
        await _secoursSms(position);
      } else {
        _dialogue('Alerte non envoyée', e.message);
      }
    } finally {
      if (mounted) {
        setState(() => _envoi = false);
        _majPouls();
      }
    }
  }

  /// Pas de réseau de données : SMS pré-rempli vers les complices en cache.
  Future<void> _secoursSms(Position? p) async {
    final numeros = (Cache.lireListe('complices') ?? const [])
        .where((c) => c is Map && c['statut'] == 'accepte')
        .map((c) => '${(c as Map)['telephone']}')
        .toList();
    if (numeros.isEmpty) {
      _dialogue('Pas de connexion', 'Impossible de joindre vos complices. Appelez le 185 (SAMU) ou le 180 (pompiers).');
      return;
    }
    final lien = p == null ? '' : ' Ma position : https://maps.google.com/?q=${p.latitude},${p.longitude}';
    final ouvert = await composerSms(
      numeros,
      'ALERTE BRAVIDO : j\'ai besoin d\'aide (${Config.motifs[_motif]}).$lien Si besoin appelez le 185 ou le 180.',
    );
    if (!ouvert) _dialogue('Pas de connexion', 'Envoyez un SMS à vos complices ou appelez le 185 (SAMU) / 180 (pompiers).');
  }

  void _dialogue(String titre, String texte, {bool actionCompte = false}) {
    if (!mounted) return;
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(titre),
        content: Text(texte),
        actions: [
          TextButton(onPressed: () => appeler('185'), child: const Text('Appeler le 185')),
          if (actionCompte)
            FilledButton(
              onPressed: () {
                Navigator.pop(context);
                Navigator.push(context, MaterialPageRoute(builder: (_) => const CompteScreen()));
              },
              child: const Text('Me connecter'),
            )
          else
            TextButton(onPressed: () => Navigator.pop(context), child: const Text('Fermer')),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final connecte = Session.instance.connecte;

    return Scaffold(
      appBar: const BarreTitre('SOS'),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(18, 8, 18, 28),
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Text(
              _envoi
                  ? 'Envoi de l\'alerte…'
                  : connecte
                      ? 'Maintenez le bouton 3 secondes pour alerter $_nbComplices complice${_nbComplices > 1 ? 's' : ''}'
                      : 'Connectez-vous et ajoutez vos complices pour pouvoir les alerter.',
              textAlign: TextAlign.center,
              style: const TextStyle(color: Couleurs.texteSecondaire, fontSize: 15, height: 1.35),
            ),
          ),
          const SizedBox(height: 4),
          Center(child: _bouton()),
          const SizedBox(height: 4),
          Container(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), boxShadow: Couleurs.ombre),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('Motif de l\'alerte', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14)),
              const SizedBox(height: 8),
              DropdownButtonFormField<String>(
                initialValue: _motif,
                isExpanded: true,
                borderRadius: BorderRadius.circular(12),
                icon: const Icon(Icons.expand_more_rounded, color: Couleurs.texteSecondaire),
                decoration: const InputDecoration(prefixIcon: Icon(Icons.medical_information_outlined, size: 20, color: Couleurs.vert)),
                items: [for (final e in Config.motifs.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
                onChanged: (v) => setState(() => _motif = v ?? _motif),
              ),
            ]),
          ),
          const SizedBox(height: 14),
          if (connecte && _nbComplices == 0)
            const Bandeau('Aucun complice n\'a encore accepté votre invitation.', icone: Icons.group_add),
          if (connecte && _nbComplices == 0)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48), backgroundColor: Colors.white),
                onPressed: () async {
                  await Navigator.push(context, MaterialPageRoute(builder: (_) => const ComplicesScreen()));
                  _rafraichirComplices();
                },
                icon: const Icon(Icons.group_add_rounded, size: 18),
                label: const Text('Ajouter mes complices'),
              ),
            ),
          const Info('', enrichi: TextSpan(children: [
            TextSpan(text: 'BRAVIDO ne remplace pas les secours. Pour une urgence vitale, appelez le '),
            TextSpan(text: '185', style: TextStyle(fontWeight: FontWeight.w800)),
            TextSpan(text: ' (SAMU) ou le '),
            TextSpan(text: '180', style: TextStyle(fontWeight: FontWeight.w800)),
            TextSpan(text: ' (pompiers).'),
          ])),
          const SizedBox(height: 2),
          const Row(children: [
            Expanded(child: BoutonUrgence('185', 'SAMU', plein: false)),
            SizedBox(width: 12),
            Expanded(child: BoutonUrgence('180', 'Pompiers', plein: false)),
          ]),
        ],
      ),
    );
  }

  Widget _bouton() {
    const diametre = 176.0;
    return Semantics(
      button: true,
      label: 'Bouton SOS. Maintenir 3 secondes pour alerter vos complices.',
      child: Listener(
        onPointerDown: (_) {
          if (_envoi) return;
          _appuyer(true);
          _appui.forward(from: 0);
        },
        onPointerUp: (_) {
          _appuyer(false);
          if (_appui.status != AnimationStatus.completed) _appui.reset();
        },
        onPointerCancel: (_) {
          _appuyer(false);
          _appui.reset();
        },
        child: AnimatedBuilder(
          animation: Listenable.merge([_appui, _pouls]),
          builder: (context, _) => SizedBox(
            width: 272,
            height: 272,
            child: Stack(alignment: Alignment.center, children: [
              // Halo : deux anneaux décalés d'une demi-période
              if (_pouls.isAnimating)
                for (final decalage in const [0.0, 0.5]) _anneau((_pouls.value + decalage) % 1, diametre),
              SizedBox(
                width: diametre + 26,
                height: diametre + 26,
                child: CircularProgressIndicator(
                  value: _appui.value,
                  strokeWidth: 7,
                  strokeCap: StrokeCap.round,
                  color: Couleurs.vert,
                  backgroundColor: Couleurs.rouge.withValues(alpha: 0.14),
                ),
              ),
              AnimatedScale(
                scale: _presse ? 0.96 : 1,
                duration: const Duration(milliseconds: 140),
                child: Container(
                  width: diametre,
                  height: diametre,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: _envoi
                        ? const RadialGradient(colors: [Color(0xFFB7C0C4), Color(0xFF8A969C)])
                        : const RadialGradient(
                            center: Alignment(-0.35, -0.45),
                            radius: 1.05,
                            colors: [Color(0xFFFF6B5E), Couleurs.rouge, Couleurs.rougeFonce],
                            stops: [0, 0.55, 1],
                          ),
                    border: Border.all(color: Colors.white.withValues(alpha: 0.55), width: 3),
                    boxShadow: _envoi
                        ? const []
                        : const [
                            BoxShadow(color: Color(0x59E03131), blurRadius: 30, spreadRadius: 2, offset: Offset(0, 12)),
                            BoxShadow(color: Color(0x26E03131), blurRadius: 8, offset: Offset(0, 2)),
                          ],
                  ),
                  alignment: Alignment.center,
                  child: _envoi
                      ? const CircularProgressIndicator(color: Colors.white)
                      : const Column(mainAxisSize: MainAxisSize.min, children: [
                          Text('SOS',
                              style: TextStyle(color: Colors.white, fontSize: 44, fontWeight: FontWeight.w900, letterSpacing: 2, height: 1)),
                          SizedBox(height: 6),
                          Row(mainAxisSize: MainAxisSize.min, children: [
                            Icon(Icons.touch_app_rounded, color: Colors.white, size: 15),
                            SizedBox(width: 4),
                            Text('Maintenir 3 s', style: TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600)),
                          ]),
                        ]),
                ),
              ),
            ]),
          ),
        ),
      ),
    );
  }

  /// Anneau du halo : grandit de 1 à 1,5 fois le bouton en s'effaçant.
  Widget _anneau(double t, double diametre) {
    final taille = diametre * (1 + 0.5 * Curves.easeOut.transform(t));
    return IgnorePointer(
      child: Container(
        width: taille,
        height: taille,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: Couleurs.rouge.withValues(alpha: 0.16 * (1 - t)),
          border: Border.all(color: Couleurs.rouge.withValues(alpha: 0.30 * (1 - t)), width: 1.5),
        ),
      ),
    );
  }
}

/// Appel direct d'un numéro d'urgence : pictogramme + numéro + libellé (jamais la couleur seule).
class BoutonUrgence extends StatelessWidget {
  const BoutonUrgence(this.numero, this.libelle, {super.key, this.plein = true});

  final String numero;
  final String libelle;

  /// Plein : fond rouge, texte blanc. Sinon : carte blanche, texte rouge.
  final bool plein;

  @override
  Widget build(BuildContext context) {
    final encre = plein ? Colors.white : Couleurs.rougeFonce;
    return Semantics(
      button: true,
      label: 'Appeler le $numero, $libelle',
      child: Material(
        color: plein ? Couleurs.rouge : Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: plein ? BorderSide.none : const BorderSide(color: Color(0xFFF3C4C0)),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () => appeler(numero),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 52),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              child: ExcludeSemantics(
                child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                  Icon(Icons.call_rounded, size: 18, color: encre),
                  const SizedBox(width: 8),
                  Flexible(
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      child: Text('$libelle · $numero', maxLines: 1, style: TextStyle(color: encre, fontWeight: FontWeight.w800, fontSize: 14.5)),
                    ),
                  ),
                ]),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

int nombreComplicesAcceptes() =>
    (Cache.lireListe('complices') ?? const []).where((c) => c is Map && c['statut'] == 'accepte').length;

/// URG-02 : compte à rebours de 5 s annulable avant l'envoi.
class _CompteARebours extends StatefulWidget {
  const _CompteARebours();

  @override
  State<_CompteARebours> createState() => _CompteAReboursState();
}

class _CompteAReboursState extends State<_CompteARebours> {
  int _reste = 5;
  Timer? _minuteur;

  @override
  void initState() {
    super.initState();
    _minuteur = Timer.periodic(const Duration(seconds: 1), (t) {
      if (_reste <= 1) {
        t.cancel();
        Navigator.pop(context, true);
      } else {
        HapticFeedback.selectionClick();
        setState(() => _reste--);
      }
    });
  }

  @override
  void dispose() {
    _minuteur?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(24, 24, 24, 20),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Text('Envoi de l\'alerte dans', style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 15)),
          const SizedBox(height: 14),
          Container(
            width: 112,
            height: 112,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: Couleurs.rougeClair,
              shape: BoxShape.circle,
              border: Border.all(color: Couleurs.rouge.withValues(alpha: 0.25), width: 3),
            ),
            child: Text('$_reste', style: const TextStyle(color: Couleurs.rougeFonce, fontSize: 56, fontWeight: FontWeight.w900, height: 1)),
          ),
          const SizedBox(height: 18),
          SizedBox(
            width: double.infinity,
            child: FilledButton.tonal(
              style: FilledButton.styleFrom(
                backgroundColor: Couleurs.gris,
                foregroundColor: Couleurs.texte,
                minimumSize: const Size.fromHeight(50),
              ),
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Annuler', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
            ),
          ),
        ]),
      ),
    );
  }
}
