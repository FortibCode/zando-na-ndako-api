<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MessageCommande extends Model
{
    use HasUuids;

    protected $table = 'messages_commande';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'commande_id', 'expediteur_user_id', 'contenu', 'lu',
    ];

    protected $casts = [
        'lu' => 'boolean',
    ];

    // Relations
    public function commande()
    {
        return $this->belongsTo(Commande::class);
    }

    public function expediteur()
    {
        return $this->belongsTo(User::class, 'expediteur_user_id');
    }
}
