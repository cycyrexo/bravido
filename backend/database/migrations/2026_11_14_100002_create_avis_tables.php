<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F13 : avis et notes avec pré-modération. Un seul avis par utilisateur et par établissement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('note'); // 1 à 5
            $table->string('commentaire', 500)->nullable();
            $table->enum('statut', ['en_attente', 'approuve', 'rejete'])->default('en_attente');
            $table->string('motif_rejet', 500)->nullable();
            // Signaux détectés automatiquement (jamais d'approbation automatique) : telephone, insulte, personne_nommee…
            $table->json('drapeaux')->nullable();
            $table->foreignId('modere_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('modere_at')->nullable();
            // Réponse publique unique du responsable, modérée elle aussi
            $table->string('reponse', 500)->nullable();
            $table->enum('reponse_statut', ['en_attente', 'approuvee', 'rejetee'])->nullable();
            $table->string('reponse_motif_rejet', 500)->nullable();
            $table->json('reponse_drapeaux')->nullable();
            $table->foreignId('reponse_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reponse_at')->nullable();
            $table->timestamps();

            $table->unique(['etablissement_id', 'user_id']);
            $table->index(['etablissement_id', 'statut', 'created_at']);
            $table->index(['statut', 'created_at']);
            $table->index('reponse_statut');
        });

        Schema::create('avis_signalements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('avis_id')->constrained('avis')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('motif', 200)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['avis_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avis_signalements');
        Schema::dropIfExists('avis');
    }
};
