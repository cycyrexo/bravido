<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerte_audios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alerte_id')->constrained('alertes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('chemin', 255);
            $table->string('mime', 60);
            $table->unsignedInteger('taille');
            $table->unsignedTinyInteger('duree_s')->nullable();
            $table->timestamps();

            $table->index(['alerte_id', 'created_at']);
            $table->index('created_at');
        });

        // Libellé précis du motif « autre » (ex. « Trajet non confirmé »)
        Schema::table('alertes', function (Blueprint $table) {
            $table->string('motif_detail', 120)->nullable()->after('motif');
        });
    }

    public function down(): void
    {
        Schema::table('alertes', fn (Blueprint $table) => $table->dropColumn('motif_detail'));
        Schema::dropIfExists('alerte_audios');
    }
};
