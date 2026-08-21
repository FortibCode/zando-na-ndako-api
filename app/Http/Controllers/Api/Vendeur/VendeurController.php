<?php

namespace App\Http\Controllers\Api\Vendeur;

use App\Http\Controllers\Controller;
use App\Models\Administrateur;
use App\Models\Commande;
use App\Models\LigneCommande;
use App\Models\Litige;
use App\Models\Livreur;
use App\Models\ParametrePlateforme;
use App\Models\Produit;
use App\Models\RetraitVendeur;
use App\Models\MessagerieVendeurAdmin;
use App\Services\PushService;
use App\Support\DashboardCache;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VendeurController extends Controller
{
    private function getVendeur(Request $request) { return $request->user()->vendeur; }

    public function dashboard(Request $request): JsonResponse
    {
        $v = $this->getVendeur($request);
        $c = Commande::where('vendeur_id', $v->id);
        return response()->json(['success' => true, 'data' => [
            'nom_commerce' => $v->nom_commerce,
            'solde_disponible' => $v->solde_disponible, 'note_moyenne' => $v->note_moyenne, 'statut_validation' => $v->statut_validation,
            // Exposés ici (plutôt que par une nouvelle route GET /vendeur/profil dédiée) car
            // dashboard() est déjà l'appel de chargement initial du contexte vendeur mobile : le
            // statut de boutique, les horaires et les documents doivent refléter l'état réel au
            // démarrage de l'app, pas seulement des valeurs locales par défaut.
            'statut_boutique' => $v->statut_boutique,
            'horaires_ouverture' => $v->horaires_ouverture,
            'numero_mobile_money_reception' => $v->numero_mobile_money_reception,
            'photo_boutique' => $v->photo_boutique,
            'document_identite' => $v->document_identite,
            'registre_commerce' => $v->registre_commerce,
            'commandes_aujourd_hui' => (clone $c)->whereDate('date_commande', today())->count(),
            'commandes_en_cours' => (clone $c)->whereIn('statut_commande', ['confirmee','achat_marche','preparation'])->count(),
            'commandes_livrees' => (clone $c)->where('statut_commande', 'livree')->count(),
            'total_produits' => $v->produits()->count(), 'produits_disponibles' => $v->produits()->where('statut_disponibilite', 'disponible')->count(),
            'produits_rupture' => $v->produits()->where('statut_disponibilite', 'rupture')->count(),
            'revenus_mois' => (clone $c)->whereMonth('date_commande', now()->month)->where('statut_commande', 'livree')->sum('montant_sous_total'),
        ]]);
    }

    // PUT /api/vendeur/statut-boutique — ouverte/pause/fermee. Contrairement au reste de
    // mettreAJourProfil(), ce champ n'est pas qu'une donnée d'affichage : il conditionne
    // réellement la création de commandes (voir CommandeController::valider) et la visibilité au
    // catalogue public (voir CatalogueController) pour que la promesse faite au vendeur par
    // l'écran mobile "Statut de la boutique" devienne vraie.
    public function mettreAJourStatutBoutique(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'statut_boutique' => 'required|in:ouverte,pause,fermee',
        ]);
        $v = $this->getVendeur($request);
        $v->update($validated);
        return response()->json(['success' => true, 'message' => 'Statut de la boutique mis à jour.', 'data' => $v]);
    }

    // PUT /api/vendeur/profil — nom de la boutique + position GPS (point de collecte pour le calcul
    // du prix de livraison à la distance réelle).
    public function mettreAJourProfil(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom_commerce'    => 'sometimes|string|max:150',
            'coordonnees_gps' => 'sometimes|array',
            'coordonnees_gps.lat' => 'required_with:coordonnees_gps|numeric|between:-90,90',
            'coordonnees_gps.lng' => 'required_with:coordonnees_gps|numeric|between:-180,180',
            // Colonnes présentes depuis la création de la table mais jusqu'ici jamais exposées par
            // cette route — l'écran mobile "Coordonnées de paiement" / "Horaires d'ouverture" n'avait
            // donc aucun moyen réel de les enregistrer.
            'numero_mobile_money_reception' => 'sometimes|nullable|string|max:30',
            'horaires_ouverture'            => 'sometimes|nullable|string|max:150',
        ]);
        $v = $this->getVendeur($request);
        $v->update($validated);
        return response()->json(['success' => true, 'message' => 'Profil boutique mis à jour.', 'data' => $v]);
    }

    // POST /api/vendeur/documents — pièces justificatives de la boutique (photo, pièce d'identité,
    // registre de commerce), envoyées séparément de l'inscription elle-même car celle-ci reste un
    // simple POST JSON (voir AuthController::register()) : aucun de ces 3 champs n'avait jusqu'ici
    // de point d'entrée pour être réellement enregistré, malgré les colonnes déjà présentes sur
    // vendeurs et malgré l'écran mobile "Documents du Vendeur" qui laissait croire qu'ils l'étaient.
    public function televerserDocuments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'photo_boutique'     => 'sometimes|image|mimes:jpeg,png,jpg,webp|max:3072',
            'document_identite'  => 'sometimes|image|mimes:jpeg,png,jpg,webp|max:5120',
            'registre_commerce'  => 'sometimes|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        if (empty($validated)) {
            return response()->json(['success' => false, 'message' => 'Aucun document fourni.'], 422);
        }

        $v = $this->getVendeur($request);
        $update = [];

        foreach (['photo_boutique', 'document_identite', 'registre_commerce'] as $champ) {
            if ($request->hasFile($champ)) {
                if ($v->$champ) {
                    Storage::disk('public')->delete($v->$champ);
                }
                $update[$champ] = $request->file($champ)->store('documents/vendeurs', 'public');
            }
        }

        $v->update($update);

        return response()->json(['success' => true, 'message' => 'Documents enregistrés.', 'data' => $v]);
    }

    public function commandes(Request $request): JsonResponse
    {
        $q = Commande::where('vendeur_id', $this->getVendeur($request)->id)->with(['client.user','lignes.produit','paiement']);
        if ($s = $request->get('statut')) $q->where('statut_commande', $s);
        return response()->json(['success' => true, 'data' => $q->orderBy('created_at','desc')->paginate(15)]);
    }

    public function commandeDetail(Request $request, string $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => Commande::where('id',$id)->where('vendeur_id',$this->getVendeur($request)->id)->with(['client.user','lignes.produit','livreur.user','paiement','livraison'])->firstOrFail()]);
    }

    public function accepterCommande(Request $request, string $id): JsonResponse
    {
        $c = Commande::where('id',$id)->where('vendeur_id',$this->getVendeur($request)->id)->firstOrFail();
        if ($c->statut_commande !== 'confirmee') return response()->json(['success'=>false,'message'=>'Déjà traitée.'],400);
        $c->update(['statut_commande'=>'achat_marche']);
        DashboardCache::bump();

        // Notifie le client (étape "achat au marché" de son suivi) et diffuse la mission à tous
        // les livreurs disponibles : c'est précisément ce passage à 'achat_marche' qui rend la
        // commande visible dans LivreurController::missionsDisponibles().
        if ($c->client?->user) {
            app(PushService::class)->envoyerAUtilisateur(
                $c->client->user,
                'Commande acceptée',
                "Le vendeur prépare votre commande {$c->numero_commande}.",
                ['type' => 'commande', 'commande_id' => $c->id, 'statut' => 'achat_marche']
            );
        }
        $livreursDisponibles = Livreur::where('statut_disponibilite', 'disponible')
            ->where('statut_validation', 'valide')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();
        app(PushService::class)->envoyerAUtilisateurs(
            $livreursDisponibles,
            'Nouvelle mission disponible',
            "Une nouvelle course est disponible : commande {$c->numero_commande}.",
            ['type' => 'mission_disponible', 'commande_id' => $c->id]
        );

        return response()->json(['success'=>true,'message'=>'Commande acceptée.']);
    }

    public function refuserCommande(Request $request, string $id): JsonResponse
    {
        $request->validate(['motif'=>'required|string|max:500']);
        $c = Commande::where('id',$id)->where('vendeur_id',$this->getVendeur($request)->id)->firstOrFail();
        if (!in_array($c->statut_commande,['confirmee','achat_marche'])) return response()->json(['success'=>false,'message'=>'Impossible.'],400);
        $c->update(['statut_commande'=>'annulee','motif_annulation'=>$request->motif]);
        foreach ($c->lignes as $l) $l->produit->increment('quantite_stock',$l->quantite);
        DashboardCache::bump();

        if ($c->client?->user) {
            app(PushService::class)->envoyerAUtilisateur(
                $c->client->user,
                'Commande refusée',
                "Le vendeur n'a pas pu honorer votre commande {$c->numero_commande}.",
                ['type' => 'commande', 'commande_id' => $c->id, 'statut' => 'annulee']
            );
        }

        return response()->json(['success'=>true,'message'=>'Commande refusée.']);
    }

    public function produits(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>$this->getVendeur($request)->produits()->with('categorie')->paginate(20)]);
    }

    public function ajouterProduit(Request $request): JsonResponse
    {
        $v = $this->getVendeur($request);
        if ($v->statut_validation !== 'valide') return response()->json(['success'=>false,'message'=>'Compte non validé.'],403);

        $validated = $request->validate([
            'nom_produit'=>'required|string|max:200','description'=>'nullable|string','categorie_id'=>'required|uuid|exists:categories,id',
            'prix_unitaire'=>'required|numeric|min:0','unite_mesure'=>'required|string|max:50','quantite_stock'=>'required|integer|min:0',
            'type_fraicheur'=>'sometimes|in:frais,fume,congele','photo'=>'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
        ]);

        $photoPath = $request->hasFile('photo') ? $request->file('photo')->store('photos/produits','public') : null;

        $p = Produit::create([
            'vendeur_id'=>$v->id,'categorie_id'=>$validated['categorie_id'],'nom_produit'=>$validated['nom_produit'],
            'description'=>$validated['description']??null,'prix_unitaire'=>$validated['prix_unitaire'],
            'unite_mesure'=>$validated['unite_mesure'],'quantite_stock'=>$validated['quantite_stock'],
            'statut_disponibilite'=>$validated['quantite_stock']>0?'disponible':'rupture',
            'type_fraicheur'=>$validated['type_fraicheur']??'frais','photo_produit'=>$photoPath,'date_maj_prix'=>now(),
        ]);
        DashboardCache::bump();

        return response()->json(['success'=>true,'message'=>'Produit ajouté.','data'=>$p],201);
    }

    public function modifierProduit(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['nom_produit'=>'sometimes|string','description'=>'nullable|string','prix_unitaire'=>'sometimes|numeric','unite_mesure'=>'sometimes|string','type_fraicheur'=>'sometimes|in:frais,fume,congele']);
        $p = Produit::where('id',$id)->where('vendeur_id',$this->getVendeur($request)->id)->firstOrFail();
        if (isset($validated['prix_unitaire'])) $validated['date_maj_prix'] = now();
        $p->update($validated);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Produit mis à jour.','data'=>$p]);
    }

    public function supprimerProduit(Request $request, string $id): JsonResponse
    {
        $p = Produit::where('id',$id)->where('vendeur_id',$this->getVendeur($request)->id)->firstOrFail();
        if ($p->photo_produit) Storage::disk('public')->delete($p->photo_produit);
        $p->delete();
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Produit supprimé.']);
    }

    public function gererStock(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['quantite'=>'required|integer|min:0','operation'=>'required|in:ajouter,definir']);
        $p = Produit::where('id',$id)->where('vendeur_id',$this->getVendeur($request)->id)->firstOrFail();
        if ($validated['operation']==='ajouter') $p->increment('quantite_stock',$validated['quantite']);
        else $p->update(['quantite_stock'=>$validated['quantite']]);
        $p->update(['statut_disponibilite'=>$p->fresh()->quantite_stock>0?'disponible':'rupture']);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Stock mis à jour.','nouveau_stock'=>$p->fresh()->quantite_stock]);
    }

    public function signalerRupture(Request $request, string $id): JsonResponse
    {
        Produit::where('id',$id)->where('vendeur_id',$this->getVendeur($request)->id)->firstOrFail()->update(['statut_disponibilite'=>'rupture','quantite_stock'=>0]);
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Rupture signalée.']);
    }

    public function revenus(Request $request): JsonResponse
    {
        $v = $this->getVendeur($request);
        $m = $request->get('mois',now()->month); $a = $request->get('annee',now()->year);
        $commandesLivreesDuMois = Commande::where('vendeur_id',$v->id)->where('statut_commande','livree')->whereMonth('date_commande',$m)->whereYear('date_commande',$a);
        $revenus = (clone $commandesLivreesDuMois)->sum('montant_sous_total');
        // Panier moyen réel (au lieu d'une valeur figée côté mobile) : moyenne du sous-total des
        // commandes livrées du mois. `avg()` renvoie null s'il n'y a aucune commande livrée —
        // ramené à 0 plutôt que d'afficher une valeur fantaisiste.
        $panierMoyen = (clone $commandesLivreesDuMois)->avg('montant_sous_total');
        $taux = (float) ParametrePlateforme::valeur('taux_commission_vendeur', '10') / 100;
        return response()->json(['success'=>true,'data'=>[
            'solde_disponible'=>$v->solde_disponible,'revenus_bruts'=>$revenus,'commissions'=>$revenus*$taux,'revenus_nets'=>$revenus*(1-$taux),'mois'=>$m,'annee'=>$a,
            'panier_moyen'=>round((float) ($panierMoyen ?? 0), 2),
            'ventes_semaine'=>$this->ventesSemaine($v->id),
            'produits_plus_vendus'=>$this->produitsPlusVendus($v->id),
        ]]);
    }

    // 7 derniers jours (aujourd'hui inclus), commandes livrées uniquement — utilisé pour le
    // graphique de ventes hebdomadaire du tableau de bord vendeur.
    private function ventesSemaine(string $vendeurId): array
    {
        $joursAbrev = ['DIM', 'LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM'];
        $debut = now()->subDays(6)->startOfDay();

        $parJour = Commande::where('vendeur_id', $vendeurId)
            ->where('statut_commande', 'livree')
            ->where('date_commande', '>=', $debut)
            ->selectRaw('DATE(date_commande) as jour, SUM(montant_sous_total) as montant')
            ->groupBy('jour')
            ->pluck('montant', 'jour');

        $resultat = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $resultat[] = [
                'jour'    => $joursAbrev[(int) $date->dayOfWeek],
                'montant' => (float) ($parJour[$date->format('Y-m-d')] ?? 0),
            ];
        }
        return $resultat;
    }

    // Top 5 des produits les plus vendus (en quantité) parmi les commandes livrées de ce vendeur.
    private function produitsPlusVendus(string $vendeurId): array
    {
        return LigneCommande::whereHas('commande', function ($q) use ($vendeurId) {
                $q->where('vendeur_id', $vendeurId)->where('statut_commande', 'livree');
            })
            ->selectRaw('produit_id, SUM(quantite) as total_vendu')
            ->groupBy('produit_id')
            ->orderByDesc('total_vendu')
            ->limit(5)
            ->with('produit:id,nom_produit')
            ->get()
            ->map(fn ($l) => ['nom' => $l->produit->nom_produit ?? 'Produit', 'ventes' => (int) $l->total_vendu])
            ->values()
            ->all();
    }

    public function demanderRetrait(Request $request): JsonResponse
    {
        $montantMin = (int) \App\Models\ParametrePlateforme::valeur('retrait_montant_minimum', '1000');
        $validated = $request->validate(['montant'=>"required|numeric|min:{$montantMin}",'methode_retrait'=>'required|in:mtn_momo,airtel_money,virement','numero_reception'=>'required|string']);
        $v = $this->getVendeur($request);
        if ($v->solde_disponible < $validated['montant']) return response()->json(['success'=>false,'message'=>"Solde insuffisant."],400);
        $r = RetraitVendeur::create(['vendeur_id'=>$v->id,'montant'=>$validated['montant'],'methode_retrait'=>$validated['methode_retrait'],'numero_reception'=>$validated['numero_reception'],'statut'=>'en_attente','date_demande'=>now()]);
        $v->decrement('solde_disponible',$validated['montant']);
        return response()->json(['success'=>true,'message'=>'Demande soumise.','data'=>$r],201);
    }

    public function historiqueRetraits(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>RetraitVendeur::where('vendeur_id',$this->getVendeur($request)->id)->orderBy('created_at','desc')->paginate(15)]);
    }

    // Liste des conversations (messages racines uniquement — les réponses vivent sous
    // messageDetail()/repondreMessage() et ne doivent pas apparaître comme des fils à part).
    public function messages(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>MessagerieVendeurAdmin::where('vendeur_id',$this->getVendeur($request)->id)->whereNull('parent_id')->orderBy('created_at','desc')->paginate(20)]);
    }

    // GET /vendeur/messages/{id} — le fil complet d'une conversation : message racine + réponses
    // triées par date, pour l'écran support/[id].tsx.
    public function messageDetail(Request $request, string $id): JsonResponse
    {
        $m = MessagerieVendeurAdmin::where('id',$id)->where('vendeur_id',$this->getVendeur($request)->id)->whereNull('parent_id')->with('reponses')->firstOrFail();
        return response()->json(['success'=>true,'data'=>$m]);
    }

    public function envoyerMessage(Request $request): JsonResponse
    {
        $validated = $request->validate(['contenu'=>'required|string|max:2000','objet'=>'required|string|max:200']);
        $v = $this->getVendeur($request);
        $m = MessagerieVendeurAdmin::create(['vendeur_id'=>$v->id,'expediteur'=>'vendeur','objet'=>$validated['objet'],'contenu'=>$validated['contenu'],'statut_lecture'=>false]);

        $this->notifierAdmins($v, $validated['contenu'], $m->id);

        return response()->json(['success'=>true,'message'=>'Message envoyé.','data'=>$m],201);
    }

    // POST /vendeur/messages/{id}/repondre — répond dans le fil du message {id} (racine ou
    // réponse : dans les deux cas la nouvelle réponse est rattachée à la racine du fil).
    public function repondreMessage(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['contenu'=>'required|string|max:2000']);
        $v = $this->getVendeur($request);
        $message = MessagerieVendeurAdmin::where('id',$id)->where('vendeur_id',$v->id)->firstOrFail();

        $reponse = MessagerieVendeurAdmin::create([
            'vendeur_id'=>$v->id,
            'parent_id'=>$message->parent_id ?? $message->id,
            'expediteur'=>'vendeur',
            'contenu'=>$validated['contenu'],
            'statut_lecture'=>false,
        ]);

        $this->notifierAdmins($v, $validated['contenu'], $message->parent_id ?? $message->id);

        return response()->json(['success'=>true,'message'=>'Réponse envoyée.','data'=>$reponse],201);
    }

    // Diffuse une notification push à tous les administrateurs quand un vendeur envoie ou
    // répond à un message — il n'y a pas d'admin_id assigné par conversation, donc on suit le
    // même principe que la diffusion "nouvelle mission" aux livreurs disponibles.
    private function notifierAdmins($vendeur, string $contenu, string $messageId): void
    {
        $admins = Administrateur::with('user')->get()->pluck('user')->filter();
        app(PushService::class)->envoyerAUtilisateurs(
            $admins,
            'Nouveau message vendeur',
            "{$vendeur->nom_commerce} : " . Str::limit($contenu, 100),
            ['type' => 'messagerie_vendeur', 'message_id' => $messageId]
        );
    }

    public function marquerMessageLu(Request $request, string $id): JsonResponse
    {
        MessagerieVendeurAdmin::where('id',$id)->where('vendeur_id',$this->getVendeur($request)->id)->firstOrFail()->update(['statut_lecture'=>true]);
        return response()->json(['success'=>true,'message'=>'Marqué comme lu.']);
    }

    // ----------------------------------------------------------------
    // LITIGES — litiges concernant les commandes de ce vendeur
    // ----------------------------------------------------------------
    public function litiges(Request $request): JsonResponse
    {
        $vendeurId = $this->getVendeur($request)->id;
        $q = Litige::whereHas('commande', fn ($q) => $q->where('vendeur_id', $vendeurId))
            ->with(['commande', 'plaignant', 'adminTraitant.user'])
            ->orderBy('date_ouverture', 'desc');
        if ($statut = $request->get('statut')) $q->where('statut', $statut);

        return response()->json(['success' => true, 'data' => $q->paginate(15)]);
    }

    public function litigeDetail(Request $request, string $id): JsonResponse
    {
        $vendeurId = $this->getVendeur($request)->id;
        $litige = Litige::whereHas('commande', fn ($q) => $q->where('vendeur_id', $vendeurId))
            ->where('id', $id)
            ->with(['commande.client.user', 'plaignant', 'adminTraitant.user', 'messages.user', 'piecesJointes.auteur', 'decisions'])
            ->firstOrFail();

        // Les notes internes de l'admin ne sont jamais destinées au vendeur.
        $litige->setRelation('messages', $litige->messages->where('est_note_interne', false)->values());

        return response()->json(['success' => true, 'data' => $litige]);
    }

    // GET /api/vendeur/avis — les notations reçues des clients, avec la moyenne dénormalisée.
    public function avis(Request $request): JsonResponse
    {
        $v = $this->getVendeur($request);
        $avis = \App\Models\NotationAvis::where('cible_id', $v->id)
            ->where('type_cible', 'vendeur')
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
            'note_moyenne' => (float) $v->note_moyenne,
            'nombre_avis' => $avis->count(),
            'avis' => $avis,
        ]]);
    }
}