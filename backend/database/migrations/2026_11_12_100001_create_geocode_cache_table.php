<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Réponses Nominatim mises en cache (politique d'usage : ne jamais redemander la même requête)
        Schema::create('geocode_cache', function (Blueprint $table) {
            $table->id();
            $table->char('cle', 40)->unique();      // sha1 de la requête normalisée
            $table->string('requete', 300);
            $table->json('reponse');                // liste (éventuellement vide) de résultats simplifiés
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geocode_cache');
    }
};
