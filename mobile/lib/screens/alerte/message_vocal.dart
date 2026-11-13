import 'dart:async';
import '../../core/langue.dart';
import 'dart:io';

import 'package:audioplayers/audioplayers.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:path_provider/path_provider.dart';
import 'package:record/record.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../widgets/communs.dart';

/// Durée maximale d'un message vocal (aussi appliquée côté serveur).
const dureeMaxMessageVocal = 30;

/// Bouton « Enregistrer un message vocal » (personne en alerte).
/// L'enregistrement ne démarre JAMAIS automatiquement : il faut toucher ce bouton, puis une fenêtre
/// bien visible (point rouge, chronomètre, bouton Arrêter) reste affichée pendant toute la prise de son.
class BoutonMessageVocal extends StatelessWidget {
  const BoutonMessageVocal({super.key, required this.alerteId, required this.onEnvoye});

  final int alerteId;
  final VoidCallback onEnvoye;

  @override
  Widget build(BuildContext context) {
    return OutlinedButton.icon(
      style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(50), backgroundColor: Couleurs.surface),
      onPressed: () async {
        final envoye = await showModalBottomSheet<bool>(
          context: context,
          isDismissible: false,
          enableDrag: false,
          showDragHandle: false,
          builder: (_) => FeuilleEnregistrement(alerteId: alerteId),
        );
        if (envoye == true) onEnvoye();
      },
      icon: Icon(Icons.mic_rounded, size: 20, color: Couleurs.encreRouge),
      label: Text(tr('Enregistrer un message vocal', 'Record a voice message')),
    );
  }
}

enum _Etape { attente, enregistrement, envoi }

/// Fenêtre d'enregistrement : indicateur visible permanent, 30 s maximum.
class FeuilleEnregistrement extends StatefulWidget {
  const FeuilleEnregistrement({super.key, required this.alerteId});

  final int alerteId;

  @override
  State<FeuilleEnregistrement> createState() => _FeuilleEnregistrementState();
}

class _FeuilleEnregistrementState extends State<FeuilleEnregistrement> with SingleTickerProviderStateMixin {
  final AudioRecorder _enregistreur = AudioRecorder();
  late final AnimationController _clignote = AnimationController(vsync: this, duration: const Duration(milliseconds: 900))..repeat(reverse: true);
  _Etape _etape = _Etape.attente;
  int _ecoule = 0;
  Timer? _minuteur;
  String? _chemin;
  String? _erreur;

  @override
  void dispose() {
    _minuteur?.cancel();
    _clignote.dispose();
    _enregistreur.cancel().whenComplete(_enregistreur.dispose);
    super.dispose();
  }

  Future<void> _demarrer() async {
    setState(() => _erreur = null);
    try {
      if (!await _enregistreur.hasPermission()) {
        setState(() => _erreur = tr('Autorisez le micro dans les réglages du téléphone pour enregistrer un message.', 'Allow the microphone in the phone settings to record a message.'));
        return;
      }
      final dossier = await getTemporaryDirectory();
      _chemin = '${dossier.path}/message_${DateTime.now().millisecondsSinceEpoch}.m4a';
      await _enregistreur.start(
        const RecordConfig(encoder: AudioEncoder.aacLc, bitRate: 32000, sampleRate: 16000, numChannels: 1),
        path: _chemin!,
      );
      if (!mounted) return;
      setState(() {
        _etape = _Etape.enregistrement;
        _ecoule = 0;
      });
      _minuteur = Timer.periodic(const Duration(seconds: 1), (_) {
        if (!mounted) return;
        setState(() => _ecoule++);
        if (_ecoule >= dureeMaxMessageVocal) _arreterEtEnvoyer();
      });
    } catch (e) {
      if (mounted) setState(() => _erreur = tr('Enregistrement impossible sur cet appareil.', 'Recording is not possible on this device.'));
    }
  }

