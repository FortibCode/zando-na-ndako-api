<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\CampagneNotification;


class Notification extends Model
{
    use HasUuids;

    protected $table = 'notifications';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'campagne_id', 'titre', 'message', 'type_canal', 'statut_lecture',
        'date_envoi', 'lien_action', 'statut_envoi', 'canal_secours_utilise'
    ];

    protected $casts = [
        'statut_lecture' => 'boolean',
        'date_envoi' => 'datetime',
        'canal_secours_utilise' => 'boolean',
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function campagne()
    {
        return $this->belongsTo(CampagneNotification::class, 'campagne_id');
    }

    // Méthodes
    public function marquerCommeLue()
    {
        $this->update(['statut_lecture' => true]);
    }

    public function getEstLueAttribute()
    {
        return $this->statut_lecture;
    }

    public function getCanalLabelAttribute()
    {
        return [
            'push' => 'Push Notification',
            'sms' => 'SMS',
            'email' => 'Email',
        ][$this->type_canal] ?? $this->type_canal;
    }
}