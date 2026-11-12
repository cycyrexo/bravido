<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('nom', 150);
            $table->string('telephone', 20);
            $table->foreignId('complice_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('statut', ['invite', 'accepte', 'refuse'])->default('invite');
            $table->unsignedTinyInteger('ordre')->default(1);
            // Lien d'acceptation envoyé par SMS
            $table->string('invitation_token', 64)->unique();
            $table->timestamp('repondu_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'telephone']);
            $table->index(['telephone', 'statut']);
        });

        Schema::create('alertes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('motif', ['malaise', 'accident', 'agression', 'autre']);
            // Position initiale, mise à NULL par la purge des 30 jours
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('precision_m')->nullable();
            $table->enum('statut', ['active', 'close', 'annulee'])->default('active');
            // Jeton du lien carte envoyé par SMS
            $table->string('lien_token', 64)->unique();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'statut']);
            $table->index(['statut', 'expires_at']);
        });

        Schema::create('alerte_destinataires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alerte_id')->constrained('alertes')->cascadeOnDelete();
            $table->foreignId('complice_id')->nullable()->constrained('complices')->nullOnDelete();
            // Copie au moment de l'alerte : le complice peut être supprimé ensuite
            $table->string('nom', 150);
            $table->string('telephone', 20);
            $table->foreignId('destinataire_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('push_envoye_at')->nullable();
            $table->timestamp('sms_envoye_at')->nullable();
            $table->timestamp('recu_at')->nullable();
            $table->timestamp('vu_at')->nullable();
            $table->enum('reponse', ['j_arrive', 'appelle_secours'])->nullable();
            $table->timestamp('repondu_at')->nullable();
            $table->timestamps();

            $table->unique(['alerte_id', 'telephone']);
            $table->index('destinataire_user_id');
        });

        Schema::create('alerte_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alerte_id')->constrained('alertes')->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedInteger('precision_m')->nullable();
            $table->timestamp('recorded_at');

            $table->index(['alerte_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerte_positions');
        Schema::dropIfExists('alerte_destinataires');
        Schema::dropIfExists('alertes');
        Schema::dropIfExists('complices');
    }
};
