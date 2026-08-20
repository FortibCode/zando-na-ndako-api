<?php

namespace App\Models;

use App\Support\CodeSequenceGenerator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Litige extends Model
{
    use HasUuids;

    protected $table = 'litiges';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'numero', 'commande_id', 'utilisateur_plaignant_id', 'motif',
        'description', 'decision', 'statut', 'date_ouverture', 'date_resolution',
        'admin_traitant_id'
    ];

    protected $casts = [
        'date_ouverture' => 'datetime',
        'date_resolution' => 'datetime',
    ];

    // Statuts non terminaux : un litige encore vivant, à un stade ou un autre du workflow.
    public const STATUTS_OUVERTS = ['ouvert', 'attente_vendeur', 'attente_client', 'en_cours', 'escalade'];
    // Statuts terminaux : plus aucune action possible sans réouverture manuelle.
    public const STATUTS_CLOS = ['resolu', 'rejete', 'annule'];

    // Transitions autorisées entre statuts — empêche par exemple de faire passer un litige
    // 'resolu' directement à 'attente_vendeur', ou de traiter deux fois un litige déjà clos.
    public const TRANSITIONS = [
        'ouvert' => ['attente_vendeur', 'attente_client', 'en_cours', 'escalade', 'resolu', 'rejete', 'annule'],
        'attente_vendeur' => ['en_cours', 'attente_client', 'escalade', 'resolu', 'rejete', 'annule'],
        'attente_client' => ['en_cours', 'attente_vendeur', 'escalade', 'resolu', 'rejete', 'annule'],
        'en_cours' => ['attente_vendeur', 'attente_client', 'escalade', 'resolu', 'rejete', 'annule'],
        'escalade' => ['en_cours', 'resolu', 'rejete', 'annule'],
        'resolu' => [],
        'rejete' => [],
        'annule' => [],
    ];

    public function peutTransitionnerVers(string $nouveauStatut): bool
    {
        return in_array($nouveauStatut, self::TRANSITIONS[$this->statut] ?? [], true);
    }

    public static function genererNumero(): string
    {
        return CodeSequenceGenerator::next('litige', 'LIT');
    }

    // Relations
    public function commande()
    {
        return $this->belongsTo(Commande::class);
    }

    public function plaignant()
    {
        return $this->belongsTo(User::class, 'utilisateur_plaignant_id');
    }

    public function adminTraitant()
    {
        return $this->belongsTo(Administrateur::class, 'admin_traitant_id');
    }

    public function messages()
    {
        return $this->hasMany(LitigeMessage::class)->orderBy('created_at');
    }

    public function piecesJointes()
    {
        return $this->hasMany(LitigePieceJointe::class)->orderBy('created_at');
    }

    public function decisions()
    {
        return $this->hasMany(LitigeDecision::class)->orderBy('created_at');
    }

    public function remboursements()
    {
        return $this->hasMany(LitigeRemboursement::class)->orderBy('created_at');
    }

    // Méthodes
    public function getStatutLabelAttribute()
    {
        return [
            'ouvert' => 'Ouvert',
            'attente_vendeur' => 'En attente du vendeur',
            'attente_client' => 'En attente du client',
            'en_cours' => 'En cours d\'examen',
            'escalade' => 'Escaladé',
            'resolu' => 'Résolu',
            'rejete' => 'Rejeté',
            'annule' => 'Annulé',
        ][$this->statut] ?? $this->statut;
    }

    public function estResolu()
    {
        return in_array($this->statut, self::STATUTS_CLOS, true);
    }
}
