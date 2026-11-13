<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Champs sensibles chiffrés par le modèle (cast 'encrypted') : colonnes TEXT.
        Schema::create('fiches_medicales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('allergies')->nullable();
            $table->text('traitements')->nullable();
            $table->text('pathologies')->nullable();
            $table->text('personne_a_prevenir_nom')->nullable();
            $table->text('personne_a_prevenir_tel')->nullable();
            $table->text('notes')->nullable();
            // Opt-in explicite : visible des complices uniquement pendant une alerte
            $table->boolean('partage')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiches_medicales');
    }
};
