import 'package:flutter/material.dart';

import '../../app.dart';
import '../../core/langue.dart';
import '../../core/rappels.dart';
import '../../core/service_rappels.dart';
import '../../widgets/communs.dart';

/// F14 : « Mes rappels » — médicaments et rendez-vous, enregistrés uniquement sur cet appareil.
class RappelsScreen extends StatefulWidget {
  const RappelsScreen({super.key});

  @override
  State<RappelsScreen> createState() => _RappelsScreenState();
}

class _RappelsScreenState extends State<RappelsScreen> with WidgetsBindingObserver {
  final _service = ServiceRappels.instance;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _service.actualiserEtat();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Retour des réglages système : l'autorisation d'alarmes exactes a pu changer
    if (state == AppLifecycleState.resumed) _service.reprogrammer();
  }

  Future<void> _ouvrirFormulaire([Rappel? r]) async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => RappelFormScreen(rappel: r)));
  }

  Future<void> _supprimer(Rappel r) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(tr('Supprimer ce rappel ?', 'Delete this reminder?')),
        content: Text(r.titre),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler', 'Cancel'))),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Supprimer', 'Delete'))),
        ],
      ),
    );
    if (ok == true) await _service.supprimer(r);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: BarreTitre(tr('Mes rappels', 'My reminders'), actions: const [SosAction()]),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _ouvrirFormulaire(),
        icon: const Icon(Icons.add_alarm_rounded),
        label: Text(tr('Nouveau rappel', 'New reminder')),
      ),
      body: ListenableBuilder(
        listenable: _service,
        builder: (context, _) {
          final maintenant = DateTime.now();
          final liste = [..._service.rappels]..sort((a, b) {
              final ea = prochainesEcheances(a, maintenant).firstOrNull, eb = prochainesEcheances(b, maintenant).firstOrNull;
              if (ea == null && eb == null) return a.titre.compareTo(b.titre);
              if (ea == null) return 1;
              if (eb == null) return -1;
              return ea.compareTo(eb);
            });
          return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 96), children: [
            Info(tr('Vos rappels restent sur ce téléphone : rien n\'est envoyé à BRAVIDO.', 'Your reminders stay on this phone: nothing is sent to BRAVIDO.')),
            if (!_service.notificationsAutorisees)
              Bandeau(tr('Les notifications sont désactivées : activez-les dans les réglages du téléphone pour recevoir vos rappels.',
                  'Notifications are turned off: enable them in the phone settings to receive your reminders.'), icone: Icons.notifications_off_outlined),
            if (!_service.alarmesExactes && _service.rappels.isNotEmpty)
              Card(
                color: Couleurs.orangeClair,
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(
                      tr('Rappels approximatifs : Android peut les décaler de quelques minutes. Pour une heure exacte, autorisez « Alarmes et rappels ».',
                          'Approximate reminders: Android may delay them by a few minutes. For exact times, allow "Alarms & reminders".'),
                      style: TextStyle(color: Couleurs.encreOrange, fontSize: 13, height: 1.35),
                    ),
                    TextButton(
                      style: TextButton.styleFrom(foregroundColor: Couleurs.encreOrange),
                      onPressed: _service.demanderAlarmesExactes,
                      child: Text(tr('Autoriser les heures exactes', 'Allow exact times')),
                    ),
                  ]),
                ),
              ),
            if (liste.isEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 40),
                child: MessageVide(
                  tr('Aucun rappel. Ajoutez vos prises de médicaments ou vos rendez-vous médicaux.', 'No reminders yet. Add your medication times or medical appointments.'),
                  icone: Icons.alarm_add_rounded,
                ),
              ),
            for (final r in liste) _CarteRappel(r, onModifier: () => _ouvrirFormulaire(r), onSupprimer: () => _supprimer(r)),
          ]);
        },
      ),
    );
  }
}

String libelleRepetition(Repetition r) => switch (r) {
      Repetition.aucune => tr('Une seule fois', 'Once'),
      Repetition.quotidien => tr('Chaque jour', 'Every day'),
      Repetition.hebdo => tr('Chaque semaine', 'Every week'),
    };

class _CarteRappel extends StatelessWidget {
  const _CarteRappel(this.r, {required this.onModifier, required this.onSupprimer});

