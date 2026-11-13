import 'package:flutter/material.dart';
import '../../core/langue.dart';

import '../../app.dart';
import '../../core/api.dart';
import '../../core/session.dart';
import '../../widgets/communs.dart';

/// « Ma fiche médicale » : informations utiles aux secours et à vos complices.
/// Le partage est désactivé par défaut (opt-in explicite) et ne s'applique que pendant une alerte active.
class FicheMedicaleScreen extends StatefulWidget {
  const FicheMedicaleScreen({super.key});

  @override
  State<FicheMedicaleScreen> createState() => _FicheMedicaleScreenState();
}

class _FicheMedicaleScreenState extends State<FicheMedicaleScreen> {
  static const _groupes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

  final _allergies = TextEditingController();
  final _traitements = TextEditingController();
  final _pathologies = TextEditingController();
  final _contactNom = TextEditingController();
  final _contactTel = TextEditingController();
  final _notes = TextEditingController();
  String? _groupe;
  bool _partage = false;
  bool _chargement = true;
  bool _envoi = false;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  @override
  void dispose() {
    for (final c in [_allergies, _traitements, _pathologies, _contactNom, _contactTel, _notes]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _charger() async {
    try {
      final r = await Api.instance.get('/me/fiche-medicale') as Map;
      final d = Map<String, dynamic>.from(r['data'] as Map);
      if (!mounted) return;
      setState(() {
        _groupe = d['groupe_sanguin'] as String?;
        _allergies.text = '${d['allergies'] ?? ''}';
        _traitements.text = '${d['traitements'] ?? ''}';
        _pathologies.text = '${d['pathologies'] ?? ''}';
        _contactNom.text = '${d['personne_a_prevenir_nom'] ?? ''}';
        _contactTel.text = '${d['personne_a_prevenir_tel'] ?? ''}';
        _notes.text = '${d['notes'] ?? ''}';
        _partage = d['partage'] == true;
        _chargement = false;
        _erreur = null;
      });
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
        _chargement = false;
        _erreur = e.message;
        });
      }
    }
  }

  /// Activer le partage demande une confirmation explicite qui détaille ce qui sera visible.
  Future<void> _basculerPartage(bool voulu) async {
    if (!voulu) {
      setState(() => _partage = false);
      return;
    }
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(tr('Partager ma fiche pendant une alerte ?', 'Share my card during an alert?')),
        content: Text(tr(
          'Si vous déclenchez une alerte, vos complices ayant accepté votre invitation pourront lire cette fiche '
          '(groupe sanguin, allergies, traitements, pathologies, personne à prévenir, notes) tant que l\'alerte est active.\n\n'
          'Elle n\'est jamais envoyée par SMS, jamais visible en dehors d\'une alerte, et vous pouvez désactiver ce partage à tout moment.',
          'If you trigger an alert, accomplices who accepted your invitation can read this card '
          '(blood group, allergies, treatments, conditions, emergency contact, notes) while the alert is active.\n\n'
          'It is never sent by SMS, never visible outside an alert, and you can turn this sharing off at any time.',
        )),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Non', 'No'))),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(tr('J\'accepte le partage', 'I accept sharing'))),
        ],
      ),
    );
    if (ok == true && mounted) setState(() => _partage = true);
  }

  Future<void> _enregistrer() async {
    setState(() => _envoi = true);
    try {
      await Api.instance.put('/me/fiche-medicale', {
        'groupe_sanguin': _groupe,
        'allergies': _allergies.text.trim(),
        'traitements': _traitements.text.trim(),
        'pathologies': _pathologies.text.trim(),
        'personne_a_prevenir_nom': _contactNom.text.trim(),
        'personne_a_prevenir_tel': _contactTel.text.trim(),
        'notes': _notes.text.trim(),
        'partage': _partage,
      });
      try {
        final me = await Api.instance.get('/me') as Map;
        await Session.instance.majUtilisateur(Map<String, dynamic>.from(me['data'] as Map));
      } catch (_) {}
      if (!mounted) return;
      afficherMessage(context, tr('Fiche médicale enregistrée.', 'Medical card saved.'));
      Navigator.pop(context);
    } on ApiException catch (e) {
      if (mounted) afficherMessage(context, e.message);
    } finally {
      if (mounted) setState(() => _envoi = false);
    }
  }

  Widget _champ(String libelle, TextEditingController c, {int lignes = 2, TextInputType? clavier, String? aide}) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextField(
          controller: c,
          minLines: lignes,
          maxLines: lignes == 1 ? 1 : 5,
          keyboardType: clavier,
          maxLength: lignes == 1 ? 150 : 2000,
          buildCounter: (_, {required currentLength, required isFocused, maxLength}) => null,
          decoration: InputDecoration(labelText: libelle, hintText: aide, alignLabelWithHint: true),
        ),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: BarreTitre(tr('Ma fiche médicale', 'My medical card')),
      body: _chargement
          ? const Chargement()
          : _erreur != null
              ? MessageVide(_erreur!, onReessayer: () {
                  setState(() => _chargement = true);
                  _charger();
                })
              : ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 28), children: [
                  Info(tr('Ces informations sont chiffrées sur nos serveurs. Elles restent privées sauf si vous activez '
                      'le partage ci-dessous : elles ne sont alors visibles de vos complices que pendant une alerte.',
                      'This information is encrypted on our servers. It stays private unless you turn on '
                      'sharing below: it is then visible to your accomplices only during an alert.')),
                  const SizedBox(height: 6),
                  Container(
                    margin: const EdgeInsets.symmetric(vertical: 8),
                    decoration: BoxDecoration(
                      color: _partage ? Couleurs.rougeClair : Couleurs.surface,
                      borderRadius: BorderRadius.circular(16),
                      border: Border.all(color: _partage ? Couleurs.bordRouge : Couleurs.bord),
                    ),
                    child: SwitchListTile(
                      value: _partage,
                      onChanged: _basculerPartage,
                      title: Text(tr('Partager pendant une alerte', 'Share during an alert'), style: const TextStyle(fontWeight: FontWeight.w800)),
                      subtitle: Text(
                        _partage
                            ? tr('Vos complices verront cette fiche si vous déclenchez une alerte.', 'Your accomplices will see this card if you trigger an alert.')
                            : tr('Désactivé : personne d\'autre que vous ne voit cette fiche.', 'Off: nobody but you can see this card.'),
                        style: const TextStyle(fontSize: 13, height: 1.3),
                      ),
                    ),
                  ),
                  TitreSection(tr('Informations', 'Information')),
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: DropdownButtonFormField<String?>(
                      initialValue: _groupe,
                      isExpanded: true,
                      decoration: InputDecoration(labelText: tr('Groupe sanguin', 'Blood group')),
                      items: [
                        DropdownMenuItem<String?>(value: null, child: Text(tr('Non renseigné', 'Not provided'))),
                        for (final g in _groupes) DropdownMenuItem<String?>(value: g, child: Text(g)),
                      ],
                      onChanged: (v) => setState(() => _groupe = v),
                    ),
                  ),
                  _champ(tr('Allergies', 'Allergies'), _allergies, aide: tr('Ex. pénicilline, arachide…', 'E.g. penicillin, peanuts…')),
                  _champ(tr('Traitements en cours', 'Current treatments'), _traitements),
                  _champ(tr('Pathologies', 'Conditions'), _pathologies, aide: tr('Ex. diabète, asthme, drépanocytose…', 'E.g. diabetes, asthma, sickle cell…')),
                  _champ(tr('Personne à prévenir (nom)', 'Emergency contact (name)'), _contactNom, lignes: 1),
                  _champ(tr('Personne à prévenir (téléphone)', 'Emergency contact (phone)'), _contactTel, lignes: 1, clavier: TextInputType.phone),
                  _champ(tr('Notes utiles aux secours', 'Notes useful to rescuers'), _notes),
                  const SizedBox(height: 4),
                  FilledButton(
                    style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                    onPressed: _envoi ? null : _enregistrer,
                    child: _envoi
                        ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
                        : Text(tr('Enregistrer', 'Save')),
                  ),
                ]),
    );
  }
}
