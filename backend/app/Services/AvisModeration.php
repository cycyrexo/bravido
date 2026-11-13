<?php

namespace App\Services;

/**
 * F13 : détection automatique de contenus à examiner en priorité. Ne valide ni ne rejette jamais seule :
 * tout avis reste « en attente » jusqu'à la décision d'un modérateur.
 */
class AvisModeration
{
    /** Courte liste d'insultes courantes (sans accents, minuscules). */
    public const INSULTES = [
        'connard', 'connasse', 'con', 'salaud', 'salope', 'merde', 'idiot', 'idiote', 'imbecile', 'abruti', 'abrutie',
        'enfoire', 'batard', 'pute', 'putain', 'debile', 'crevard', 'ordure', 'nul a chier', 'fils de',
    ];

    /** Mots d'accusation (diffamation possible lorsqu'ils visent une personne nommée). */
    public const ACCUSATIONS = [
        'voleur', 'voleuse', 'escroc', 'arnaque', 'arnaqueur', 'arnaqueuse', 'incompetent', 'incompetente', 'tue', 'tuer', 'mort',
        'deces', 'empoisonne', 'negligence', 'faute', 'criminel', 'corrompu', 'corruption', 'detourne', 'harcele', 'viol',
    ];

    /** Termes de santé : l'avis ne doit contenir ni diagnostic ni information médicale personnelle. */
    public const TERMES_MEDICAUX = [
        'diagnostic', 'cancer', 'vih', 'sida', 'diabete', 'tuberculose', 'hepatite', 'paludisme', 'drepanocytose',
        'depression', 'schizophrenie', 'enceinte', 'grossesse', 'avortement', 'ordonnance', 'traitement',
    ];

    /** Libellés lisibles pour le back-office. */
    public const LIBELLES = [
        'telephone' => 'Numéro de téléphone',
        'lien' => 'Lien ou courriel',
        'insulte' => 'Insulte probable',
        'personne_nommee' => 'Personne nommée',
        'accusation' => 'Accusation visant une personne nommée',
        'donnee_medicale' => 'Information médicale',
        'signale' => 'Signalé par un utilisateur',
    ];

    /** Motif d'une personne nommée : « Dr Kouassi », « Docteur Traoré », « le pharmacien Konan »… */
    private const PERSONNE = '/\b(?i:dr|docteur|pr|professeur|pharmacien(?:ne)?|infirmier|infirmiere|mme|madame|monsieur)\.?\s+[A-ZÉÈÀ][\p{L}\'-]+/u';

    private const TELEPHONE = '/(?:\+|00)?\d[\d\s.\-]{6,}\d/';

    private const LIEN = '~(https?://\S+|www\.\S+|\b[\w.+-]+@[\w-]+\.[\w.]+)~i';

    /**
     * @return list<string> signaux : telephone, lien, insulte, personne_nommee, accusation, donnee_medicale
     */
    public function analyser(?string $texte): array
    {
        if ($texte === null || trim($texte) === '') {
            return [];
        }
        $drapeaux = [];
        $norm = Recherche::normaliser($texte);

        if (preg_match(self::TELEPHONE, $texte) === 1 && strlen(preg_replace('/\D/', '', $texte) ?? '') >= 8) {
            $drapeaux[] = 'telephone';
        }
        if (preg_match(self::LIEN, $texte) === 1) {
            $drapeaux[] = 'lien';
        }
        if ($this->contient($norm, self::INSULTES)) {
            $drapeaux[] = 'insulte';
        }
        if (preg_match(self::PERSONNE, $texte) === 1) {
            $drapeaux[] = 'personne_nommee';
            if ($this->contient($norm, self::ACCUSATIONS)) {
                $drapeaux[] = 'accusation';
            }
        }
        if ($this->contient($norm, self::TERMES_MEDICAUX)) {
            $drapeaux[] = 'donnee_medicale';
        }

        return $drapeaux;
    }

    /** Texte échappé avec les passages à examiner entourés de <mark> (affichage modérateur). */
    public function surligner(?string $texte): string
    {
        $e = e((string) $texte);
        $motifs = [self::TELEPHONE, self::LIEN, self::PERSONNE];
        foreach ([...self::INSULTES, ...self::ACCUSATIONS, ...self::TERMES_MEDICAUX] as $mot) {
            $motifs[] = '/\b'.str_replace(' ', '\s+', preg_quote($mot, '/')).'\b/iu';
        }
        foreach ($motifs as $m) {
            $e = preg_replace($m, '<mark>$0</mark>', $e) ?? $e;
        }

        // Évite les <mark> imbriqués ou répétés
        $e = preg_replace('~(<mark>)+~', '<mark>', $e) ?? $e;

        return preg_replace('~(</mark>)+~', '</mark>', $e) ?? $e;
    }

    private function contient(string $norm, array $mots): bool
    {
        foreach ($mots as $mot) {
            if (preg_match('/(^|\s)'.str_replace(' ', '\s+', preg_quote($mot, '/')).'(\s|$)/', $norm) === 1) {
                return true;
            }
        }

        return false;
    }
}