  final Rappel r;
  final VoidCallback onModifier;
  final VoidCallback onSupprimer;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final prochaine = prochainesEcheances(r, DateTime.now()).firstOrNull;
    final medicament = r.type == TypeRappel.medicament;
    final prochainTexte = !r.actif
        ? tr('Désactivé', 'Off')
        : prochaine == null
            ? tr('Terminé', 'Finished')
            : tr('Prochain : ${libelleEcheance(prochaine, DateTime.now(), aujourdhui: "aujourd'hui", demain: "demain")}',
                'Next: ${libelleEcheance(prochaine, DateTime.now(), aujourdhui: "today", demain: "tomorrow")}');
    return Card(
      child: InkWell(
        onTap: onModifier,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 6, 12),
          child: Row(children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(color: medicament ? Couleurs.vertClair : Couleurs.bleuClair, borderRadius: BorderRadius.circular(14)),
              child: Icon(medicament ? Icons.medication_rounded : Icons.event_rounded, color: medicament ? Couleurs.vert : Couleurs.bleu),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(r.titre, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                const SizedBox(height: 2),
                Text('${r.heures.join(' · ')} · ${libelleRepetition(r.repetition)}',
                    style: TextStyle(color: theme.colorScheme.onSurfaceVariant, fontSize: 13)),
                Text(prochainTexte, style: TextStyle(color: r.actif ? Couleurs.vert : theme.colorScheme.onSurfaceVariant, fontSize: 13, fontWeight: FontWeight.w600)),
              ]),
            ),
            Column(children: [
              Switch(value: r.actif, onChanged: (v) => ServiceRappels.instance.basculer(r, v)),
            ]),
            IconButton(tooltip: tr('Supprimer', 'Delete'), icon: const Icon(Icons.delete_outline_rounded), onPressed: onSupprimer),
          ]),
        ),
      ),
    );
  }
}

/// Création / modification d'un rappel.
class RappelFormScreen extends StatefulWidget {
  const RappelFormScreen({super.key, this.rappel});

  final Rappel? rappel;

  @override
  State<RappelFormScreen> createState() => _RappelFormScreenState();
}

class _RappelFormScreenState extends State<RappelFormScreen> {
  late TypeRappel _type = widget.rappel?.type ?? TypeRappel.medicament;
  late final _titre = TextEditingController(text: widget.rappel?.titre ?? '');
  late final _note = TextEditingController(text: widget.rappel?.note ?? '');
  late List<String> _heures = [...(widget.rappel?.heures ?? ['08:00'])];
  late DateTime _jour = DateTime.tryParse(widget.rappel?.jour ?? '') ?? _aujourdhui();
  late Repetition _repetition = widget.rappel?.repetition ?? Repetition.quotidien;
  late int _avant = widget.rappel?.avantMinutes ?? 0;
  String? _erreur;
  bool _envoi = false;

  static DateTime _aujourdhui() {
    final n = DateTime.now().toUtc(); // jour civil de Côte d'Ivoire (UTC)
    return DateTime(n.year, n.month, n.day);
  }

  @override
  void dispose() {
    _titre.dispose();
    _note.dispose();
    super.dispose();
  }

  void _changerType(TypeRappel t) {
    setState(() {
      _type = t;
      if (widget.rappel == null) {
        _repetition = t == TypeRappel.medicament ? Repetition.quotidien : Repetition.aucune;
        _heures = t == TypeRappel.medicament ? ['08:00'] : ['09:00'];
      }
      if (t == TypeRappel.rendezvous && _heures.length > 1) _heures = [_heures.first];
    });
  }

  Future<String?> _choisirHeure(String initiale) async {
    final m = minutesDe(initiale) ?? 480;
    final t = await showTimePicker(
      context: context,
      initialTime: TimeOfDay(hour: m ~/ 60, minute: m % 60),
      builder: (c, enfant) => MediaQuery(data: MediaQuery.of(c).copyWith(alwaysUse24HourFormat: true), child: enfant!),
    );
    if (t == null) return null;
    return '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';
  }

  Future<void> _ajouterHeure() async {
    final h = await _choisirHeure('12:00');
    if (h != null && !_heures.contains(h)) setState(() => _heures = [..._heures, h]..sort());
  }

  Future<void> _modifierHeure(int i) async {
    final h = await _choisirHeure(_heures[i]);
    if (h == null) return;
    setState(() {
      _heures[i] = h;
      _heures = _heures.toSet().toList()..sort();
    });
  }

  Future<void> _choisirJour() async {
    final auj = _aujourdhui();
    final d = await showDatePicker(
      context: context,
      initialDate: _jour.isBefore(auj) ? auj : _jour,
      firstDate: auj,
      lastDate: auj.add(const Duration(days: 365 * 3)),
    );
    if (d != null) setState(() => _jour = d);
  }

  Future<void> _enregistrer() async {
    final titre = _titre.text.trim();
    if (titre.isEmpty) {
      setState(() => _erreur = tr('Donnez un titre au rappel.', 'Give the reminder a title.'));
      return;
    }
    if (_heures.isEmpty) {
      setState(() => _erreur = tr('Ajoutez au moins une heure.', 'Add at least one time.'));
      return;
    }
    setState(() {
      _erreur = null;
      _envoi = true;
    });
    final service = ServiceRappels.instance;
    await service.demanderAutorisation();
    final r = Rappel(
      id: widget.rappel?.id ?? service.nouvelId(),
      titre: titre,
      note: _note.text.trim(),
      type: _type,
      heures: _heures,
      jour: formatJour(_jour),
      repetition: _repetition,
      avantMinutes: _type == TypeRappel.rendezvous ? _avant : 0,
      actif: widget.rappel?.actif ?? true,
    );
    await service.enregistrer(r);
    if (!mounted) return;
    Navigator.pop(context);
    if (prochainesEcheances(r, DateTime.now()).isEmpty) {
      afficherMessage(context, tr('Cette date est déjà passée : le rappel ne sonnera pas.', 'This date has already passed: the reminder will not ring.'));
    }
  }

