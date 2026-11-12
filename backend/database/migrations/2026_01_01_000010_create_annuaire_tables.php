<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('libelle', 100);
            $table->enum('module', ['urgence', 'pharmacie', 'soins']);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('assurances', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 150)->unique();
            $table->enum('type', ['publique', 'privee', 'mutuelle']);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        // Table centrale : pharmacies, hôpitaux, centres de secours, bureaux FGA...
        Schema::create('etablissements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categorie_id')->constrained('categories');
            $table->string('nom', 200);
            $table->foreignId('commune_id')->constrained('communes');
            $table->string('adresse')->nullable();
            $table->string('repere')->nullable();
            $table->text('description')->nullable();
            // Coordonnées WGS84. Recherche par distance : boîte englobante sur
            // l'index (lat, lng) puis tri par Haversine (compatible MySQL et MariaDB).
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('tel1', 20)->nullable();
            $table->string('tel2', 20)->nullable();
            $table->string('horaires')->nullable();
            $table->boolean('h24')->default(false);
            $table->enum('statut', ['brouillon', 'publie'])->default('brouillon');
            $table->timestamp('verifie_at')->nullable();
            $table->boolean('premium')->default(false);
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['categorie_id', 'statut']);
            $table->index(['lat', 'lng']);
            $table->index('nom');
        });

        Schema::create('etab_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->string('service', 100);
            $table->unique(['etablissement_id', 'service']);
            $table->index('service');
        });

        Schema::create('etab_assurances', function (Blueprint $table) {
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->foreignId('assurance_id')->constrained('assurances')->cascadeOnDelete();
            $table->primary(['etablissement_id', 'assurance_id']);
            $table->index('assurance_id');
        });

        Schema::create('favoris', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'etablissement_id']);
        });

        Schema::create('numeros_urgence', function (Blueprint $table) {
            $table->id();
            $table->string('libelle', 100);
            $table->string('numero', 20);
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numeros_urgence');
        Schema::dropIfExists('favoris');
        Schema::dropIfExists('etab_assurances');
        Schema::dropIfExists('etab_services');
        Schema::dropIfExists('etablissements');
        Schema::dropIfExists('assurances');
        Schema::dropIfExists('categories');
    }
};
