<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BO-03 : un import = un lot. Les gardes ne sont créées qu'à la publication du lot.
        Schema::create('import_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ville_id')->constrained('villes');
            $table->date('date_debut');
            $table->date('date_fin');
            $table->string('fichier_nom');
            $table->enum('statut', ['apercu', 'publie', 'annule'])->default('apercu');
            $table->json('lignes');
            $table->unsignedInteger('nb_valides')->default(0);
            $table->unsignedInteger('nb_erreurs')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('publie_at')->nullable();
            $table->timestamps();
        });

        // Périodes datées : gère les gardes hebdomadaires comme mensuelles
        Schema::create('gardes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->date('date_debut');
            $table->date('date_fin');
            $table->string('source', 150)->nullable();
            $table->foreignId('lot_import_id')->nullable()->constrained('import_lots')->nullOnDelete();
            $table->timestamps();

            $table->index(['date_debut', 'date_fin']);
            $table->index(['etablissement_id', 'date_debut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gardes');
        Schema::dropIfExists('import_lots');
    }
};
