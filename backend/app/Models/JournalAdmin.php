<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalAdmin extends Model
{
    protected $table = 'journal_admin';

    public const UPDATED_AT = null;

    protected $fillable = ['admin_id', 'action', 'cible_type', 'cible_id', 'avant', 'apres', 'ip'];

    protected function casts(): array
    {
        return ['avant' => 'array', 'apres' => 'array', 'created_at' => 'datetime'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
