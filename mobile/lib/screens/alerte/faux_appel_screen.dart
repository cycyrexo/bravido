import 'dart:async';
import '../../core/langue.dart';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../app.dart';
import '../../core/reglages.dart';
import '../../core/sos_silencieux.dart';
import '../../widgets/communs.dart';

/// Faux appel + mode discret : un faux appel entrant pour quitter une situation gênante.
/// Geste caché pendant la « conversation » : appui long de 2 s sur le chronomètre = vraie alerte SOS, sans aucun signal.
/// Honnêteté : BRAVIDO n'est pas invisible ; cette fonction aide à gagner du temps, elle ne remplace pas les secours.
class FauxAppelScreen extends StatefulWidget {
  const FauxAppelScreen({super.key});

  @override
  State<FauxAppelScreen> createState() => _FauxAppelScreenState();
}

class _FauxAppelScreenState extends State<FauxAppelScreen> {
  late final TextEditingController _nom = TextEditingController(text: Reglages.instance.appelant);
  late bool _sonnerie = Reglages.instance.sonnerie;
  late bool _vibration = Reglages.instance.vibration;
  int _delai = 0;
  int _attente = 0;
  Timer? _minuteur;

  @override
  void dispose() {
    _minuteur?.cancel();
    _nom.dispose();
    super.dispose();
  }

  Future<void> _lancer() async {
    final r = Reglages.instance;
    await r.definirAppelant(_nom.text);
    await r.definirSonnerie(_sonnerie);
    await r.definirVibration(_vibration);
    if (_delai == 0) return _afficherAppel();
    setState(() => _attente = _delai);
    _minuteur = Timer.periodic(const Duration(seconds: 1), (t) {
      if (_attente <= 1) {
        t.cancel();
        setState(() => _attente = 0);
        _afficherAppel();
      } else {
        setState(() => _attente--);
      }
    });
  }

  void _annulerAttente() {
    _minuteur?.cancel();
    setState(() => _attente = 0);
  }

