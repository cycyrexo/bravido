<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // F18 : « Je gère cet établissement » = fiche de revendication, validée au back-office
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE fiches MODIFY type ENUM('souscription','correction','revendication') NOT NULL");
        }

        // Déclarations de garde faites par un responsable : toujours modérées avant publication
        Schema::create('garde_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date_debut');
            $table->date('date_fin');
            $table->enum('statut', ['en_attente', 'validee', 'rejetee'])->default('en_attente');
            $table->string('motif_rejet', 500)->nullable();
            $table->foreignId('traite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('traite_at')->nullable();
            $table->foreignId('garde_id')->nullable()->constrained('gardes')->nullOnDelete();
            $table->timestamps();

            $table->index(['statut', 'created_at']);
            $table->index(['etablissement_id', 'date_debut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garde_declarations');
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE fiches MODIFY type ENUM('souscription','correction') NOT NULL");
        }
    }
};
