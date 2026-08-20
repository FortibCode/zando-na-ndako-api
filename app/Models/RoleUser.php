<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RoleUser extends Model
{
    use HasUuids;

    protected $table = 'role_user';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'role'
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}