  @override
  Widget build(BuildContext context) {
    final medicament = _type == TypeRappel.medicament;
    final jourTexte = '${_jour.day.toString().padLeft(2, '0')}/${_jour.month.toString().padLeft(2, '0')}/${_jour.year}';
    return Scaffold(
      appBar: BarreTitre(widget.rappel == null ? tr('Nouveau rappel', 'New reminder') : tr('Modifier le rappel', 'Edit reminder')),
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 32), children: [
        SegmentedButton<TypeRappel>(
          segments: [
            ButtonSegment(value: TypeRappel.medicament, icon: const Icon(Icons.medication_rounded), label: Text(tr('Médicament', 'Medication'))),
            ButtonSegment(value: TypeRappel.rendezvous, icon: const Icon(Icons.event_rounded), label: Text(tr('Rendez-vous', 'Appointment'))),
          ],
          selected: {_type},
          onSelectionChanged: (s) => _changerType(s.first),
        ),
        const SizedBox(height: 16),
        TextField(
          controller: _titre,
          maxLength: 60,
          textCapitalization: TextCapitalization.sentences,
          decoration: InputDecoration(
            labelText: tr('Titre', 'Title'),
            hintText: medicament ? tr('Ex. : Paracétamol 500 mg', 'E.g. Paracetamol 500 mg') : tr('Ex. : Consultation Dr Koné', 'E.g. Dr Kone consultation'),
          ),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _note,
          maxLength: 160,
          maxLines: 2,
          decoration: InputDecoration(labelText: tr('Note (facultatif)', 'Note (optional)')),
        ),
        const SizedBox(height: 8),
        TitreSection(medicament ? tr('Heures de prise', 'Times') : tr('Heure du rendez-vous', 'Appointment time')),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (var i = 0; i < _heures.length; i++)
            InputChip(
              label: Text(_heures[i], style: const TextStyle(fontWeight: FontWeight.w700)),
              avatar: const Icon(Icons.schedule_rounded, size: 18),
              onPressed: () => _modifierHeure(i),
              onDeleted: _heures.length > 1 ? () => setState(() => _heures.removeAt(i)) : null,
            ),
          if (medicament && _heures.length < 6)
            ActionChip(avatar: const Icon(Icons.add_rounded, size: 18), label: Text(tr('Ajouter une heure', 'Add a time')), onPressed: _ajouterHeure),
        ]),
        const SizedBox(height: 8),
        TitreSection(medicament ? tr('Premier jour', 'First day') : tr('Date', 'Date')),
        OutlinedButton.icon(
          style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(50), alignment: Alignment.centerLeft),
          onPressed: _choisirJour,
          icon: const Icon(Icons.calendar_today_rounded, size: 18),
          label: Text(jourTexte),
        ),
        TitreSection(tr('Répétition', 'Repeat')),
        SegmentedButton<Repetition>(
          segments: [
            for (final r in Repetition.values) ButtonSegment(value: r, label: Text(libelleRepetition(r), maxLines: 1, overflow: TextOverflow.ellipsis)),
          ],
          selected: {_repetition},
          onSelectionChanged: (s) => setState(() => _repetition = s.first),
        ),
        if (!medicament) ...[
          TitreSection(tr('Me prévenir', 'Notify me')),
          Wrap(spacing: 8, children: [
            for (final (min, label) in [(0, tr('À l\'heure', 'On time')), (60, tr('1 h avant', '1 h before')), (1440, tr('La veille', 'Day before'))])
              ChoiceChip(
                label: Text(label),
                selected: _avant == min,
                labelStyle: TextStyle(color: _avant == min ? Colors.white : Theme.of(context).colorScheme.onSurface, fontWeight: FontWeight.w700),
                onSelected: (_) => setState(() => _avant = min),
              ),
          ]),
        ],
        if (_erreur != null) Padding(padding: const EdgeInsets.only(top: 12), child: Bandeau(_erreur!, couleur: Couleurs.rouge, icone: Icons.error_outline)),
        const SizedBox(height: 20),
        FilledButton.icon(
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          onPressed: _envoi ? null : _enregistrer,
          icon: const Icon(Icons.check_rounded),
          label: Text(tr('Enregistrer', 'Save')),
        ),
      ]),
    );
  }
}
