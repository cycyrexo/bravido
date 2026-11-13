import 'dart:async';
import '../../core/langue.dart';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:geolocator/geolocator.dart';

import '../../app.dart';
import '../../config.dart';
import '../../core/api.dart';
import '../../core/cache.dart';
import '../../core/outils.dart';
import '../../core/reglages.dart';
import '../../core/session.dart';
import '../../core/sos_silencieux.dart';
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
    final discret = Reglages.instance.modeDiscret;
    if (!discret) HapticFeedback.heavyImpact();
    _appui.reset();
    if (discret) {
      // Mode discret : compte à rebours silencieux de 3 s sur un écran sombre et neutre
      final confirme = await Navigator.push<bool>(
        context,
        PageRouteBuilder<bool>(opaque: true, transitionDuration: Duration.zero, pageBuilder: (_, __, ___) => const _CompteAReboursDiscret()),
      );
      if (confirme == true) await _envoyerDiscret();
      return;
    }
    final confirme = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (_) => const _CompteARebours(),
    );
    if (confirme == true) await _envoyer();
  }

  /// Envoi sans son, vibration ni changement d'écran. Un échec reste visible (on ne cache jamais une alerte non partie).
  Future<void> _envoyerDiscret() async {
    if (!Session.instance.connecte) return _envoyer();
    final id = await SosSilencieux.envoyer(motif: _motif);
    if (id == null && mounted) await _envoyer();
  }

  Future<void> _envoyer() async {
    if (!Session.instance.connecte) {
      _dialogue(tr('Connectez-vous pour prévenir vos complices', 'Sign in to alert your accomplices'),
          tr('L\'alerte aux complices nécessite un compte. En cas d\'urgence, appelez le 185 (SAMU) ou le 180 (pompiers).', 'Alerting accomplices requires an account. In an emergency, call 185 (SAMU) or 180 (fire brigade).'),
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
        _dialogue(tr('Alerte non envoyée', 'Alert not sent'), e.message);
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
      _dialogue(tr('Pas de connexion', 'No connection'), tr('Impossible de joindre vos complices. Appelez le 185 (SAMU) ou le 180 (pompiers).', 'Could not reach your accomplices. Call 185 (SAMU) or 180 (fire brigade).'));
      return;
    }
    final lien = p == null ? '' : ' Ma position : https://maps.google.com/?q=${p.latitude},${p.longitude}';
    final ouvert = await composerSms(
      numeros,
      'ALERTE BRAVIDO : j\'ai besoin d\'aide (${Config.motifs[_motif]}).$lien Si besoin appelez le 185 ou le 180.',
    );
    if (!ouvert) _dialogue(tr('Pas de connexion', 'No connection'), tr('Envoyez un SMS à vos complices ou appelez le 185 (SAMU) / 180 (pompiers).', 'Send an SMS to your accomplices or call 185 (SAMU) / 180 (fire brigade).'));
  }

  void _dialogue(String titre, String texte, {bool actionCompte = false}) {
    if (!mounted) return;
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(titre),
        content: Text(texte),
        actions: [
          TextButton(onPressed: () => appeler('185'), child: Text(tr('Appeler le 185', 'Call 185'))),
          if (actionCompte)
            FilledButton(
              onPressed: () {
                Navigator.pop(context);
                Navigator.push(context, MaterialPageRoute(builder: (_) => const CompteScreen()));
              },
              child: Text(tr('Me connecter', 'Sign in')),
            )
          else
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer', 'Close'))),
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
                  ? tr('Envoi de l\'alerte…', 'Sending the alert…')
                  : connecte
                      ? tr('Maintenez le bouton 3 secondes pour alerter $_nbComplices complice${_nbComplices > 1 ? 's' : ''}', 'Hold the button for 3 seconds to alert $_nbComplices accomplice${_nbComplices > 1 ? 's' : ''}')
                      : tr('Connectez-vous et ajoutez vos complices pour pouvoir les alerter.', 'Sign in and add your accomplices to be able to alert them.'),
              textAlign: TextAlign.center,
              style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 15, height: 1.35),
            ),
          ),
          const SizedBox(height: 4),
          Center(child: _bouton()),
          const SizedBox(height: 4),
          Container(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
            decoration: BoxDecoration(color: Couleurs.surface, borderRadius: BorderRadius.circular(16), boxShadow: Couleurs.ombre),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr('Motif de l\'alerte', 'Reason for the alert'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14)),
              const SizedBox(height: 8),
              DropdownButtonFormField<String>(
                initialValue: _motif,
                isExpanded: true,
                borderRadius: BorderRadius.circular(12),
                icon: Icon(Icons.expand_more_rounded, color: Couleurs.texteSecondaire),
                decoration: InputDecoration(prefixIcon: Icon(Icons.medical_information_outlined, size: 20, color: Couleurs.accent)),
                items: [for (final e in Config.motifs.entries) DropdownMenuItem(value: e.key, child: Text(trd(e.value)))],
                onChanged: (v) => setState(() => _motif = v ?? _motif),
              ),
            ]),
          ),
          const SizedBox(height: 14),
          if (connecte && _nbComplices == 0)
            Bandeau(tr('Aucun complice n\'a encore accepté votre invitation.', 'No accomplice has accepted your invitation yet.'), icone: Icons.group_add),
          if (connecte && _nbComplices == 0)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48), backgroundColor: Couleurs.surface),
                onPressed: () async {
                  await Navigator.push(context, MaterialPageRoute(builder: (_) => const ComplicesScreen()));
                  _rafraichirComplices();
                },
                icon: const Icon(Icons.group_add_rounded, size: 18),
                label: Text(tr('Ajouter mes complices', 'Add my accomplices')),
              ),
            ),
          Info('', enrichi: TextSpan(children: [
            TextSpan(text: tr('BRAVIDO ne remplace pas les secours. Pour une urgence vitale, appelez le ', 'BRAVIDO does not replace emergency services. For a life-threatening emergency, call ')),
            const TextSpan(text: '185', style: TextStyle(fontWeight: FontWeight.w800)),
            TextSpan(text: tr(' (SAMU) ou le ', ' (SAMU) or ')),
            const TextSpan(text: '180', style: TextStyle(fontWeight: FontWeight.w800)),
            TextSpan(text: tr(' (pompiers).', ' (fire brigade).')),
          ])),
          const SizedBox(height: 2),
          Row(children: [
            const Expanded(child: BoutonUrgence('185', 'SAMU', plein: false)),
            const SizedBox(width: 12),
            Expanded(child: BoutonUrgence('180', tr('Pompiers', 'Fire brigade'), plein: false)),
          ]),
        ],
      ),
    );
  }

  Widget _bouton() {
    const diametre = 176.0;
    return Semantics(
      button: true,
      label: tr('Bouton SOS. Maintenir 3 secondes pour alerter vos complices.', 'SOS button. Hold for 3 seconds to alert your accomplices.'),
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
                      : Column(mainAxisSize: MainAxisSize.min, children: [
                          const Text('SOS',
                              style: TextStyle(color: Colors.white, fontSize: 44, fontWeight: FontWeight.w900, letterSpacing: 2, height: 1)),
                          const SizedBox(height: 6),
                          Row(mainAxisSize: MainAxisSize.min, children: [
                            const Icon(Icons.touch_app_rounded, color: Colors.white, size: 15),
                            const SizedBox(width: 4),
                            Text(tr('Maintenir 3 s', 'Hold 3 s'), style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600)),
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
    final encre = plein ? Colors.white : Couleurs.encreRouge;
    return Semantics(
      button: true,
      label: tr('Appeler le $numero, $libelle', 'Call $numero, $libelle'),
      child: Material(
        color: plein ? Couleurs.rouge : Couleurs.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: plein ? BorderSide.none : BorderSide(color: Couleurs.bordRouge),
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

/// Mode discret : 3 s sans son ni vibration, écran sombre et neutre. Un toucher n'importe où annule.
class _CompteAReboursDiscret extends StatefulWidget {
  const _CompteAReboursDiscret();

  @override
  State<_CompteAReboursDiscret> createState() => _CompteAReboursDiscretState();
}

class _CompteAReboursDiscretState extends State<_CompteAReboursDiscret> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(seconds: 3))
    ..addStatusListener((s) {
      if (s == AnimationStatus.completed && mounted) Navigator.pop(context, true);
    })
    ..forward();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      child: Scaffold(
        backgroundColor: const Color(0xFF0B0E10),
        body: GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTap: () => Navigator.pop(context, false),
          child: SizedBox.expand(
            child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              AnimatedBuilder(
                animation: _c,
                builder: (_, __) => SizedBox(
                  width: 40,
                  height: 40,
                  child: CircularProgressIndicator(value: _c.value, strokeWidth: 2, color: const Color(0xFF3B4449), backgroundColor: const Color(0xFF161B1E)),
                ),
              ),
              const SizedBox(height: 18),
              const Text('Touchez l\'écran pour annuler', style: TextStyle(color: Color(0xFF4A555B), fontSize: 12)),
            ]),
          ),
        ),
      ),
    );
  }
}

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
          Text(tr('Envoi de l\'alerte dans', 'Sending the alert in'), style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 15)),
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
            child: Text('$_reste', style: TextStyle(color: Couleurs.encreRouge, fontSize: 56, fontWeight: FontWeight.w900, height: 1)),
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
              child: Text(tr('Annuler', 'Cancel'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
            ),
          ),
        ]),
      ),
    );
  }
}
