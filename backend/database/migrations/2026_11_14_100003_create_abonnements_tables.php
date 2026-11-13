<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F19 : abonnement famille (infrastructure + paiement en bac à sable).
 * RÈGLE PRODUIT : l'envoi des alertes (push, SMS de secours, positions, trajets) ne dépend JAMAIS de ces tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('nom', 100);
            $table->unsignedInteger('prix_xof');
            $table->unsignedSmallInteger('duree_jours');
            $table->unsignedTinyInteger('profils_max'); // propriétaire compris
            $table->boolean('actif')->default(true);
            $table->boolean('a_confirmer')->default(false); // prix et conditions provisoires
            $table->timestamps();
        });

        Schema::create('abonnements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // propriétaire
            $table->foreignId('plan_id')->constrained('plans');
            $table->enum('statut', ['actif', 'expire', 'annule'])->default('actif');
            $table->enum('origine', ['paiement', 'manuel'])->default('paiement');
            $table->timestamp('debut');
            $table->timestamp('fin');
            $table->timestamps();

            $table->index(['user_id', 'statut']);
            $table->index(['statut', 'fin']);
        });

        Schema::create('abonnement_membres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('abonnement_id')->constrained('abonnements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('statut', ['invite', 'accepte'])->default('invite');
            $table->timestamps();

            $table->unique(['abonnement_id', 'user_id']);
            $table->index(['user_id', 'statut']);
        });

        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->foreignId('abonnement_id')->nullable()->constrained('abonnements')->nullOnDelete();
            $table->unsignedInteger('montant');
            $table->string('devise', 3)->default('XOF');
            $table->string('fournisseur', 20);
            $table->enum('statut', ['en_attente', 'reussi', 'echoue'])->default('en_attente');
            $table->json('payload')->nullable(); // dernier message du fournisseur (sans donnée de carte ni de compte)
            $table->timestamps();

            $table->index(['statut', 'created_at']);
            $table->index(['fournisseur', 'statut']);
        });

        // Plan de démonstration : valeurs PROVISOIRES, à confirmer par le service avant tout encaissement réel
        DB::table('plans')->insert([
            'code' => 'famille_mensuel', 'nom' => 'Famille mensuel', 'prix_xof' => 1000, 'duree_jours' => 30,
            'profils_max' => 4, 'actif' => true, 'a_confirmer' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
        Schema::dropIfExists('abonnement_membres');
        Schema::dropIfExists('abonnements');
        Schema::dropIfExists('plans');
    }
};
