<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Message vocal déclenché volontairement par la personne en alerte (30 s max). */
class AlerteAudio extends Model
{
    protected $table = 'alerte_audios';

    protected $fillable = ['chemin', 'mime', 'taille', 'duree_s'];

    public function alerte(): BelongsTo
    {
        return $this->belongsTo(Alerte::class);
    }

    public function supprimerFichier(): void
    {
        Storage::disk('local')->delete($this->chemin);
    }
}