  Future<void> _afficherAppel() async {
    if (!mounted) return;
    await Navigator.of(context, rootNavigator: true).push(PageRouteBuilder<void>(
      opaque: true,
      transitionDuration: Duration.zero,
      pageBuilder: (_, __, ___) => const _AppelEntrant(),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: BarreTitre(tr('Faux appel', 'Fake call')),
      body: ListView(padding: const EdgeInsets.fromLTRB(18, 8, 18, 28), children: [
        Info(tr('Simule un appel entrant pour vous aider à quitter une situation inconfortable. '
            'Ce n\'est pas un appel réel. Pendant la fausse conversation, un appui long de 2 secondes sur le chronomètre '
            'déclenche une vraie alerte, sans son ni vibration.',
            'Simulates an incoming call to help you leave an uncomfortable situation. '
            'It is not a real call. During the fake conversation, a 2-second long press on the timer '
            'triggers a real alert, with no sound or vibration.')),
        TitreSection(tr('Nom de l\'appelant', 'Caller name')),
        TextField(
          controller: _nom,
          maxLength: 30,
          textCapitalization: TextCapitalization.words,
          decoration: InputDecoration(prefixIcon: const Icon(Icons.person_outline_rounded), hintText: tr('Maman', 'Mom'), counterText: ''),
        ),
        TitreSection(tr('Déclenchement', 'Trigger')),
        Puces<int>(
          options: {0: tr('Maintenant', 'Now'), 10: tr('Dans 10 s', 'In 10 s'), 30: tr('Dans 30 s', 'In 30 s'), 60: tr('Dans 1 min', 'In 1 min')},
          valeur: _delai,
          onChanged: (v) => setState(() => _delai = v),
        ),
        const SizedBox(height: 8),
        Card(
          child: Column(children: [
            SwitchListTile(
              value: _sonnerie,
              onChanged: (v) => setState(() => _sonnerie = v),
              title: Text(tr('Sonnerie', 'Ringtone'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(tr('Son d\'alerte du système, répété pendant l\'appel entrant', 'System alert sound, repeated during the incoming call')),
            ),
            SwitchListTile(
              value: _vibration,
              onChanged: (v) => setState(() => _vibration = v),
              title: Text(tr('Vibration', 'Vibration'), style: const TextStyle(fontWeight: FontWeight.w700)),
            ),
          ]),
        ),
        TitreSection(tr(tr('Mode discret', 'Discreet mode'), 'Discreet mode')),
        const CarteModeDiscret(),
        const SizedBox(height: 18),
        if (_attente > 0) ...[
          Center(child: Text(tr('Appel dans $_attente s', 'Call in $_attente s'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))),
          const SizedBox(height: 10),
          OutlinedButton(
            style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(50), backgroundColor: Couleurs.surface),
            onPressed: _annulerAttente,
            child: Text(tr('Annuler', 'Cancel')),
          ),
        ] else
          FilledButton.icon(
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(54), backgroundColor: Couleurs.vert),
            onPressed: _lancer,
            icon: const Icon(Icons.phone_callback_rounded),
            label: Text(tr('Lancer le faux appel', 'Start the fake call'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
          ),
        const SizedBox(height: 12),
        Text(
          tr('BRAVIDO ne remplace pas les secours : en cas d\'urgence, appelez le 185 (SAMU) ou le 180 (pompiers).', 'BRAVIDO does not replace emergency services: in an emergency, call 185 (SAMU) or 180 (fire brigade).'),
          textAlign: TextAlign.center,
          style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 12.5),
        ),
      ]),
    );
  }
}

/// Interrupteur « Mode discret » avec son explication honnête (réutilisé dans l'Espace utilisateur).
class CarteModeDiscret extends StatelessWidget {
  const CarteModeDiscret({super.key});

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: Reglages.instance,
      builder: (context, _) => Card(
        child: SwitchListTile(
          value: Reglages.instance.modeDiscret,
          onChanged: Reglages.instance.definirModeDiscret,
          title: Text(tr('Mode discret', 'Discreet mode'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
          subtitle: Text(tr('Le compte à rebours du SOS devient silencieux : 3 s, sans vibration ni son, sur un écran sombre et neutre. '
              'L\'application reste visible sur votre téléphone (elle n\'est pas invisible) et l\'alerte est bien envoyée.',
              'The SOS countdown becomes silent: 3 s, no vibration or sound, on a dark neutral screen. '
              'The app stays visible on your phone (it is not invisible) and the alert is still sent.')),
        ),
      ),
    );
  }
}

/// Écran d'appel entrant, volontairement générique (pas de marque d'opérateur).
class _AppelEntrant extends StatefulWidget {
  const _AppelEntrant();

  @override
  State<_AppelEntrant> createState() => _AppelEntrantState();
}

class _AppelEntrantState extends State<_AppelEntrant> with SingleTickerProviderStateMixin {
  late final AnimationController _pouls = AnimationController(vsync: this, duration: const Duration(milliseconds: 1600))..repeat();
  Timer? _sonnerie;

  @override
  void initState() {
    super.initState();
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
    final r = Reglages.instance;
    if (r.sonnerie || r.vibration) {
      void tic() {
        if (r.sonnerie) SystemSound.play(SystemSoundType.alert);
        if (r.vibration) HapticFeedback.vibrate();
      }

      tic();
      _sonnerie = Timer.periodic(const Duration(milliseconds: 2200), (_) => tic());
    }
  }

  @override
  void dispose() {
    _sonnerie?.cancel();
    _pouls.dispose();
    super.dispose();
  }

  void _refuser() {
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
    Navigator.pop(context);
  }

  void _repondre() {
    _sonnerie?.cancel();
    Navigator.pushReplacement(
      context,
      PageRouteBuilder<void>(transitionDuration: Duration.zero, pageBuilder: (_, __, ___) => const _Conversation()),
    );
  }

  @override
  Widget build(BuildContext context) {
    final nom = Reglages.instance.appelant;
    return PopScope(
      canPop: false,
      child: Scaffold(
        backgroundColor: const Color(0xFF14202A),
        body: SafeArea(
          child: Column(children: [
            const SizedBox(height: 56),
            Text(tr('Appel entrant', 'Incoming call'), style: const TextStyle(color: Colors.white70, fontSize: 16)),
            const SizedBox(height: 10),
            Text(nom, style: const TextStyle(color: Colors.white, fontSize: 36, fontWeight: FontWeight.w500)),
            const SizedBox(height: 6),
            Text(tr('mobile', 'mobile'), style: const TextStyle(color: Colors.white60, fontSize: 15)),
            const Spacer(),
            AnimatedBuilder(
              animation: _pouls,
              builder: (_, __) => SizedBox(
                width: 200,
                height: 200,
                child: Stack(alignment: Alignment.center, children: [
                  for (final d in const [0.0, 0.5])
                    Container(
                      width: 110 + 90 * ((_pouls.value + d) % 1),
                      height: 110 + 90 * ((_pouls.value + d) % 1),
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: Colors.white.withValues(alpha: 0.10 * (1 - ((_pouls.value + d) % 1))),
                      ),
                    ),
                  CircleAvatar(
                    radius: 54,
                    backgroundColor: const Color(0xFF3A4C5A),
                    child: Text(_initiale(nom), style: const TextStyle(color: Colors.white, fontSize: 44, fontWeight: FontWeight.w300)),
                  ),
                ]),
              ),
            ),
            const Spacer(),
            Padding(
              padding: const EdgeInsets.fromLTRB(40, 0, 40, 56),
              child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                _BoutonAppel(couleur: const Color(0xFFE5484D), icone: Icons.call_end_rounded, libelle: 'Refuser', onTap: _refuser),
                _BoutonAppel(couleur: const Color(0xFF30A46C), icone: Icons.call_rounded, libelle: 'Répondre', onTap: _repondre),
              ]),
            ),
          ]),
        ),
      ),
    );
  }

  String _initiale(String nom) => nom.isEmpty ? '?' : nom.characters.first.toUpperCase();
}

