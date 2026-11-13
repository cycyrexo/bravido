<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paires d'établissements déclarées « pas un doublon » (toujours a < b)
        Schema::create('doublons_ignores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_a_id')->constrained('etablissements')->cascadeOnDelete();
            $table->foreignId('etablissement_b_id')->constrained('etablissements')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['etablissement_a_id', 'etablissement_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doublons_ignores');
    }
};
