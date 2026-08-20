<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CodeOTP extends Model
{
    use HasUuids;

    protected $table = 'code_otp';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'code', 'canal', 'date_generation',
        'date_expiration', 'statut', 'nombre_tentatives',
        'adresse_ip_demande'
    ];

    protected $casts = [
        'date_generation' => 'datetime',
        'date_expiration' => 'datetime',
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Méthodes
    public function estValide()
    {
        return $this->statut === 'valide' && $this->date_expiration > now();
    }

    public function estExpire()
    {
        return $this->date_expiration <= now();
    }

    public function incrementerTentatives()
    {
        $this->increment('nombre_tentatives');
        if ($this->nombre_tentatives >= 5) {
            $this->update(['statut' => 'expire']);
        }
    }

    public static function genererCode()
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}