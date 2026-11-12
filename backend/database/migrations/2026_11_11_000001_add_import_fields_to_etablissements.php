<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import de l'annuaire GUIDSANTE : les établissements n'ont pas de GPS.
 * position_precise = false -> lat/lng = centre de la commune (ou de la ville) ;
 * l'app n'affiche alors ni distance ni point exact, et le back-office les liste « à géolocaliser ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communes', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('nom');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });

        Schema::table('etablissements', function (Blueprint $table) {
            $table->boolean('position_precise')->default(true)->after('lng');
            $table->string('tel_autres')->nullable()->after('tel2');
            $table->string('email')->nullable()->after('tel_autres');
            $table->string('site_web')->nullable()->after('email');
            $table->string('responsable_nom', 150)->nullable()->after('site_web');
            // Traçabilité de l'import (ex. « guidsante-2026 », page et empreinte de la ligne)
            $table->string('source', 50)->nullable()->after('responsable_id');
            $table->string('source_ref', 100)->nullable()->unique()->after('source');
            $table->index(['position_precise', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->dropIndex(['position_precise', 'statut']);
            $table->dropUnique(['source_ref']);
            $table->dropColumn(['position_precise', 'tel_autres', 'email', 'site_web', 'responsable_nom', 'source', 'source_ref']);
        });
        Schema::table('communes', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng']);
        });
    }
};
