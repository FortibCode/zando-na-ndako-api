<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LogActivite extends Model
{
    use HasUuids;

    protected $table = 'log_activites';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'action', 'date_action', 'adresse_ip',
        'user_agent', 'details'
    ];

    protected $casts = [
        'date_action' => 'datetime',
        'details'     => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