  Future<void> _arreterEtEnvoyer() async {
    if (_etape != _Etape.enregistrement) return;
    _minuteur?.cancel();
    final duree = _ecoule.clamp(1, dureeMaxMessageVocal);
    setState(() => _etape = _Etape.envoi);
    try {
      final chemin = await _enregistreur.stop() ?? _chemin;
      if (chemin == null) throw ApiException('Aucun enregistrement.');
      final fichier = File(chemin);
      await Api.instance.post(
        '/alertes/${widget.alerteId}/audio',
        FormData.fromMap({
          'duree_s': duree,
          'fichier': await MultipartFile.fromFile(chemin, filename: 'message.m4a', contentType: DioMediaType('audio', 'mp4')),
        }),
      );
      await fichier.delete().catchError((_) => fichier);
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
        _etape = _Etape.attente;
        _erreur = e.message;
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() {
        _etape = _Etape.attente;
        _erreur = tr('Envoi impossible. Réessayez.', 'Could not send. Try again.');
        });
      }
    }
  }

  Future<void> _annuler() async {
    _minuteur?.cancel();
    await _enregistreur.cancel();
    if (mounted) Navigator.pop(context, false);
  }

  String _mmss(int s) => '${(s ~/ 60).toString().padLeft(2, '0')}:${(s % 60).toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    final enCours = _etape == _Etape.enregistrement;

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 22, 20, 20),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          if (enCours || _etape == _Etape.envoi) ...[
            Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              FadeTransition(
                opacity: _clignote.drive(Tween(begin: 0.25, end: 1.0)),
                child: Container(width: 16, height: 16, decoration: const BoxDecoration(color: Couleurs.rouge, shape: BoxShape.circle)),
              ),
              const SizedBox(width: 10),
              Text(
                enCours ? tr('ENREGISTREMENT EN COURS', 'RECORDING IN PROGRESS') : tr('Envoi du message…', 'Sending the message…'),
                style: TextStyle(color: Couleurs.encreRouge, fontWeight: FontWeight.w900, letterSpacing: 0.6),
              ),
            ]),
            const SizedBox(height: 14),
            Text(
              '${_mmss(_ecoule)} / ${_mmss(dureeMaxMessageVocal)}',
              style: const TextStyle(fontSize: 44, fontWeight: FontWeight.w800, fontFeatures: [FontFeature.tabularFigures()]),
            ),
            const SizedBox(height: 6),
            LinearProgressIndicator(
              value: _ecoule / dureeMaxMessageVocal,
              color: Couleurs.rouge,
              backgroundColor: Couleurs.rougeClair,
              minHeight: 6,
              borderRadius: BorderRadius.circular(99),
            ),
            const SizedBox(height: 18),
            if (enCours)
              FilledButton.icon(
                style: FilledButton.styleFrom(backgroundColor: Couleurs.rouge, minimumSize: const Size.fromHeight(52)),
                onPressed: _arreterEtEnvoyer,
                icon: const Icon(Icons.stop_rounded),
                label: Text(tr('Arrêter et envoyer', 'Stop and send')),
              )
            else
              const Padding(padding: EdgeInsets.all(12), child: CircularProgressIndicator()),
            if (enCours)
              TextButton(onPressed: _annuler, child: Text(tr('Annuler, ne rien envoyer', 'Cancel, send nothing'))),
          ] else ...[
            Icon(Icons.mic_none_rounded, size: 44, color: Couleurs.encreRouge),
            const SizedBox(height: 10),
            Text(tr('Message vocal pour vos complices', 'Voice message for your accomplices'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
            const SizedBox(height: 6),
            Text(
              tr('L\'enregistrement ne démarre que lorsque vous touchez « Commencer », dure $dureeMaxMessageVocal secondes au maximum '
              'et n\'est envoyé qu\'à vos complices prévenus. Il est supprimé après 30 jours.',
              'Recording only starts when you tap "Start", lasts $dureeMaxMessageVocal seconds at most '
              'and is sent only to your alerted accomplices. It is deleted after 30 days.'),
              textAlign: TextAlign.center,
              style: TextStyle(color: Couleurs.texteSecondaire, fontSize: 13.5, height: 1.35),
            ),
            if (_erreur != null) ...[
              const SizedBox(height: 10),
              Text(_erreur!, textAlign: TextAlign.center, style: TextStyle(color: Couleurs.encreRouge, fontWeight: FontWeight.w600)),
            ],
            const SizedBox(height: 16),
            FilledButton.icon(
              style: FilledButton.styleFrom(backgroundColor: Couleurs.rouge, minimumSize: const Size.fromHeight(52)),
              onPressed: _demarrer,
              icon: const Icon(Icons.fiber_manual_record_rounded, size: 18),
              label: Text(tr('Commencer l\'enregistrement', 'Start recording')),
            ),
            TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Fermer', 'Close'))),
          ],
        ]),
      ),
    );
  }
}

