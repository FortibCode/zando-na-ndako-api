<?php

namespace App\Http\Controllers\Api\Livreur;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Livraison;
use App\Models\RetraitLivreur;
use App\Models\SuiviPositionLivraison;
use App\Services\PushService;
use App\Support\DashboardCache;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LivreurController extends Controller
{
    private function getLivreur(Request $request) { return $request->user()->livreur; }

    // Rémunération réelle d'une livraison : le prix de livraison de sa commande (calculé à la
    // distance réelle si les coordonnées étaient disponibles, sinon le tarif de zone) — jamais
    // un forfait fixe déconnecté du trajet effectué.
    private function remunerationLivraison(?Commande $commande): float
    {
        return $commande ? (float) $commande->frais_livraison : 0;
    }

    public function dashboard(Request $request): JsonResponse
    {
        $l = $this->getLivreur($request);
        $liv = Livraison::where('livreur_id', $l->id);
        $livreesMois = (clone $liv)->where('statut_livraison','livree')->whereMonth('date_livraison',now()->month)->with('commande')->get();
        $livreesJour = (clone $liv)->where('statut_livraison','livree')->whereDate('date_livraison',today())->with('commande')->get();
        return response()->json(['success'=>true,'data'=>[
            'solde_disponible'=>$l->solde_disponible,'note_moyenne'=>$l->note_moyenne,'statut_disponibilite'=>$l->statut_disponibilite,'statut_validation'=>$l->statut_validation,
            'missions_en_cours'=>(clone $liv)->where('statut_livraison','en_cours')->count(),
            'missions_livrees'=>(clone $liv)->where('statut_livraison','livree')->count(),
            'missions_aujourd_hui'=>(clone $liv)->whereDate('created_at',today())->count(),
            'revenus_mois'=>$livreesMois->sum(fn($l)=>$this->remunerationLivraison($l->commande)),
            'revenus_jour'=>$livreesJour->sum(fn($l)=>$this->remunerationLivraison($l->commande)),
        ]]);
    }

    public function missionsDisponibles(Request $request): JsonResponse
    {
        $l = $this->getLivreur($request);
        if ($l->statut_disponibilite!=='disponible') return response()->json(['success'=>false,'message'=>'Indisponible.'],400);
        if ($l->statut_validation!=='valide') return response()->json(['success'=>false,'message'=>'Compte non validé.'],403);

        $commandes = Commande::whereNull('livreur_id')->whereIn('statut_commande',['achat_marche','preparation'])
            ->with(['vendeur.zone','lignes','beneficiaire'])->orderBy('creneau_livraison_debut')->get()
            ->map(fn($c)=>['commande_id'=>$c->id,'numero_commande'=>$c->numero_commande,'adresse_livraison'=>$c->adresse_livraison,
                'creneau_debut'=>$c->creneau_livraison_debut,'creneau_fin'=>$c->creneau_livraison_fin,'montant_total'=>$c->montant_total,
                'nombre_articles'=>$c->lignes->sum('quantite'),'zone_vendeur'=>$c->vendeur?->zone?->nom_zone,
                'distance_km'=>$c->distance_estimee_km,'remuneration'=>$this->remunerationLivraison($c)]);

        return response()->json(['success'=>true,'data'=>$commandes]);
    }

public function accepterMission(Request $request, string $id): JsonResponse
    {
        $l = $this->getLivreur($request);
        $c = Commande::findOrFail($id);
        if ($c->livreur_id) return response()->json(['success'=>false,'message'=>'Déjà assignée.'],409);
        $livraison = DB::transaction(function() use ($c,$l) {
            $c->update(['livreur_id'=>$l->id,'statut_commande'=>'en_route']);
            return Livraison::create(['commande_id'=>$c->id,'livreur_id'=>$l->id,'statut_livraison'=>'assigne']);
        });
        DashboardCache::bump();

        // Le client suit sa commande en direct : il doit savoir qu'un livreur est en route
        // (étape 'en_route' du suivi). Confirmation également envoyée au livreur lui-même,
        // utile le jour où l'assignation sera automatisée plutôt que choisie manuellement.
        if ($c->client?->user) {
            app(PushService::class)->envoyerAUtilisateur(
                $c->client->user,
                'Livreur en route',
                "Un livreur a été assigné à votre commande {$c->numero_commande} et arrive bientôt.",
                ['type' => 'commande', 'commande_id' => $c->id, 'statut' => 'en_route']
            );
        }
        if ($l->user) {
            app(PushService::class)->envoyerAUtilisateur(
                $l->user,
                'Mission confirmée',
                "Vous avez pris en charge la commande {$c->numero_commande}.",
                ['type' => 'mission_assignee', 'commande_id' => $c->id]
            );
        }

        return response()->json(['success'=>true,'message'=>'Mission acceptée !','data'=>['livraison_id'=>$livraison->id,'commande_id'=>$c->id]]);
    }

    public function refuserMission(Request $request, string $id): JsonResponse
    {
        return response()->json(['success'=>true,'message'=>'Mission refusée.']);
    }

    public function demarrerNavigation(Request $request, string $id): JsonResponse
    {
        $c = Commande::where('id',$id)->where('livreur_id',$this->getLivreur($request)->id)->with(['vendeur.zone','beneficiaire'])->firstOrFail();
        $nomVendeur = trim(($c->vendeur?->nom_commerce ?? 'Vendeur') . ($c->vendeur?->zone?->nom_zone ? ' — ' . $c->vendeur->zone->nom_zone : ''));
        // Durée estimée à partir de la distance réelle (25 km/h en moyenne, trafic urbain moto/scooter) —
        // pas de nouvel appel au service de routage ici, la distance a déjà été calculée à la commande.
        $dureeMin = $c->distance_estimee_km ? (int) round((float) $c->distance_estimee_km / 25 * 60) : null;
        return response()->json(['success'=>true,'data'=>[
            'commande_id'=>$c->id,'numero_commande'=>$c->numero_commande,'adresse_vendeur'=>$nomVendeur,
            'coordonnees_gps_vendeur'=>$c->vendeur?->coordonnees_gps,
            'adresse_livraison'=>$c->adresse_livraison,'coordonnees_gps'=>$c->coordonnees_gps_livraison,
            'distance_km'=>$c->distance_estimee_km,'duree_estimee_min'=>$dureeMin,
            'montant_livraison'=>$this->remunerationLivraison($c),
            'beneficiaire'=>$c->beneficiaire?['nom'=>$c->beneficiaire->nom,'telephone'=>$c->beneficiaire->telephone]:null,
            'methode_paiement'=>$c->paiement?->methode,'montant_a_encaisser'=>$c->paiement?->methode==='paiement_livraison'?$c->montant_total:0,
        ]]);
    }

    public function actualiserPosition(Request $request): JsonResponse
    {
        $validated = $request->validate(['livraison_id'=>'required|uuid|exists:livraisons,id','latitude'=>'required|numeric|between:-90,90','longitude'=>'required|numeric|between:-180,180']);
        $liv = Livraison::where('id',$validated['livraison_id'])->where('livreur_id',$this->getLivreur($request)->id)->firstOrFail();
        SuiviPositionLivraison::create(['livraison_id'=>$liv->id,'latitude'=>$validated['latitude'],'longitude'=>$validated['longitude'],'horodatage'=>now()]);
        return response()->json(['success'=>true,'message'=>'Position mise à jour.']);
    }

    public function confirmerCollecte(Request $request, string $id): JsonResponse
    {
        $liv = Livraison::where('id',$id)->where('livreur_id',$this->getLivreur($request)->id)->firstOrFail();
        $liv->update(['statut_livraison'=>'en_cours','date_collecte'=>now()]);
        $liv->commande->update(['statut_commande'=>'en_route']);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Collecte confirmée.']);
    }

    public function confirmerDepart(Request $request, string $id): JsonResponse
    {
        $liv = Livraison::where('id',$id)->where('livreur_id',$this->getLivreur($request)->id)->firstOrFail();
        $liv->update(['statut_livraison'=>'en_route_client','date_depart_client'=>now()]);
        $liv->commande->update(['statut_commande'=>'en_route_client']);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Départ confirmé.']);
    }

    public function confirmerLivraison(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['photo_preuve'=>'nullable|image|mimes:jpeg,png,jpg|max:5120']);
        $liv = Livraison::where('id',$id)->where('livreur_id',$this->getLivreur($request)->id)->firstOrFail();
        $photoPath = $request->hasFile('photo_preuve') ? $request->file('photo_preuve')->store('photos/livraisons','public') : null;

        $liv->marquerLivree($photoPath);
        DashboardCache::bump();

        // Dernière étape du suivi client (statut 'livree').
        $commande = $liv->commande;
        if ($commande) {
            $commande->loadMissing('client.user');
            if ($commande->client?->user) {
                app(PushService::class)->envoyerAUtilisateur(
                    $commande->client->user,
                    'Commande livrée !',
                    "Votre commande {$commande->numero_commande} a été livrée. Bon appétit !",
                    ['type' => 'commande', 'commande_id' => $commande->id, 'statut' => 'livree']
                );
            }
        }

        return response()->json(['success'=>true,'message'=>'Livraison confirmée !']);
    }

    public function signalerProbleme(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['motif'=>'required|string|max:500']);
        Livraison::where('id',$id)->where('livreur_id',$this->getLivreur($request)->id)->firstOrFail()->update(['statut_livraison'=>'echouee','motif_incident'=>$validated['motif']]);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Problème signalé.']);
    }

    public function basculerDisponibilite(Request $request): JsonResponse
    {
        $l = $this->getLivreur($request);
        $ns = $l->statut_disponibilite==='disponible'?'indisponible':'disponible';
        $l->update(['statut_disponibilite'=>$ns]);
        return response()->json(['success'=>true,'message'=>"Vous êtes maintenant : $ns.",'statut'=>$ns]);
    }

