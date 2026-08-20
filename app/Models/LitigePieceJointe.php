<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LitigePieceJointe extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'litige_pieces_jointes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'litige_id', 'message_id', 'uploaded_by', 'file_name', 'file_path',
        'file_type', 'mime_type', 'file_size', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function litige()
    {
        return $this->belongsTo(Litige::class);
    }

    public function message()
    {
        return $this->belongsTo(LitigeMessage::class, 'message_id');
    }

    // Nommée différemment de la colonne uploaded_by (id brut) : Eloquent sérialise le nom de la
    // relation en snake_case ('uploaded_by' aussi), ce qui écraserait la valeur de la colonne dans
    // le JSON si les deux portaient le même nom.
    public function auteur()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