/// Liste des messages vocaux d'une alerte avec lecture (complice ou propriétaire).
class ListeMessagesVocaux extends StatefulWidget {
  const ListeMessagesVocaux({super.key, required this.alerteId, required this.audios});

  final int alerteId;
  final List<Map> audios;

  @override
  State<ListeMessagesVocaux> createState() => _ListeMessagesVocauxState();
}

class _ListeMessagesVocauxState extends State<ListeMessagesVocaux> {
  final AudioPlayer _lecteur = AudioPlayer();
  int? _enLecture;
  int? _enChargement;
  StreamSubscription<void>? _fin;

  @override
  void initState() {
    super.initState();
    _fin = _lecteur.onPlayerComplete.listen((_) {
      if (mounted) setState(() => _enLecture = null);
    });
  }

  @override
  void dispose() {
    _fin?.cancel();
    _lecteur.dispose();
    super.dispose();
  }

  Future<void> _basculer(int audioId) async {
    if (_enLecture == audioId) {
      await _lecteur.stop();
      setState(() => _enLecture = null);
      return;
    }
    setState(() => _enChargement = audioId);
    try {
      // Téléchargement authentifié (le fichier n'est jamais public), puis lecture locale
      final octets = await Api.instance.telecharger('/alertes/${widget.alerteId}/audio/$audioId');
      final dossier = await getTemporaryDirectory();
      final fichier = File('${dossier.path}/ecoute_$audioId.m4a');
      await fichier.writeAsBytes(octets, flush: true);
      await _lecteur.stop();
      await _lecteur.play(DeviceFileSource(fichier.path));
      if (mounted) setState(() => _enLecture = audioId);
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    } catch (_) {
      if (mounted) afficherMessage(context, tr('Lecture impossible.', 'Playback failed.'));
    } finally {
      if (mounted) setState(() => _enChargement = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      TitreSection(tr('Messages vocaux', 'Voice messages')),
      for (var i = 0; i < widget.audios.length; i++)
        Builder(builder: (context) {
          final a = widget.audios[i];
          final id = (a['id'] as num).toInt();
          final lecture = _enLecture == id;
          return Card(
            child: ListTile(
              leading: _enChargement == id
                  ? const SizedBox(width: 28, height: 28, child: CircularProgressIndicator(strokeWidth: 2.5))
                  : Icon(lecture ? Icons.stop_circle_rounded : Icons.play_circle_fill_rounded, size: 32, color: Couleurs.accent),
              title: Text(tr('Message vocal ${i + 1}', 'Voice message ${i + 1}'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text([
                if (a['duree_s'] != null) '${a['duree_s']} s',
                if (a['created_at'] != null) formatHeureIso('${a['created_at']}'),
              ].join(' · ')),
              onTap: _enChargement != null ? null : () => _basculer(id),
            ),
          );
        }),
    ]);
  }
}

String formatHeureIso(String iso) {
  final d = DateTime.tryParse(iso)?.toLocal();
  return d == null ? '' : '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
}
