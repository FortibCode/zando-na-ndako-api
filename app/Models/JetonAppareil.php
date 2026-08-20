<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class JetonAppareil extends Model
{
    use HasUuids;

    protected $table = 'jetons_appareils';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'token', 'plateforme',
    ];

    // ----------------------------------------------------------------
    // Relations
    // ----------------------------------------------------------------
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
