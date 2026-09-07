<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BannierePublicitaire extends Model
{
    use HasUuids;

    protected $table = 'bannieres_publicitaires';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'vendeur_id',
        'titre',
        'description',
        'image_url',
        'type_cible',
        'cible_id',
        'date_debut',
        'date_fin',
        'statut',
        'priorite',
        'nombre_vues',
        'nombre_clics',
    ];

    protected $casts = [
        'date_debut' => 'datetime',
        'date_fin'   => 'datetime',
        'priorite'   => 'integer',
        'nombre_vues'=> 'integer',
        'nombre_clics'=> 'integer',
    ];

    public function vendeur()
    {
        return $this->belongsTo(Vendeur::class);
    }

    /**
     * Scope pour filtrer uniquement les bannières actuellement actives.
     */
    public function scopeActives($query)
    {
        $now = now();
        return $query->where('statut', 'actif')
            ->where('date_debut', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('date_fin')->orWhere('date_fin', '>=', $now);
            })
            ->orderBy('priorite', 'desc')
            ->orderBy('created_at', 'desc');
    }
}
