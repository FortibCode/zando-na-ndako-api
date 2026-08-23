<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Source unique des types de boutique (categorie_principale) : remplace l'ancienne constante
// Vendeur::TYPES_BOUTIQUE, gérable désormais par un admin (voir AdminController::ajouterTypeBoutique
// / modifierTypeBoutique / supprimerTypeBoutique) plutôt que codée en dur et nécessitant un
// déploiement pour le moindre ajout/renommage.
class TypeBoutique extends Model
{
    use HasUuids;

    protected $table = 'types_boutique';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'type', 'logo',
    ];

    // Liste des libellés valides, pour construire une règle de validation `in:...` à jour sans
    // jamais dupliquer la liste elle-même (voir AuthController::register,
    // VendeurController::mettreAJourProfil, AdminController::modifierVendeur).
    public static function libellesValides(): array
    {
        return self::orderBy('type')->pluck('type')->all();
    }
}
