<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // USR-04 / BO-04 : fiches de souscription et de correction
        Schema::create('fiches', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['souscription', 'correction']);
            $table->foreignId('etablissement_id')->nullable()->constrained('etablissements')->nullOnDelete();
            $table->foreignId('soumis_par')->constrained('users')->cascadeOnDelete();
            $table->json('donnees');
            $table->json('pieces')->nullable();
            $table->enum('statut', ['en_attente', 'validee', 'rejetee'])->default('en_attente');
            $table->foreignId('traite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('traite_at')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamps();
            $table->index('statut');
        });

        // USR-02 / BO-05 ; "signalement" = bouton « Signaler une erreur » d'une fiche (HOP-03)
        Schema::create('messages_support', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['observation', 'suggestion', 'preoccupation', 'signalement']);
            $table->foreignId('etablissement_id')->nullable()->constrained('etablissements')->nullOnDelete();
            $table->text('texte');
            $table->enum('statut', ['nouveau', 'en_cours', 'traite'])->default('nouveau');
            $table->text('reponse')->nullable();
            $table->foreignId('repondu_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('repondu_at')->nullable();
            $table->timestamps();
            $table->index('statut');
        });

        // ACC-02 / BO-06 : régie publicitaire
        Schema::create('publicites', function (Blueprint $table) {
            $table->id();
            $table->string('annonceur', 150);
            $table->string('titre', 150)->nullable();
            $table->string('image');
            $table->string('lien', 500)->nullable();
            $table->date('date_debut');
            $table->date('date_fin');
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->index(['actif', 'date_debut', 'date_fin']);
        });

        // Aucune ville = diffusion nationale
        Schema::create('pub_villes', function (Blueprint $table) {
            $table->foreignId('publicite_id')->constrained('publicites')->cascadeOnDelete();
            $table->foreignId('ville_id')->constrained('villes')->cascadeOnDelete();
            $table->primary(['publicite_id', 'ville_id']);
        });

        Schema::create('pub_stats', function (Blueprint $table) {
            $table->foreignId('publicite_id')->constrained('publicites')->cascadeOnDelete();
            $table->date('jour');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clics')->default(0);
            $table->primary(['publicite_id', 'jour']);
        });

        // Traçabilité des actions du back-office
        Schema::create('journal_admin', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('cible_type', 100)->nullable();
            $table->unsignedBigInteger('cible_id')->nullable();
            $table->json('avant')->nullable();
            $table->json('apres')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['cible_type', 'cible_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_admin');
        Schema::dropIfExists('pub_stats');
        Schema::dropIfExists('pub_villes');
        Schema::dropIfExists('publicites');
        Schema::dropIfExists('messages_support');
        Schema::dropIfExists('fiches');
    }
};
