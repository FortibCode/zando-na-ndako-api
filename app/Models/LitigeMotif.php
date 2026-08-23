<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Source unique des motifs de litige valides — remplace l'ancienne constante
// LitigeController::MOTIFS, gérable désormais par un admin plutôt que codée en dur
// indépendamment côté backend/mobile/web.
class LitigeMotif extends Model
{
    use HasUuids;

    protected $table = 'litige_motifs';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code', 'libelle',
    ];

    public static function codesValides(): array
    {
        return self::orderBy('code')->pluck('code')->all();
    }
}
