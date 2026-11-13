<?php
require __DIR__.'/../backend/vendor/autoload.php';

use App\Services\Recherche;

$out = "// FICHIER GÉNÉRÉ depuis backend/app/Services/Recherche.php (const SYNONYMES). Ne pas modifier à la main :\n";
$out .= "// régénérer avec le script indiqué dans README.md (section Hors ligne) ; un test PHP vérifie la parité.\n\n";
$out .= "/// Synonymes (sans accents, minuscules) -> catégories (`cat`) et radicaux de services (`svc`), identiques au serveur.\n";
$out .= "const Map<String, Map<String, List<String>>> synonymesRecherche = {\n";
foreach (Recherche::SYNONYMES as $mot => $def) {
    $parts = [];
    foreach (['cat', 'svc'] as $k) {
        if (isset($def[$k])) {
            $parts[] = "'{$k}': [".implode(', ', array_map(fn ($v) => "'{$v}'", $def[$k])).']';
        }
    }
    $out .= "  '{$mot}': {".implode(', ', $parts)."},\n";
}
$out .= "};\n\n";
$out .= "/// Mots vides ignorés (comme le serveur).\n";
$out .= "const Set<String> motsVides = {'de', 'du', 'des', 'la', 'le', 'les', 'un', 'une', 'et', 'a', 'au', 'aux', 'pres', 'en', 'd', 'l'};\n";
file_put_contents(__DIR__.'/../mobile/lib/core/synonymes.dart', $out);
echo "ok\n";
