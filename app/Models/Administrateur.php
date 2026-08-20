<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Administrateur extends Model
{
    use HasUuids;

    protected $table = 'administrateurs';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'role_admin', 'permissions', 'date_nomination'
    ];

    protected $casts = [
        'permissions' => 'array',
        'date_nomination' => 'datetime',
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parametres()
    {
        return $this->hasMany(ParametrePlateforme::class, 'admin_dernier_maj_id');
    }

    public function conversations()
    {
        return $this->hasMany(ConversationSupportClient::class);
    }

    public function messages()
    {
        return $this->hasMany(MessagerieVendeurAdmin::class);
    }

    public function litigesTraites()
    {
        return $this->hasMany(Litige::class, 'admin_traitant_id');
    }

    // Méthodes
    public function isSuperAdmin()
    {
        return $this->role_admin === 'super_admin';
    }

    public function hasPermission($permission)
    {
        return $this->isSuperAdmin() || in_array($permission, $this->permissions ?? []);
    }
}