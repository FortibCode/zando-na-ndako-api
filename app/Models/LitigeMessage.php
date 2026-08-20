<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LitigeMessage extends Model
{
    use HasUuids;

    protected $table = 'litige_messages';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'litige_id', 'user_id', 'sender_type', 'message', 'est_note_interne',
    ];

    protected $casts = [
        'est_note_interne' => 'boolean',
    ];

    public function litige()
    {
        return $this->belongsTo(Litige::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function piecesJointes()
    {
        return $this->hasMany(LitigePieceJointe::class, 'message_id');
    }
}
