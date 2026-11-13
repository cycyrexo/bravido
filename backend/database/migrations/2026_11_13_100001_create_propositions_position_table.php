<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Positions GPS proposées « sur place » par les utilisateurs (vague 2, F2) : jamais publiées automatiquement
        Schema::create('propositions_position', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedSmallInteger('precision_m');
            $table->timestamp('created_at')->useCurrent();

            // Une seule proposition par utilisateur et par établissement
            $table->unique(['etablissement_id', 'user_id']);
            $table->index('created_at');
        });

        Schema::table('etablissements', function (Blueprint $table) {
            $table->timestamp('position_a_valider_at')->nullable()->after('verifie_at');
        });
    }

    public function down(): void
    {
        Schema::table('etablissements', fn (Blueprint $t) => $t->dropColumn('position_a_valider_at'));
        Schema::dropIfExists('propositions_position');
    }
};
