<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MessagerieVendeurAdmin extends Model
{
    use HasUuids;

    protected $table = 'messagerie_vendeur_admin';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'vendeur_id', 'admin_id', 'contenu', 'date_envoi',
        'statut_lecture', 'id_conversation', 'objet', 'expediteur', 'parent_id',
    ];

    protected $casts = [
        'date_envoi' => 'datetime',
        'statut_lecture' => 'boolean',
    ];

    // Relations
    public function vendeur()
    {
        return $this->belongsTo(Vendeur::class);
    }

    public function admin()
    {
        return $this->belongsTo(Administrateur::class);
    }

    // Message racine du fil (null si ce message est lui-même la racine).
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    // Réponses attachées à ce message racine, triées par date d'envoi.
    public function reponses()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    // Méthodes
    public function marquerCommeLu()
    {
        $this->update(['statut_lecture' => true]);
    }

    public function getEstLuAttribute()
    {
        return $this->statut_lecture;
    }
}