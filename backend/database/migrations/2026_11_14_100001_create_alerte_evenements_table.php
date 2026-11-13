<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F22 — Journal d'audit des alertes. Volontairement minimal : aucune position, aucun contenu de message,
 * aucune donnée médicale, aucun nom ni téléphone (le destinataire n'est identifié que par son rang 1..n).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerte_evenements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alerte_id')->constrained('alertes')->cascadeOnDelete();
            $table->enum('type', [
                'declenchee', 'push_envoye', 'sms_envoye', 'recue', 'vue', 'reponse',
                'audio', 'cloturee', 'annulee', 'expiree', 'trajet_auto',
            ]);
            $table->unsignedTinyInteger('destinataire_ref')->nullable(); // rang du destinataire dans l'alerte (1..n)
            $table->string('canal', 10)->nullable(); // push | sms | app | systeme
            $table->timestamp('created_at')->useCurrent();

            $table->index(['alerte_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerte_evenements');
    }
};
