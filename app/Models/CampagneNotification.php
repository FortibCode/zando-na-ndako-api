<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CampagneNotification extends Model
{
    use HasUuids;

    protected $table = 'campagnes_notifications';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'titre', 'message', 'cible_type', 'envoyer_push', 'envoyer_sms',
        'lien_action', 'date_envoi', 'statut',
        'nombre_destinataires', 'nombre_envois_reussis', 'admin_id',
    ];

    protected $casts = [
        'envoyer_push' => 'boolean',
        'envoyer_sms' => 'boolean',
        'date_envoi' => 'datetime',
    ];

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'campagne_id');
    }

    public function admin()
    {
        return $this->belongsTo(Administrateur::class, 'admin_id');
    }

    public function estDue(): bool
    {
        return $this->statut === 'programmee' && (!$this->date_envoi || $this->date_envoi <= now());
    }
}
