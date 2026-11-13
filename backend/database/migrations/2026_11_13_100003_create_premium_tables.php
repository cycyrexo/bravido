<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->date('premium_jusqu_au')->nullable()->after('premium');
            $table->json('horaires_detail')->nullable()->after('horaires');
            $table->text('description_longue')->nullable()->after('description');
        });

        // Photos Premium (5 max) : fichier redimensionné + miniature, disque public
        Schema::create('etab_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->string('chemin', 255);
            $table->string('miniature', 255);
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['etablissement_id', 'ordre']);
        });

        // Statistiques d'usage agrégées par jour : aucun identifiant d'utilisateur
        Schema::create('etab_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->date('jour');
            $table->unsignedInteger('vues')->default(0);
            $table->unsignedInteger('appels')->default(0);
            $table->unsignedInteger('itineraires')->default(0);

            $table->unique(['etablissement_id', 'jour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etab_stats');
        Schema::dropIfExists('etab_photos');
        Schema::table('etablissements', fn (Blueprint $t) => $t->dropColumn(['premium_jusqu_au', 'horaires_detail', 'description_longue']));
    }
};