public function revenus(Request $request): JsonResponse
    {
        $l = $this->getLivreur($request);
        $m = (int)$request->get('mois',now()->month); $a = (int)$request->get('annee',now()->year);

        $base = Livraison::where('livreur_id',$l->id)->where('statut_livraison','livree')->with('commande');

        // Jour
        $jour = (clone $base)->whereDate('date_livraison',today())->get();
        // Semaine (7 derniers jours)
        $semaine = (clone $base)->whereBetween('date_livraison',[now()->subDays(6)->startOfDay(), now()])->get();
        // Mois
        $mois = (clone $base)->whereMonth('date_livraison',$m)->whereYear('date_livraison',$a)->get();

        $sum = fn($c) => $c->sum(fn($l)=>$this->remunerationLivraison($l->commande));
        $dist = fn($c) => round($c->sum('distance_parcourue'),2);

        return response()->json(['success'=>true,'data'=>[
            'solde_disponible'=>$l->solde_disponible,
            'mois'=>$m,'annee'=>$a,
            // Mois (par défaut)
            'nb_livraisons'=>$mois->count(),'remuneration'=>$sum($mois),
            'distance_totale_km'=>$dist($mois),
            'gain_moyen'=>$mois->count()>0?round($sum($mois)/$mois->count()):0,
            // Périodes
            'jour'=>['remuneration'=>$sum($jour),'nb_livraisons'=>$jour->count(),'distance_totale_km'=>$dist($jour)],
            'semaine'=>['remuneration'=>$sum($semaine),'nb_livraisons'=>$semaine->count(),'distance_totale_km'=>$dist($semaine)],
            'mois_stat'=>['remuneration'=>$sum($mois),'nb_livraisons'=>$mois->count(),'distance_totale_km'=>$dist($mois)],
            'revenus_7_jours'=>$this->revenus7Jours($semaine, $sum),
        ]]);
    }

    // Ventile la collection déjà chargée des livraisons des 7 derniers jours ($semaine, calculée
    // juste au-dessus) en un point par jour — utilisé pour le vrai graphique 7-jours du tableau de
    // bord livreur, à la place de l'ancien graphique factice.
    private function revenus7Jours($semaine, callable $sum): array
    {
        $joursAbrev = ['DIM', 'LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM'];
        $parJour = $semaine->groupBy(fn ($liv) => $liv->date_livraison?->format('Y-m-d'));

        $resultat = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $resultat[] = [
                'jour'    => $joursAbrev[(int) $date->dayOfWeek],
                'montant' => (float) $sum($parJour->get($date->format('Y-m-d'), collect())),
            ];
        }
        return $resultat;
    }

    public function demanderRetrait(Request $request): JsonResponse
    {
        $montantMin = (int) \App\Models\ParametrePlateforme::valeur('retrait_montant_minimum', '1000');
        $validated = $request->validate(['montant'=>"required|numeric|min:{$montantMin}",'methode_retrait'=>'required|in:mtn_momo,airtel_money','numero_reception'=>'required|string']);
        $l = $this->getLivreur($request);
        if ($l->solde_disponible < $validated['montant']) return response()->json(['success'=>false,'message'=>"Solde insuffisant."],400);
        $r = RetraitLivreur::create(['livreur_id'=>$l->id,'montant'=>$validated['montant'],'methode_retrait'=>$validated['methode_retrait'],'numero_reception'=>$validated['numero_reception'],'statut'=>'en_attente','date_demande'=>now()]);
        $l->decrement('solde_disponible',$validated['montant']);
        return response()->json(['success'=>true,'message'=>'Demande soumise.','data'=>$r],201);
    }

    public function historiqueRetraits(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>RetraitLivreur::where('livreur_id',$this->getLivreur($request)->id)->orderBy('created_at','desc')->paginate(15)]);
    }