class _BoutonAppel extends StatelessWidget {
  const _BoutonAppel({required this.couleur, required this.icone, required this.libelle, required this.onTap});

  final Color couleur;
  final IconData icone;
  final String libelle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: libelle,
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Material(
          color: couleur,
          shape: const CircleBorder(),
          child: InkWell(
            customBorder: const CircleBorder(),
            onTap: onTap,
            child: SizedBox(width: 72, height: 72, child: Icon(icone, color: Colors.white, size: 34)),
          ),
        ),
        const SizedBox(height: 8),
        Text(libelle, style: const TextStyle(color: Colors.white70, fontSize: 14)),
      ]),
    );
  }
}

/// Fausse conversation : chronomètre + boutons factices. Appui long de 2 s sur le chronomètre = SOS silencieux.
class _Conversation extends StatefulWidget {
  const _Conversation();

  @override
  State<_Conversation> createState() => _ConversationState();
}

class _ConversationState extends State<_Conversation> {
  int _secondes = 0;
  Timer? _tic;
  Timer? _appuiLong;
  bool _muet = false;
  bool _hautParleur = false;

  @override
  void initState() {
    super.initState();
    _tic = Timer.periodic(const Duration(seconds: 1), (_) => setState(() => _secondes++));
  }

  @override
  void dispose() {
    _tic?.cancel();
    _appuiLong?.cancel();
    super.dispose();
  }

  /// Aucun retour : ni son, ni vibration, ni changement d'écran.
  void _debutAppui() {
    _appuiLong?.cancel();
    _appuiLong = Timer(const Duration(seconds: 2), () => SosSilencieux.envoyer());
  }

  void _finAppui() => _appuiLong?.cancel();

  void _raccrocher() {
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
    Navigator.pop(context);
  }

  String get _chrono {
    final m = (_secondes ~/ 60).toString().padLeft(2, '0');
    final s = (_secondes % 60).toString().padLeft(2, '0');
    return '$m:$s';
  }

  @override
  Widget build(BuildContext context) {
    final nom = Reglages.instance.appelant;
    return PopScope(
      canPop: false,
      child: Scaffold(
        backgroundColor: const Color(0xFF14202A),
        body: SafeArea(
          child: Column(children: [
            const SizedBox(height: 56),
            Text(nom, style: const TextStyle(color: Colors.white, fontSize: 34, fontWeight: FontWeight.w500)),
            const SizedBox(height: 8),
            // Zone du geste caché : le chronomètre
            Listener(
              onPointerDown: (_) => _debutAppui(),
              onPointerUp: (_) => _finAppui(),
              onPointerCancel: (_) => _finAppui(),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 40, vertical: 8),
                child: Text(_chrono, style: const TextStyle(color: Colors.white70, fontSize: 18, fontFeatures: [FontFeature.tabularFigures()])),
              ),
            ),
            const Spacer(),
            Row(mainAxisAlignment: MainAxisAlignment.spaceEvenly, children: [
              _Option(icone: _muet ? Icons.mic_off_rounded : Icons.mic_rounded, libelle: tr('Muet', 'Mute'), actif: _muet, onTap: () => setState(() => _muet = !_muet)),
              _Option(icone: Icons.dialpad_rounded, libelle: tr('Clavier', 'Keypad'), actif: false, onTap: () {}),
              _Option(
                  icone: Icons.volume_up_rounded,
                  libelle: tr('Haut-parleur', 'Speaker'),
                  actif: _hautParleur,
                  onTap: () => setState(() => _hautParleur = !_hautParleur)),
            ]),
            const SizedBox(height: 56),
            Material(
              color: const Color(0xFFE5484D),
              shape: const CircleBorder(),
              child: InkWell(
                customBorder: const CircleBorder(),
                onTap: _raccrocher,
                child: const SizedBox(width: 72, height: 72, child: Icon(Icons.call_end_rounded, color: Colors.white, size: 34)),
              ),
            ),
            const SizedBox(height: 56),
          ]),
        ),
      ),
    );
  }
}

class _Option extends StatelessWidget {
  const _Option({required this.icone, required this.libelle, required this.actif, required this.onTap});

  final IconData icone;
  final String libelle;
  final bool actif;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Column(mainAxisSize: MainAxisSize.min, children: [
      Material(
        color: actif ? Colors.white : Colors.white12,
        shape: const CircleBorder(),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: onTap,
          child: SizedBox(width: 62, height: 62, child: Icon(icone, color: actif ? const Color(0xFF14202A) : Colors.white, size: 26)),
        ),
      ),
      const SizedBox(height: 6),
      Text(libelle, style: const TextStyle(color: Colors.white70, fontSize: 12.5)),
    ]);
  }
}
