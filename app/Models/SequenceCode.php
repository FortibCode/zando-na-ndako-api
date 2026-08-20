<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SequenceCode extends Model
{
    use HasUuids;

    protected $table = 'sequences_codes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['type', 'annee', 'dernier_numero'];
}
