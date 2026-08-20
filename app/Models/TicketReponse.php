<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TicketReponse extends Model
{
    use HasUuids;

    protected $table = 'ticket_reponses';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'ticket_id', 'auteur_id', 'message', 'est_note_interne',
    ];

    protected $casts = [
        'est_note_interne' => 'boolean',
    ];

    public function ticket()
    {
        return $this->belongsTo(TicketSupport::class, 'ticket_id');
    }

    public function auteur()
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