public function historiqueMissions(Request $request): JsonResponse
    {
        $data = Livraison::where('livreur_id',$this->getLivreur($request)->id)
            ->with(['commande.client.user','commande.vendeur','commande.lignes'])
            ->orderBy('created_at','desc')->paginate(50);

        $data->getCollection()->transform(function($liv){
            $client = $liv->commande?->client?->user;
            $vendeur = $liv->commande?->vendeur?->user;
            return [
                'id'=>$liv->id,
                'numero_commande'=>$liv->commande?->numero_commande,
                'statut_livraison'=>$liv->statut_livraison,
                'montant'=>$this->remunerationLivraison($liv->commande),
                'distance_parcourue'=>$liv->distance_parcourue,
                'created_at'=>$liv->created_at,
                'date_livraison'=>$liv->date_livraison,
                'client_nom'=>$client ? trim(($client->prenom??'').' '.($client->nom??'')) : null,
                'vendeur_nom'=>$vendeur ? trim(($vendeur->prenom??'').' '.($vendeur->nom??'')) : null,
            ];
        });

        return response()->json(['success'=>true,'data'=>$data]);
    }

    // GET /api/livreur/avis — les notations reçues des clients, avec la moyenne dénormalisée.
    public function avis(Request $request): JsonResponse
    {
        $l = $this->getLivreur($request);
        $avis = \App\Models\NotationAvis::where('cible_id', $l->id)
            ->where('type_cible', 'livreur')
            ->with('commande:id,numero_commande,client_id')
            ->orderBy('date_notation', 'desc')
            ->get()
            ->map(function ($a) {
                $client = \App\Models\Client::with('user:id,nom,prenom,photo_profil')->find($a->notateur_id);
                return [
                    'id' => $a->id, 'note' => $a->note, 'commentaire' => $a->commentaire,
                    'date_notation' => $a->date_notation, 'numero_commande' => $a->commande?->numero_commande,
                    'client' => $client?->user ? ['nom' => $client->user->nom_complet, 'photo' => $client->user->photo_profil] : null,
                ];
            });

        return response()->json(['success' => true, 'data' => [
            'note_moyenne' => (float) $l->note_moyenne,
            'nombre_avis' => $avis->count(),
            'avis' => $avis,
        ]]);
    }
}