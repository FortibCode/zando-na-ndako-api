<?php

namespace App\Models;

use App\Support\CodeSequenceGenerator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TicketSupport extends Model
{
    use HasUuids;

    protected $table = 'tickets_support';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'numero', 'user_id', 'commande_id', 'categorie', 'sujet', 'description',
        'priorite', 'admin_assigne_id', 'statut', 'date_derniere_reponse',
    ];

    protected $casts = [
        'date_derniere_reponse' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function commande()
    {
        return $this->belongsTo(Commande::class);
    }

    public function adminAssigne()
    {
        return $this->belongsTo(Administrateur::class, 'admin_assigne_id');
    }

    public function reponses()
    {
        return $this->hasMany(TicketReponse::class, 'ticket_id')->orderBy('created_at');
    }

    public static function genererNumero(): string
    {
        return CodeSequenceGenerator::next('ticket', 'TICK');
    }
}
