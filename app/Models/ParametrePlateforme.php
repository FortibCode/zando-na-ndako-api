<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ParametrePlateforme extends Model
{
    use HasUuids;

    protected $table = 'parametres_plateforme';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'cle', 'valeur', 'description', 'date_maj', 'admin_dernier_maj_id'
    ];

    protected $casts = [
        'date_maj' => 'datetime',
    ];

    public function adminDernierMaj()
    {
        return $this->belongsTo(Administrateur::class, 'admin_dernier_maj_id');
    }

    public static function valeur(string $cle, ?string $defaut = null): ?string
    {
        return static::where('cle', $cle)->value('valeur') ?? $defaut;
    }
}
