<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotationAvis extends Model
{
    use HasUuids;

    protected $table = 'notations_avis';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'commande_id', 'notateur_id', 'type_notateur', 'cible_id', 'type_cible',
        'note', 'commentaire', 'date_notation'
    ];

    protected $casts = [
        'date_notation' => 'datetime',
    ];

    // Relations
    public function commande()
    {
        return $this->belongsTo(Commande::class);
    }

    // Méthodes
    protected static function modelPour(string $type)
    {
        return match ($type) {
            'vendeur' => Vendeur::class,
            'livreur' => Livreur::class,
            'client'  => Client::class,
        };
    }

    public function getNotateur()
    {
        return static::modelPour($this->type_notateur)::find($this->notateur_id);
    }

    public function getCible()
    {
        return static::modelPour($this->type_cible)::find($this->cible_id);
    }

    public static function getMoyenne($cibleId, $type)
    {
        return static::where('cible_id', $cibleId)
            ->where('type_cible', $type)
            ->avg('note') ?? 0;
    }

    /**
     * Recalcule et persiste la moyenne dénormalisée (vendeurs.note_moyenne, livreurs.note_moyenne,
     * clients.note_moyenne) — ces colonnes existaient déjà mais n'étaient jamais écrites, faute
     * d'endpoint pour soumettre une notation.
     */
    public static function recalculerMoyenne(string $cibleId, string $type): void
    {
        $moyenne = round(static::getMoyenne($cibleId, $type), 2);
        static::modelPour($type)::where('id', $cibleId)->update(['note_moyenne' => $moyenne]);
    }
}