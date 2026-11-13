<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trajets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('statut', ['en_cours', 'arrive', 'alerte'])->default('en_cours');
            $table->timestamp('started_at');
            $table->timestamp('echeance_at');
            $table->timestamp('rappel_envoye_at')->nullable();
            $table->timestamp('arrive_at')->nullable();
            $table->unsignedTinyInteger('extensions')->default(0);
            $table->boolean('partage_position')->default(false);
            // Complices suivant le trajet (ids de `complices`) ; NULL = tous les complices acceptés
            $table->json('complice_ids')->nullable();
            // Dernière position connue (remise à NULL par la purge des 30 jours)
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('precision_m')->nullable();
            $table->timestamp('position_at')->nullable();
            $table->foreignId('alerte_id')->nullable()->constrained('alertes')->nullOnDelete();
            $table->timestamps();

            $table->index(['statut', 'echeance_at']);
            $table->index(['user_id', 'statut']);
        });

        Schema::create('trajet_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trajet_id')->constrained('trajets')->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedInteger('precision_m')->nullable();
            $table->timestamp('recorded_at');

            $table->index(['trajet_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trajet_positions');
        Schema::dropIfExists('trajets');
    }
};
