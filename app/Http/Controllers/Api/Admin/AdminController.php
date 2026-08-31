<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\PermissionHelper;
use App\Http\Controllers\Controller;
use App\Models\Administrateur;
use App\Models\User;
use App\Models\Vendeur;
use App\Models\Livreur;
use App\Models\Commande;
use App\Models\Notification;
use App\Models\CampagneNotification;
use App\Models\Categorie;
use App\Models\TypeBoutique;
use App\Models\Commission;
use App\Models\Coupon;
use App\Models\Livraison;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\Promotion;
use App\Models\PromotionProduit;
use App\Models\RetraitLivreur;
use App\Models\RetraitVendeur;
use App\Models\TicketReponse;
use App\Models\TicketSupport;
use App\Models\ZoneLivraison;
use App\Models\Litige;
use App\Models\LitigeMotif;
use App\Models\LitigeMessage;
use App\Models\LitigeDecision;
use App\Models\LitigeRemboursement;
use App\Models\RoleUser;
use App\Models\LogActivite;
use App\Models\ParametrePlateforme;
use App\Models\TauxChange;
use App\Models\NotationAvis;
use App\Services\NotificationBroadcastService;
use App\Services\PushService;
use App\Services\SmsService;
use App\Support\AuditLogger;
use App\Support\DashboardCache;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        // Passe par DashboardCache (Redis versionné) au lieu du store par défaut : c'est le seul
        // mécanisme que DashboardCache::bump() sait invalider. L'ancien Cache::remember('admin_
        // dashboard', ...) vivait sur un store différent (celui de CACHE_STORE) que bump() ne
        // pouvait structurellement jamais atteindre — le dashboard restait donc périmé jusqu'à
        // 5 minutes après chaque action, quoi qu'il arrive.
        return response()->json(['success' => true, 'data' => DashboardCache::remember('summary', 300, [], function () {
            return [
                'utilisateurs' => [
                    'total' => User::count(), 'clients' => User::where('type_utilisateur', 'client')->count(),
                    'vendeurs' => User::where('type_utilisateur', 'vendeur')->count(), 'livreurs' => User::where('type_utilisateur', 'livreur')->count(),
                    'nouveaux_aujourd_hui'   => User::whereDate('date_inscription', today())->count(),
                    'nouveaux_cette_semaine' => User::where('date_inscription', '>=', now()->startOfWeek())->count(),
                    'nouveaux_ce_mois' => User::whereMonth('date_inscription', now()->month)->whereYear('date_inscription', now()->year)->count(),
                ],
                'commandes' => [
                    'total' => Commande::count(), 'en_cours' => Commande::whereIn('statut_commande', ['confirmee','achat_marche','preparation','en_route'])->count(),
                    'livrees' => Commande::where('statut_commande', 'livree')->count(), 'annulees' => Commande::where('statut_commande', 'annulee')->count(),
                    'aujourd_hui' => Commande::whereDate('date_commande', today())->count(),
                ],
                'revenus' => ['ce_mois' => Commande::where('statut_commande', 'livree')->whereMonth('date_commande', now()->month)->sum('montant_total')],
                'litiges' => ['ouverts' => Litige::where('statut', 'ouvert')->count(), 'en_cours' => Litige::where('statut', 'en_cours')->count()],
                'vendeurs_en_attente' => Vendeur::where('statut_validation', 'en_attente')->count(),
                'livreurs_en_attente' => Livreur::where('statut_validation', 'en_attente')->count(),
            ];
        })]);
    }

    public function statistiques(Request $request): JsonResponse
    {
        $mois  = $request->get('mois', now()->month);
        $annee = $request->get('annee', now()->year);

        // Le graphe Finances a besoin des seules commandes livrées, pour rester cohérent avec ses
        // cartes KPI (basées sur /admin/finances, filtré 'livree') — la vue d'ensemble du tableau
        // de bord veut au contraire tout le volume de commandes, annulées comprises exclues.
        $commandesParJourQuery = Commande::whereMonth('date_commande', $mois)->whereYear('date_commande', $annee);
        if ($request->boolean('livree_uniquement')) {
            $commandesParJourQuery->where('statut_commande', 'livree');
        }
        $commandesParJour = $commandesParJourQuery
            ->selectRaw('DATE(date_commande) as jour, COUNT(*) as total, SUM(montant_total) as revenus')
            ->groupBy('jour')
            ->orderBy('jour')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'commandes_par_jour' => $commandesParJour,
                'top_vendeurs'       => Vendeur::withCount(['commandes' => fn($q) => $q->where('statut_commande', 'livree')->whereMonth('date_commande', $mois)->whereYear('date_commande', $annee)])
                    ->orderByDesc('commandes_count')->take(5)->with('user')->get(),
                'top_produits'       => DB::table('lignes_commande')
                    ->join('commandes', 'lignes_commande.commande_id', '=', 'commandes.id')
                    ->join('produits', 'lignes_commande.produit_id', '=', 'produits.id')
                    ->whereMonth('commandes.date_commande', $mois)
                    ->whereYear('commandes.date_commande', $annee)
                    ->selectRaw('produits.nom_produit, SUM(lignes_commande.quantite) as quantite_vendue')
                    ->groupBy('produits.id', 'produits.nom_produit')
                    ->orderByDesc('quantite_vendue')
                    ->take(10)
                    ->get(),
                'meilleures_categories' => DB::table('lignes_commande')
                    ->join('commandes', 'lignes_commande.commande_id', '=', 'commandes.id')
                    ->join('produits', 'lignes_commande.produit_id', '=', 'produits.id')
                    ->join('categories', 'produits.categorie_id', '=', 'categories.id')
                    ->whereMonth('commandes.date_commande', $mois)
                    ->whereYear('commandes.date_commande', $annee)
                    ->selectRaw('categories.nom_categorie, SUM(lignes_commande.sous_total) as total_ventes')
                    ->groupBy('categories.id', 'categories.nom_categorie')
                    ->orderByDesc('total_ventes')
                    ->take(6)
                    ->get(),
            ],
        ]);
    }

    // ----------------------------------------------------------------
    // FINANCES
    // ----------------------------------------------------------------
    // GET /api/admin/taux-change — taux de conversion (App\Models\TauxChange), utilisés pour tout
    // calcul en devise réelle (paiements diaspora, tableaux de bord). Jusqu'ici seulement peuplés
    // par TauxChangeSeeder.php : aucun admin ne pouvait les corriger sans écriture DB directe.
    public function tauxChange(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => TauxChange::orderBy('devise_source')->orderBy('devise_cible')->get()]);
    }

    public function ajouterTauxChange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'devise_source' => 'required|string|max:10',
            'devise_cible' => 'required|string|max:10|different:devise_source',
            'valeur_taux' => 'required|numeric|gt:0',
            'source_taux' => 'nullable|string|max:255',
        ]);
        $existe = TauxChange::where('devise_source', $validated['devise_source'])->where('devise_cible', $validated['devise_cible'])->exists();
        if ($existe) {
            return response()->json(['success' => false, 'message' => 'Ce taux de change existe déjà — modifiez-le plutôt que d\'en créer un second.'], 422);
        }
        $taux = TauxChange::create($validated + ['date_maj' => now()]);
        AuditLogger::log($request->user(), 'creer_taux_change', 'finances', 'TauxChange', $taux->id, null, $validated, $request);
        return response()->json(['success' => true, 'data' => $taux], 201);
    }

    public function modifierTauxChange(Request $request, string $id): JsonResponse
    {
        $taux = TauxChange::findOrFail($id);
        $validated = $request->validate([
            'devise_source' => 'sometimes|string|max:10',
            'devise_cible' => 'sometimes|string|max:10|different:devise_source',
            'valeur_taux' => 'sometimes|numeric|gt:0',
            'source_taux' => 'sometimes|nullable|string|max:255',
        ]);
        $avant = $taux->only(array_keys($validated));
        if (array_key_exists('valeur_taux', $validated)) {
            $validated['date_maj'] = now();
        }
        $taux->update($validated);
        AuditLogger::log($request->user(), 'modifier_taux_change', 'finances', 'TauxChange', $taux->id, $avant, $validated, $request);
        return response()->json(['success' => true, 'data' => $taux->fresh()]);
    }

    public function supprimerTauxChange(Request $request, string $id): JsonResponse
    {
        $taux = TauxChange::findOrFail($id);
        AuditLogger::log($request->user(), 'supprimer_taux_change', 'finances', 'TauxChange', $taux->id, $taux->only(['devise_source', 'devise_cible', 'valeur_taux']), null, $request);
        $taux->delete();
        return response()->json(['success' => true, 'message' => 'Taux de change supprimé.']);
    }

    // GET /api/admin/avis — modération des avis/notations (App\Models\NotationAvis) : jusqu'ici
    // aucune vue admin n'existait pour repérer et retirer un avis abusif ou frauduleux. Volontairement
    // pas de "créer un avis" — un faux avis n'a pas de sens métier, seulement voir et supprimer.
    public function avis(Request $request): JsonResponse
    {
        $q = NotationAvis::orderByDesc('date_notation');
        if ($type = $request->get('type_cible')) $q->where('type_cible', $type);
        if ($noteMax = $request->get('note_max')) $q->where('note', '<=', $noteMax);
        if ($search = $request->get('search')) $q->where('commentaire', 'like', "%{$search}%");

        $paginated = $q->paginate((int) $request->get('per_page', 20));
        $paginated->getCollection()->transform(function (NotationAvis $a) {
            return [
                'id' => $a->id,
                'note' => $a->note,
                'commentaire' => $a->commentaire,
                'date_notation' => $a->date_notation,
                'type_notateur' => $a->type_notateur,
                'type_cible' => $a->type_cible,
                'notateur_nom' => $this->nomEntiteNotation($a->getNotateur(), $a->type_notateur),
                'cible_nom' => $this->nomEntiteNotation($a->getCible(), $a->type_cible),
                'commande_id' => $a->commande_id,
            ];
        });

        return response()->json(['success' => true, 'data' => $paginated]);
    }

    private function nomEntiteNotation($entite, string $type): string
    {
        if (!$entite) return '—';
        if ($type === 'vendeur') return $entite->nom_commerce ?? '—';
        return $entite->user?->nom_complet ?? '—';
    }

    public function supprimerAvis(Request $request, string $id): JsonResponse
    {
        $avis = NotationAvis::findOrFail($id);
        $cibleId = $avis->cible_id;
        $typeCible = $avis->type_cible;
        AuditLogger::log($request->user(), 'supprimer_avis', 'moderation', 'NotationAvis', $avis->id, $avis->only(['note', 'commentaire', 'type_cible', 'cible_id']), null, $request);
        $avis->delete();
        // La moyenne dénormalisée (vendeurs/livreurs/clients.note_moyenne) doit refléter la
        // suppression immédiatement, pas seulement à la prochaine notation soumise.
        NotationAvis::recalculerMoyenne($cibleId, $typeCible);
        return response()->json(['success' => true, 'message' => 'Avis supprimé.']);
    }

    public function finances(Request $request): JsonResponse
    {
        $mois  = $request->get('mois', now()->month);
        $annee = $request->get('annee', now()->year);

        $query = Commande::where('statut_commande', 'livree')
            ->whereMonth('date_commande', $mois)
            ->whereYear('date_commande', $annee);

        return response()->json([
            'success' => true,
            'data'    => [
                'chiffre_affaires'       => (clone $query)->sum('montant_total'),
                'commissions_percues'    => Commission::whereHas('commande', fn ($q) => $q->where('statut_commande', 'livree')
                    ->whereMonth('date_commande', $mois)->whereYear('date_commande', $annee))->sum('montant_commission'),
                'frais_livraison'        => (clone $query)->sum('frais_livraison'),
                'nb_commandes'           => (clone $query)->count(),
                'mois'                   => (int) $mois,
                'annee'                  => (int) $annee,
                // Le taux réellement appliqué à CHAQUE commission reste figé sur sa propre ligne
                // (Commission.taux_commission, calculé au moment de la commande) — celui-ci n'est
                // que la valeur ACTUELLE du réglage, pour l'affichage (ex: légende du graphique),
                // qui ne doit plus jamais être recopiée en dur ("10%") à cet endroit.
                'taux_commission_vendeur' => ParametrePlateforme::valeur('taux_commission_vendeur', '10'),
            ],
        ]);
    }

    // ----------------------------------------------------------------
    // TRANSACTIONS (paiements — vue détail derrière les agrégats du dashboard)
    // ----------------------------------------------------------------
    public function transactions(Request $request): JsonResponse
    {
        $q = Paiement::with(['commande.client.user', 'commande.vendeur'])->orderBy('created_at', 'desc');
        if ($methode = $request->get('methode')) $q->where('methode', $methode);
        if ($statut = $request->get('statut')) $q->where('statut', $statut);
        if ($debut = $request->get('date_debut')) $q->whereDate('created_at', '>=', $debut);
        if ($fin = $request->get('date_fin')) $q->whereDate('created_at', '<=', $fin);
        return response()->json(['success' => true, 'data' => $q->paginate((int) $request->get('per_page', 20))]);
    }

    // ----------------------------------------------------------------
    // COMMISSIONS
    // ----------------------------------------------------------------
    public function commissions(Request $request): JsonResponse
    {
        $q = Commission::with(['commande.vendeur'])->orderBy('created_at', 'desc');
        if ($statut = $request->get('statut')) $q->where('statut', $statut);
        // Le taux ACTUEL du réglage (voir commentaire sur finances() ci-dessus) — pour l'écran
        // qui n'affiche plus "10%" en dur dans sa description.
        return response()->json([
            'success' => true,
            'data' => $q->paginate((int) $request->get('per_page', 20)),
            'taux_commission_vendeur' => ParametrePlateforme::valeur('taux_commission_vendeur', '10'),
        ]);
    }

    public function validerCommission(Request $request, string $id): JsonResponse
    {
        $commission = Commission::findOrFail($id);
        if ($commission->statut === 'validee') {
            return response()->json(['success' => false, 'message' => 'Cette commission est déjà validée.'], 400);
        }
        $commission->update(['statut' => 'validee']);
        AuditLogger::log($request->user(), 'valider_commission', 'commissions', 'Commission', $commission->id, ['statut' => 'en_attente'], ['statut' => 'validee'], $request);
        return response()->json(['success' => true, 'message' => 'Commission validée.']);
    }

    // ----------------------------------------------------------------
    // RETRAITS (vendeurs & livreurs) — le solde est débité à la demande ;
    // un rejet doit donc recréditer le compte concerné.
    // ----------------------------------------------------------------
    public function retraitsVendeurs(Request $request): JsonResponse
    {
        $q = RetraitVendeur::with(['vendeur.user'])->orderBy('date_demande', 'desc');
        if ($statut = $request->get('statut')) $q->where('statut', $statut);
        return response()->json(['success' => true, 'data' => $q->paginate((int) $request->get('per_page', 20))]);
    }

    public function validerRetraitVendeur(Request $request, string $id): JsonResponse
    {
        $retrait = RetraitVendeur::findOrFail($id);
        if ($retrait->statut !== 'en_attente') {
            return response()->json(['success' => false, 'message' => 'Cette demande a déjà été traitée.'], 400);
        }
        $retrait->update(['statut' => 'valide', 'date_traitement' => now()]);
        AuditLogger::log($request->user(), 'valider_retrait_vendeur', 'retraits', 'RetraitVendeur', $retrait->id, ['statut' => 'en_attente'], ['statut' => 'valide'], $request);
        return response()->json(['success' => true, 'message' => 'Retrait validé.']);
    }

    public function rejeterRetraitVendeur(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['motif' => 'required|string|max:500']);
        $retrait = RetraitVendeur::findOrFail($id);
        if ($retrait->statut !== 'en_attente') {
            return response()->json(['success' => false, 'message' => 'Cette demande a déjà été traitée.'], 400);
        }
        DB::transaction(function () use ($retrait) {
            $retrait->update(['statut' => 'rejete', 'date_traitement' => now()]);
            $retrait->vendeur->increment('solde_disponible', $retrait->montant);
        });
        AuditLogger::log($request->user(), 'rejeter_retrait_vendeur', 'retraits', 'RetraitVendeur', $retrait->id, ['statut' => 'en_attente'], ['statut' => 'rejete', 'motif' => $validated['motif']], $request);
        return response()->json(['success' => true, 'message' => 'Retrait rejeté, solde recrédité.']);
    }

    public function retraitsLivreurs(Request $request): JsonResponse
    {
        $q = RetraitLivreur::with(['livreur.user'])->orderBy('date_demande', 'desc');
        if ($statut = $request->get('statut')) $q->where('statut', $statut);
        return response()->json(['success' => true, 'data' => $q->paginate((int) $request->get('per_page', 20))]);
    }

    public function validerRetraitLivreur(Request $request, string $id): JsonResponse
    {
        $retrait = RetraitLivreur::findOrFail($id);
        if ($retrait->statut !== 'en_attente') {
            return response()->json(['success' => false, 'message' => 'Cette demande a déjà été traitée.'], 400);
        }
        $retrait->update(['statut' => 'valide', 'date_traitement' => now()]);
        AuditLogger::log($request->user(), 'valider_retrait_livreur', 'retraits', 'RetraitLivreur', $retrait->id, ['statut' => 'en_attente'], ['statut' => 'valide'], $request);
        return response()->json(['success' => true, 'message' => 'Retrait validé.']);
    }

    public function rejeterRetraitLivreur(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['motif' => 'required|string|max:500']);
        $retrait = RetraitLivreur::findOrFail($id);
        if ($retrait->statut !== 'en_attente') {
            return response()->json(['success' => false, 'message' => 'Cette demande a déjà été traitée.'], 400);
        }
        DB::transaction(function () use ($retrait) {
            $retrait->update(['statut' => 'rejete', 'date_traitement' => now()]);
            $retrait->livreur->increment('solde_disponible', $retrait->montant);
        });
        AuditLogger::log($request->user(), 'rejeter_retrait_livreur', 'retraits', 'RetraitLivreur', $retrait->id, ['statut' => 'en_attente'], ['statut' => 'rejete', 'motif' => $validated['motif']], $request);
        return response()->json(['success' => true, 'message' => 'Retrait rejeté, solde recrédité.']);
    }

    // GET /api/admin/rapports/export — export CSV en flux (aucune donnée chargée intégralement en
    // mémoire), couvrant les grandes familles de rapports demandées : ventes/commandes, produits,
    // vendeurs, utilisateurs/inscriptions, diaspora, livraisons, finances, commissions, retraits.
    public function exporterRapport(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:commandes,produits,vendeurs,utilisateurs,diaspora,livraisons,finances,commissions,retraits',
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date',
            'statut' => 'nullable|string',
            'vendeur_id' => 'nullable|uuid',
            'categorie_id' => 'nullable|uuid',
            'type_utilisateur' => 'nullable|string',
            'methode' => 'nullable|string',
            'acteur' => 'nullable|in:vendeurs,livreurs',
        ]);

        $debut = $validated['date_debut'] ?? now()->subDays(29)->startOfDay()->toDateString();
        $fin = $validated['date_fin'] ?? now()->toDateString();

        [$colonnes, $lignes, $nomFichier] = match ($validated['type']) {
            'commandes' => $this->rapportCommandes($debut, $fin, $validated),
            'produits' => $this->rapportProduits($debut, $fin, $validated),
            'vendeurs' => $this->rapportVendeurs($debut, $fin),
            'utilisateurs' => $this->rapportUtilisateurs($debut, $fin, $validated),
            'diaspora' => $this->rapportDiaspora($debut, $fin),
            'livraisons' => $this->rapportLivraisons($debut, $fin, $validated),
            'finances' => $this->rapportFinances($debut, $fin, $validated),
            'commissions' => $this->rapportCommissions($debut, $fin, $validated),
            'retraits' => $this->rapportRetraits($debut, $fin, $validated),
        };

        AuditLogger::log($request->user(), 'exporter_rapport', 'rapports', 'Rapport', $validated['type'], null, ['type' => $validated['type'], 'periode' => "{$debut} → {$fin}"], $request);

        return response()->streamDownload(function () use ($colonnes, $lignes) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour un affichage correct des accents dans Excel
            fputcsv($out, $colonnes, ';');
            foreach ($lignes as $ligne) {
                fputcsv($out, $ligne, ';');
            }
            fclose($out);
        }, $nomFichier, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function rapportCommandes(string $debut, string $fin, array $f): array
    {
        $q = Commande::with(['client.user', 'vendeur', 'livreur.user'])
            ->whereBetween('date_commande', ["{$debut} 00:00:00", "{$fin} 23:59:59"]);
        if (!empty($f['statut'])) $q->where('statut_commande', $f['statut']);
        if (!empty($f['vendeur_id'])) $q->where('vendeur_id', $f['vendeur_id']);

        $lignes = $q->orderBy('date_commande')->lazy()->map(fn ($c) => [
            $c->numero_commande,
            $c->date_commande?->format('d/m/Y H:i'),
            $c->client?->user ? "{$c->client->user->prenom} {$c->client->user->nom}" : '',
            $c->vendeur?->nom_commerce,
            $c->livreur?->user ? "{$c->livreur->user->prenom} {$c->livreur->user->nom}" : '',
            $c->statut_commande,
            $c->montant_total,
            $c->devise_paiement,
        ]);

        return [
            ['Numéro', 'Date', 'Client', 'Vendeur', 'Livreur', 'Statut', 'Montant total', 'Devise'],
            $lignes,
            "rapport-commandes-{$debut}-au-{$fin}.csv",
        ];
    }

    private function rapportProduits(string $debut, string $fin, array $f): array
    {
        $q = DB::table('lignes_commande')
            ->join('commandes', 'lignes_commande.commande_id', '=', 'commandes.id')
            ->join('produits', 'lignes_commande.produit_id', '=', 'produits.id')
            ->join('categories', 'produits.categorie_id', '=', 'categories.id')
            ->join('vendeurs', 'produits.vendeur_id', '=', 'vendeurs.id')
            ->whereBetween('commandes.date_commande', ["{$debut} 00:00:00", "{$fin} 23:59:59"]);
        if (!empty($f['categorie_id'])) $q->where('produits.categorie_id', $f['categorie_id']);

        $resultats = $q->selectRaw('produits.nom_produit, categories.nom_categorie, vendeurs.nom_commerce, SUM(lignes_commande.quantite) as quantite, SUM(lignes_commande.sous_total) as chiffre_affaires')
            ->groupBy('produits.id', 'produits.nom_produit', 'categories.nom_categorie', 'vendeurs.nom_commerce')
            ->orderByDesc('chiffre_affaires')
            ->get();

        $lignes = $resultats->map(fn ($r) => [$r->nom_produit, $r->nom_categorie, $r->nom_commerce, $r->quantite, $r->chiffre_affaires]);

        return [
            ['Produit', 'Catégorie', 'Vendeur', 'Quantité vendue', 'Chiffre d\'affaires'],
            $lignes,
            "rapport-produits-{$debut}-au-{$fin}.csv",
        ];
    }

    private function rapportVendeurs(string $debut, string $fin): array
    {
        $resultats = DB::table('commandes')
            ->join('vendeurs', 'commandes.vendeur_id', '=', 'vendeurs.id')
            ->leftJoin('commissions', 'commissions.commande_id', '=', 'commandes.id')
            ->whereBetween('commandes.date_commande', ["{$debut} 00:00:00", "{$fin} 23:59:59"])
            ->where('commandes.statut_commande', 'livree')
            ->selectRaw('vendeurs.nom_commerce, vendeurs.categorie_principale, COUNT(*) as nb_commandes,
                SUM(commandes.montant_total) as chiffre_affaires, SUM(COALESCE(commissions.montant_commission, 0)) as commission')
            ->groupBy('vendeurs.id', 'vendeurs.nom_commerce', 'vendeurs.categorie_principale')
            ->orderByDesc('chiffre_affaires')
            ->get();

        $lignes = $resultats->map(fn ($r) => [$r->nom_commerce, $r->categorie_principale, $r->nb_commandes, $r->chiffre_affaires, round($r->commission, 2)]);

        return [
            ['Commerce', 'Catégorie', 'Commandes livrées', 'Chiffre d\'affaires', 'Commission'],
            $lignes,
            "rapport-vendeurs-{$debut}-au-{$fin}.csv",
        ];
    }

    private function rapportUtilisateurs(string $debut, string $fin, array $f): array
    {
        $q = User::whereBetween('date_inscription', ["{$debut} 00:00:00", "{$fin} 23:59:59"]);
        if (!empty($f['type_utilisateur'])) $q->where('type_utilisateur', $f['type_utilisateur']);

        $lignes = $q->orderBy('date_inscription')->lazy()->map(fn ($u) => [
            $u->nom, $u->prenom, $u->type_utilisateur, $u->telephone, $u->email, $u->statut_compte,
            $u->date_inscription?->format('d/m/Y H:i'),
        ]);

        return [
            ['Nom', 'Prénom', 'Type', 'Téléphone', 'Email', 'Statut', 'Date d\'inscription'],
            $lignes,
            "rapport-utilisateurs-{$debut}-au-{$fin}.csv",
        ];
    }

    private function rapportDiaspora(string $debut, string $fin): array
    {
        $q = Commande::with(['client.user', 'beneficiaire'])
            ->where('type_commande', 'diaspora')
            ->whereBetween('date_commande', ["{$debut} 00:00:00", "{$fin} 23:59:59"]);

        $lignes = $q->orderBy('date_commande')->lazy()->map(fn ($c) => [
            $c->numero_commande,
            $c->client?->user ? "{$c->client->user->prenom} {$c->client->user->nom}" : '',
            $c->client?->user?->pays_residence,
            $c->beneficiaire?->nom,
            $c->montant_total,
            $c->devise_paiement,
            $c->statut_commande,
        ]);

        return [
            ['Numéro commande', 'Expéditeur', 'Pays', 'Bénéficiaire', 'Montant', 'Devise', 'Statut'],
            $lignes,
            "rapport-diaspora-{$debut}-au-{$fin}.csv",
        ];
    }

    private function rapportLivraisons(string $debut, string $fin, array $f): array
    {
        $q = Livraison::with(['livreur.user', 'commande'])
            ->whereBetween('created_at', ["{$debut} 00:00:00", "{$fin} 23:59:59"]);
        if (!empty($f['statut'])) $q->where('statut_livraison', $f['statut']);

        $lignes = $q->orderBy('created_at')->lazy()->map(fn ($l) => [
            $l->commande?->numero_commande,
            $l->livreur?->user ? "{$l->livreur->user->prenom} {$l->livreur->user->nom}" : '',
            $l->statut_livraison,
            $l->date_collecte?->format('d/m/Y H:i'),
            $l->date_livraison?->format('d/m/Y H:i'),
            $l->distance_parcourue,
            $l->motif_incident,
        ]);

        return [
            ['Commande', 'Livreur', 'Statut', 'Collecte', 'Livraison', 'Distance (km)', 'Incident'],
            $lignes,
            "rapport-livraisons-{$debut}-au-{$fin}.csv",
        ];
    }

    private function rapportFinances(string $debut, string $fin, array $f): array
    {
        $q = Paiement::with(['commande.client.user'])
            ->whereBetween('created_at', ["{$debut} 00:00:00", "{$fin} 23:59:59"]);
        if (!empty($f['methode'])) $q->where('methode', $f['methode']);
        if (!empty($f['statut'])) $q->where('statut', $f['statut']);

        $lignes = $q->orderBy('created_at')->lazy()->map(fn ($p) => [
            $p->commande?->numero_commande,
            $p->commande?->client?->user ? "{$p->commande->client->user->prenom} {$p->commande->client->user->nom}" : '',
            $p->methode,
            $p->montant,
            $p->devise,
            $p->statut,
            $p->created_at?->format('d/m/Y H:i'),
        ]);

        return [
            ['Commande', 'Client', 'Méthode', 'Montant', 'Devise', 'Statut', 'Date'],
            $lignes,
            "rapport-finances-{$debut}-au-{$fin}.csv",
        ];
    }

    private function rapportCommissions(string $debut, string $fin, array $f): array
    {
        $q = Commission::with(['commande.vendeur'])
            ->whereBetween('created_at', ["{$debut} 00:00:00", "{$fin} 23:59:59"]);
        if (!empty($f['statut'])) $q->where('statut', $f['statut']);

        $lignes = $q->orderBy('created_at')->lazy()->map(fn ($c) => [
            $c->commande?->numero_commande,
            $c->commande?->vendeur?->nom_commerce,
            $c->taux_commission,
            $c->montant_commission,
            $c->statut,
            $c->date_calcul?->format('d/m/Y') ?? $c->created_at?->format('d/m/Y'),
        ]);

        return [
            ['Commande', 'Vendeur', 'Taux', 'Montant', 'Statut', 'Date'],
            $lignes,
            "rapport-commissions-{$debut}-au-{$fin}.csv",
        ];
    }

    private function rapportRetraits(string $debut, string $fin, array $f): array
    {
        $acteur = $f['acteur'] ?? 'vendeurs';
        if ($acteur === 'livreurs') {
            $q = RetraitLivreur::with('livreur.user')->whereBetween('date_demande', ["{$debut} 00:00:00", "{$fin} 23:59:59"]);
            $lignes = $q->orderBy('date_demande')->lazy()->map(fn ($r) => [
                $r->livreur?->user ? "{$r->livreur->user->prenom} {$r->livreur->user->nom}" : '',
                'Livreur', $r->montant, $r->methode_retrait, $r->numero_reception, $r->statut, $r->date_demande?->format('d/m/Y H:i'),
            ]);
        } else {
            $q = RetraitVendeur::with('vendeur')->whereBetween('date_demande', ["{$debut} 00:00:00", "{$fin} 23:59:59"]);
            $lignes = $q->orderBy('date_demande')->lazy()->map(fn ($r) => [
                $r->vendeur?->nom_commerce, 'Vendeur', $r->montant, $r->methode_retrait, $r->numero_reception, $r->statut, $r->date_demande?->format('d/m/Y H:i'),
            ]);
        }

        return [
            ['Bénéficiaire', 'Type', 'Montant', 'Moyen', 'Numéro de réception', 'Statut', 'Date de la demande'],
            $lignes,
            "rapport-retraits-{$acteur}-{$debut}-au-{$fin}.csv",
        ];
    }

    public function utilisateurs(Request $request): JsonResponse
    {
        $q = User::query()->with('client');
        if ($t = $request->get('type')) $q->where('type_utilisateur', $t);
        if ($s = $request->get('statut')) $q->where('statut_compte', $s);
        if ($request->boolean('diaspora')) $q->whereHas('client', fn($cq) => $cq->where('est_diaspora', true));
        if ($search = $request->get('search')) $q->where(fn($q) => $q->where('nom','like',"%{$search}%")->orWhere('email','like',"%{$search}%")->orWhere('telephone','like',"%{$search}%"));
        return response()->json(['success'=>true,'data'=>$q->orderBy('date_inscription','desc')->paginate((int) $request->get('per_page', 25))]);
    }

    public function creerUtilisateur(Request $request): JsonResponse
    {
        // Seuls les comptes "client" peuvent être créés directement ici : un vendeur ou un livreur
        // a besoin d'une fiche vendeurs/livreurs complète (nom_commerce, type_vehicule, etc.) que ce
        // formulaire rapide ne collecte pas — ces comptes doivent passer par l'inscription mobile.
        $validated = $request->validate([
            'nom'          => 'required|string|max:100',
            'prenom'       => 'required|string|max:100',
            'email'        => 'nullable|email|unique:users,email',
            'telephone'    => 'required|string|unique:users,telephone',
            'mot_de_passe' => 'required|string|min:8',
            'statut_compte'=> 'nullable|in:actif,en_attente_validation',
        ]);

        $user = User::create([
            'nom'               => $validated['nom'],
            'prenom'            => $validated['prenom'],
            'email'             => $validated['email'] ?? null,
            'telephone'         => $validated['telephone'],
            'mot_de_passe_hash' => Hash::make($validated['mot_de_passe']),
            'type_utilisateur'  => 'client',
            'statut_compte'     => $validated['statut_compte'] ?? 'actif',
            'consentement_cgu'  => true,
        ]);

        return response()->json(['success' => true, 'message' => 'Utilisateur créé.', 'data' => $user], 201);
    }

    public function utilisateurDetail(Request $request, string $id): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>User::with(['client','vendeur','livreur','administrateur','roles'])->findOrFail($id)]);
    }

    public function modifierUtilisateur(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $validated = $request->validate([
            'nom'       => 'sometimes|string|max:100',
            'prenom'    => 'sometimes|string|max:100',
            'email'     => ['sometimes', 'nullable', 'email', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($user->id)],
            'telephone' => ['sometimes', 'string', \Illuminate\Validation\Rule::unique('users', 'telephone')->ignore($user->id)],
        ]);
        $avant = $user->only(array_keys($validated));
        $user->update($validated);
        AuditLogger::log($request->user(), 'modifier_utilisateur', 'utilisateurs', 'User', $user->id, $avant, $validated, $request);
        return response()->json(['success' => true, 'message' => 'Profil mis à jour.', 'data' => $user]);
    }

    public function activerUtilisateur(Request $request, string $id): JsonResponse
    {
        $user = User::with(['vendeur', 'livreur'])->findOrFail($id);
        $statutAvant = $user->statut_compte;
        $user->update(['statut_compte' => 'actif']);
        if ($user->vendeur && $user->vendeur->statut_validation !== 'valide') {
            $user->vendeur->update(['statut_validation' => 'valide']);
        }
        if ($user->livreur && $user->livreur->statut_validation !== 'valide') {
            $user->livreur->update(['statut_validation' => 'valide', 'statut_disponibilite' => 'disponible']);
        }
        DashboardCache::bump();
        AuditLogger::log($request->user(), 'activer_utilisateur', 'utilisateurs', 'User', $user->id, ['statut_compte' => $statutAvant], ['statut_compte' => 'actif'], $request);
        return response()->json(['success' => true, 'message' => 'Compte activé.']);
    }

    public function reinitialiserMotDePasse(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $motDePasseTemporaire = Str::password(10, true, true, false, false);
        $user->update(['mot_de_passe_hash' => Hash::make($motDePasseTemporaire)]);
        $user->tokens()->delete();
        AuditLogger::log($request->user(), 'reinitialiser_mot_de_passe', 'utilisateurs', 'User', $user->id, null, null, $request);

        try {
            app(SmsService::class)->envoyer(
                $user->telephone,
                "Zando na Ndako : votre nouveau mot de passe temporaire est {$motDePasseTemporaire}. Changez-le dès votre prochaine connexion."
            );
        } catch (\Throwable $e) {
            // Best-effort : l'admin voit quand même le mot de passe généré ci-dessous, même si le SMS échoue.
        }

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe réinitialisé.',
            'data'    => ['mot_de_passe_temporaire' => $motDePasseTemporaire],
        ]);
    }

    public function suspendreUtilisateur(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['motif' => 'required|string|max:500']);
        $u = User::with(['vendeur', 'livreur'])->findOrFail($id);
        $statutAvant = $u->statut_compte;
        $u->update(['statut_compte' => 'suspendu']);
        $u->tokens()->delete();
        if ($u->vendeur) {
            $u->vendeur->update(['statut_validation' => 'suspendu']);
        }
        if ($u->livreur) {
            $u->livreur->update(['statut_validation' => 'suspendu', 'statut_disponibilite' => 'indisponible']);
        }
        DashboardCache::bump();
        AuditLogger::log($request->user(), 'suspendre_utilisateur', 'utilisateurs', 'User', $u->id, ['statut_compte' => $statutAvant], ['statut_compte' => 'suspendu', 'motif' => $validated['motif']], $request);
        return response()->json(['success' => true, 'message' => 'Compte suspendu.']);
    }

    // DELETE /api/admin/utilisateurs/{id} — suppression définitive d'un compte client/vendeur/
    // livreur (ex : comptes de test créés pendant le développement). Volontairement bloqué si des
    // données réelles en dépendent : clients/vendeurs/livreurs sont référencés par une clé étrangère
    // stricte (RESTRICT) depuis commandes/produits/retraits/etc., donc la suppression échoue au
    // niveau base si le compte a la moindre activité réelle — c'est ce qui empêche de casser
    // l'historique d'une vraie commande, pas une simple vérification applicative qu'on pourrait
    // oublier de mettre à jour. Les comptes administrateur ne passent pas par cet écran.
    public function supprimerUtilisateur(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if ($user->type_utilisateur === 'administrateur') {
            return response()->json(['success' => false, 'message' => 'Un compte administrateur ne peut pas être supprimé depuis cet écran.'], 400);
        }
        $avant = $user->only(['nom', 'prenom', 'email', 'telephone', 'type_utilisateur']);

        try {
            DB::transaction(function () use ($user) {
                // Comme pour supprimerCommande() : les données qui appartiennent en propre à ce
                // compte (messagerie interne avec l'admin, demandes de retrait, promotions,
                // produits) sont supprimées avec lui plutôt que de bloquer — un vendeur de test qui
                // a par exemple ajouté un produit ou écrit à l'admin pendant les tests n'a que ça
                // comme "activité". Seule une VRAIE commande fait encore échouer la transaction
                // (contrainte stricte sur produits/commandes), avec le message générique ci-dessous.
                if ($user->vendeur) {
                    $user->vendeur->messages()->delete();
                    $user->vendeur->retraits()->delete();
                    $user->vendeur->promotionsVendeur()->delete();
                    $user->vendeur->produits()->delete();
                }
                if ($user->livreur) {
                    $user->livreur->retraits()->delete();
                }
                $user->tokens()->delete();
                $user->delete();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23503') {
                return response()->json(['success' => false, 'message' => "Ce compte a des commandes ou d'autres données réelles liées — suppression impossible tant qu'elles existent."], 400);
            }
            throw $e;
        }

        DashboardCache::bump();
        AuditLogger::log($request->user(), 'supprimer_utilisateur', 'utilisateurs', 'User', $id, $avant, null, $request);
        return response()->json(['success' => true, 'message' => 'Compte supprimé.']);
    }

    public function vendeurs(Request $request): JsonResponse
    {
        $q = Vendeur::with('user')->orderBy('created_at','desc');
        if ($s = $request->get('statut')) $q->where('statut_validation', $s);
        // Filtre par type de boutique : nécessaire depuis que categorie_principale est devenu
        // l'axe de navigation principal côté client (parcours "boutique d'abord") — un admin qui
        // modère un type précis n'avait auparavant aucun moyen de restreindre la liste.
        if ($type = $request->get('categorie_principale')) $q->where('categorie_principale', $type);
        return response()->json(['success'=>true,'data'=>$q->paginate((int) $request->get('per_page', 20))]);
    }

    public function vendeurDetail(Request $request, string $id): JsonResponse
    {
        $vendeur = Vendeur::with(['user', 'zone'])->findOrFail($id);

        $produitsQuery = Produit::where('vendeur_id', $id);
        $produitsRupture = (clone $produitsQuery)->where(function ($q) {
            $q->where('statut_disponibilite', '!=', 'disponible')->orWhere('quantite_stock', '<=', 0);
        })->count();

        $commandesQuery = Commande::where('vendeur_id', $id);
        $commandesLivrees = (clone $commandesQuery)->where('statut_commande', 'livree');

        $commissionsEnAttente = Commission::whereHas('commande', fn ($q) => $q->where('vendeur_id', $id))
            ->where('statut', 'en_attente')->sum('montant_commission');

        return response()->json(['success' => true, 'data' => [
            'vendeur' => $vendeur,
            'stats' => [
                'produits_total' => (clone $produitsQuery)->count(),
                'produits_rupture' => $produitsRupture,
                'commandes_total' => (clone $commandesQuery)->count(),
                'commandes_livrees' => (clone $commandesLivrees)->count(),
                'chiffre_affaires' => (float) (clone $commandesLivrees)->sum('montant_total'),
                'commissions_en_attente' => (float) $commissionsEnAttente,
            ],
            'retraits_recents' => RetraitVendeur::where('vendeur_id', $id)->orderByDesc('date_demande')->take(10)->get(),
            // Aperçu des produits de la boutique (TOUS les statuts, pas juste ceux publiquement
            // visibles) : sans ça, un admin ne pouvait pas prévisualiser ce qu'un client voit
            // réellement en visitant la fiche boutique côté catalogue (voir CatalogueController::
            // produitsVendeur, qui lui exclut les boutiques fermées et n'existe que côté public).
            'produits' => (clone $produitsQuery)->with('categorie')->orderByDesc('created_at')->take(50)->get(),
        ]]);
    }

    // PUT /api/admin/vendeurs/{id} — modération des informations publiques d'une boutique
    // (type, horaires, message, photo). Jusqu'ici seul le vendeur lui-même pouvait modifier ces
    // champs (VendeurController::mettreAJourProfil) : un admin n'avait aucun moyen de corriger une
    // catégorisation erronée ou de retirer une photo/un message inapproprié sans suspendre tout le
    // compte. photo_boutique accepte explicitement `null` pour permettre de la retirer (pas de
    // téléversement depuis l'admin : uniquement le vendeur peut en fournir une nouvelle).
    public function modifierVendeur(Request $request, string $id): JsonResponse
    {
        $vendeur = Vendeur::findOrFail($id);
        $validated = $request->validate([
            'categorie_principale' => 'sometimes|string|in:' . implode(',', TypeBoutique::libellesValides()),
            'horaires_ouverture'   => 'sometimes|nullable|string|max:255',
            'message_boutique'     => 'sometimes|nullable|string|max:500',
            'photo_boutique'       => 'sometimes|nullable|string',
        ]);

        $avant = $vendeur->only(array_keys($validated));
        $vendeur->update($validated);

        AuditLogger::log($request->user(), 'modifier_vendeur', 'vendeurs', 'Vendeur', $vendeur->id, $avant, $validated, $request);
        return response()->json(['success' => true, 'message' => 'Boutique mise à jour.', 'data' => $vendeur->fresh()]);
    }

    public function validerVendeur(Request $request, string $id): JsonResponse
    {
        $v = Vendeur::with('user')->findOrFail($id);
        $statutAvant = $v->statut_validation;
        $v->update(['statut_validation' => 'valide']);

        if ($v->user) {
            $v->user->update(['statut_compte' => 'actif']);
            Notification::create([
                'user_id'           => $v->user->id,
                'titre'              => 'Compte Vendeur Validé',
                'message'            => 'Félicitations ! Votre compte vendeur a été validé par un administrateur. Vous pouvez désormais ajouter vos produits et ouvrir votre boutique.',
                'type_notification' => 'vendeur_valide',
                'statut_lecture'     => false,
            ]);
            try {
                app(PushService::class)->envoyerAUtilisateur(
                    $v->user,
                    'Compte Vendeur Validé ✅',
                    'Votre compte vendeur a été validé. Vous pouvez désormais publier vos produits !',
                    ['type' => 'vendeur_validation', 'statut' => 'valide']
                );
            } catch (\Throwable $e) {}
        }

        AuditLogger::log($request->user(), 'valider_vendeur', 'vendeurs', 'Vendeur', $v->id, ['statut_validation' => $statutAvant], ['statut_validation' => 'valide'], $request);
        return response()->json(['success' => true, 'message' => 'Vendeur validé.']);
    }

    public function suspendreVendeur(Request $request, string $id): JsonResponse
    {
        $v = Vendeur::with('user')->findOrFail($id);
        $statutAvant = $v->statut_validation;
        $v->update(['statut_validation' => 'suspendu']);

        if ($v->user) {
            $v->user->update(['statut_compte' => 'suspendu']);
            $v->user->tokens()->delete();
            Notification::create([
                'user_id'           => $v->user->id,
                'titre'              => 'Compte Vendeur Suspendu',
                'message'            => 'Votre compte vendeur a été suspendu par l\'administration. Veuillez contacter le support.',
                'type_notification' => 'vendeur_suspendu',
                'statut_lecture'     => false,
            ]);
            try {
                app(PushService::class)->envoyerAUtilisateur(
                    $v->user,
                    'Compte Vendeur Suspendu ⚠️',
                    'Votre compte vendeur a été suspendu par l\'administration.',
                    ['type' => 'vendeur_validation', 'statut' => 'suspendu']
                );
            } catch (\Throwable $e) {}
        }

        AuditLogger::log($request->user(), 'suspendre_vendeur', 'vendeurs', 'Vendeur', $v->id, ['statut_validation' => $statutAvant], ['statut_validation' => 'suspendu'], $request);
        return response()->json(['success' => true, 'message' => 'Vendeur suspendu.']);
    }

    public function livreurs(Request $request): JsonResponse
    {
        $q = Livreur::with('user')->orderBy('created_at','desc');
        if ($s = $request->get('statut')) $q->where('statut_validation', $s);
        if ($request->boolean('disponible')) $q->where('statut_disponibilite', 'disponible');
        return response()->json(['success'=>true,'data'=>$q->paginate((int) $request->get('per_page', 20))]);
    }

    public function livreurDetail(Request $request, string $id): JsonResponse
    {
        $livreur = Livreur::with('user')->findOrFail($id);

        $livraisonsQuery = Livraison::where('livreur_id', $id);
        $livraisonsTerminees = (clone $livraisonsQuery)->where('statut_livraison', 'livree');

        $revenus = Commande::where('livreur_id', $id)->where('statut_commande', 'livree')->sum('frais_livraison');

        return response()->json(['success' => true, 'data' => [
            'livreur' => $livreur,
            'stats' => [
                'livraisons_total' => (clone $livraisonsQuery)->count(),
                'livraisons_terminees' => (clone $livraisonsTerminees)->count(),
                'distance_totale_km' => round((float) (clone $livraisonsTerminees)->sum('distance_parcourue'), 1),
                'revenus_total' => (float) $revenus,
            ],
            'retraits_recents' => RetraitLivreur::where('livreur_id', $id)->orderByDesc('date_demande')->take(10)->get(),
        ]]);
    }

    public function validerLivreur(Request $request, string $id): JsonResponse
    {
        $l = Livreur::with('user')->findOrFail($id);
        $avant = ['statut_validation' => $l->statut_validation, 'statut_disponibilite' => $l->statut_disponibilite];
        $l->update(['statut_validation' => 'valide', 'statut_disponibilite' => 'disponible']);

        if ($l->user) {
            $l->user->update(['statut_compte' => 'actif']);
            Notification::create([
                'user_id'           => $l->user->id,
                'titre'              => 'Compte Livreur Validé',
                'message'            => 'Votre profil livreur a été approuvé. Vous pouvez passer en mode disponible et recevoir des livraisons.',
                'type_notification' => 'livreur_valide',
                'statut_lecture'     => false,
            ]);
            try {
                app(PushService::class)->envoyerAUtilisateur(
                    $l->user,
                    'Compte Livreur Validé ✅',
                    'Votre profil livreur a été approuvé par l\'administration.',
                    ['type' => 'livreur_validation', 'statut' => 'valide']
                );
            } catch (\Throwable $e) {}
        }

        AuditLogger::log($request->user(), 'valider_livreur', 'livreurs', 'Livreur', $l->id, $avant, ['statut_validation' => 'valide', 'statut_disponibilite' => 'disponible'], $request);
        return response()->json(['success' => true, 'message' => 'Livreur validé.']);
    }

    public function suspendreLivreur(Request $request, string $id): JsonResponse
    {
        $l = Livreur::with('user')->findOrFail($id);
        $avant = ['statut_validation' => $l->statut_validation, 'statut_disponibilite' => $l->statut_disponibilite];
        $l->update(['statut_validation' => 'suspendu', 'statut_disponibilite' => 'indisponible']);

        if ($l->user) {
            $l->user->update(['statut_compte' => 'suspendu']);
            $l->user->tokens()->delete();
            Notification::create([
                'user_id'           => $l->user->id,
                'titre'              => 'Compte Livreur Suspendu',
                'message'            => 'Votre compte livreur a été suspendu par l\'administration.',
                'type_notification' => 'livreur_suspendu',
                'statut_lecture'     => false,
            ]);
            try {
                app(PushService::class)->envoyerAUtilisateur(
                    $l->user,
                    'Compte Livreur Suspendu ⚠️',
                    'Votre compte livreur a été suspendu par l\'administration.',
                    ['type' => 'livreur_validation', 'statut' => 'suspendu']
                );
            } catch (\Throwable $e) {}
        }

        AuditLogger::log($request->user(), 'suspendre_livreur', 'livreurs', 'Livreur', $l->id, $avant, ['statut_validation' => 'suspendu', 'statut_disponibilite' => 'indisponible'], $request);
        return response()->json(['success' => true, 'message' => 'Livreur suspendu.']);
    }

    public function commandes(Request $request): JsonResponse
    {
        $q = Commande::with(['client.user','vendeur','livreur.user'])->orderBy('created_at','desc');
        if ($s = $request->get('statut')) $q->where('statut_commande', $s);
        if ($clientId = $request->get('client_id')) $q->where('client_id', $clientId);
        if ($mois = $request->get('mois')) $q->whereMonth('date_commande', $mois);
        if ($annee = $request->get('annee')) $q->whereYear('date_commande', $annee);
        return response()->json(['success'=>true,'data'=>$q->paginate((int) $request->get('per_page', 20))]);
    }

    public function commandeDetail(Request $request, string $id): JsonResponse
    {
        $commande = Commande::with([
            'client.user', 'vendeur.user', 'livreur.user',
            'lignes.produit', 'paiement', 'livraison.suiviPositions', 'litige',
        ])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $commande]);
    }

    public function attribuerVendeurCommande(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['vendeur_id' => 'required|uuid|exists:vendeurs,id']);
        $c = Commande::findOrFail($id);
        $avant = $c->vendeur_id;
        $c->update(['vendeur_id' => $validated['vendeur_id']]);
        DashboardCache::bump();
        AuditLogger::log($request->user(), 'reattribuer_vendeur_commande', 'commandes', 'Commande', $c->id, ['vendeur_id' => $avant], ['vendeur_id' => $validated['vendeur_id']], $request);
        return response()->json(['success' => true, 'message' => 'Vendeur réattribué.']);
    }

    public function rembourserCommande(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['motif' => 'required|string|max:500']);
        $commande = Commande::with('paiement')->findOrFail($id);
        $paiement = $commande->paiement;

        if (!$paiement) {
            return response()->json(['success' => false, 'message' => 'Aucun paiement associé à cette commande.'], 400);
        }
        if ($paiement->statut !== 'valide') {
            return response()->json(['success' => false, 'message' => 'Seul un paiement validé peut être remboursé.'], 400);
        }

        // Stripe et PayPal sont les deux seules méthodes avec une vraie passerelle réversible via
        // API dans cette app (mtn_momo/airtel_money n'exposent qu'une API Collections, sans
        // remboursement ; paiement_livraison n'a jamais de paiement électronique à rembourser) —
        // leur "remboursement" ne peut être qu'un enregistrement manuel, à traiter hors-app, comme
        // le sont déjà les retraits.
        $message = 'Remboursement enregistré — à traiter manuellement auprès du prestataire.';
        if ($paiement->methode === 'stripe' && $paiement->reference_transaction_externe) {
            $result = $this->rembourserStripe($paiement->reference_transaction_externe);
            if ($result === false) {
                return response()->json(['success' => false, 'message' => "Le remboursement Stripe a échoué. Le paiement n'a pas été marqué comme remboursé."], 502);
            }
            $message = $result;
        } elseif ($paiement->methode === 'paypal' && $paiement->reference_transaction_externe) {
            $result = $this->rembourserPayPal($paiement->reference_transaction_externe);
            if ($result === false) {
                return response()->json(['success' => false, 'message' => "Le remboursement PayPal a échoué. Le paiement n'a pas été marqué comme remboursé."], 502);
            }
            $message = $result;
        }

        $paiement->update(['statut' => 'rembourse']);
        DashboardCache::bump();
        AuditLogger::log($request->user(), 'rembourser_commande', 'commandes', 'Paiement', $paiement->id,
            ['statut' => 'valide'], ['statut' => 'rembourse', 'motif' => $validated['motif']], $request);

        return response()->json(['success' => true, 'message' => $message]);
    }

    // DELETE /api/admin/commandes/{id} — suppression définitive d'une commande de test ou annulée.
    // Volontairement limité aux statuts "annulee" et "confirmee" (commandes jamais entamées) pour
    // ne jamais effacer l'historique d'une livraison réelle. Toutes les données liées (lignes,
    // livraison, paiement associé) sont supprimées dans la même transaction — sans ça, les clés
    // étrangères RESTRICT bloqueraient la suppression au niveau base.
    public function supprimerCommande(Request $request, string $id): JsonResponse
    {
        $commande = Commande::with(['lignes', 'livraison', 'paiement'])->findOrFail($id);

        $statutsAutorises = ['annulee', 'confirmee'];
        if (!in_array($commande->statut_commande, $statutsAutorises, true)) {
            return response()->json([
                'success' => false,
                'message' => "Seules les commandes annulées ou non démarrées (confirmée) peuvent être supprimées. Statut actuel : {$commande->statut_commande}.",
            ], 400);
        }

        $avant = [
            'numero_commande' => $commande->numero_commande,
            'statut_commande' => $commande->statut_commande,
            'montant_total'   => $commande->montant_total,
        ];

        // notations_avis et litiges référencent aussi commandes.id avec une contrainte stricte,
        // mais contrairement à livraison/paiement/commission (simple comptabilité générée pour
        // CHAQUE commande), un vrai litige ou un vrai avis a sa propre valeur : les effacer en
        // silence ferait disparaître un historique de dispute ou une note honnête juste parce que
        // la commande sous-jacente a été nettoyée. On bloque donc explicitement plutôt que de
        // cascader, avec un message qui dit pourquoi.
        if ($commande->litige()->exists()) {
            return response()->json(['success' => false, 'message' => 'Cette commande a un litige associé — supprimez ou traitez le litige avant de supprimer la commande.'], 400);
        }
        if ($commande->notations()->exists()) {
            return response()->json(['success' => false, 'message' => 'Cette commande a un avis client associé — impossible de la supprimer sans perdre cette notation.'], 400);
        }

        try {
            DB::transaction(function () use ($commande) {
                // Supprimer les entités liées qui ne sont pas en CASCADE dans la base — la
                // commission (commissions.commande_id, contrainte stricte) manquait ici : toute
                // commande déjà passée par CommandeController (statut "confirmee" en fait déjà
                // partie) a une ligne Commission liée, donc la suppression échouait
                // systématiquement avec une violation de clé étrangère au lieu d'un message clair.
                $commande->livraison()?->delete();
                $commande->paiement()?->delete();
                $commande->commission()?->delete();
                $commande->lignes()->delete();
                $commande->delete();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23503') {
                return response()->json(['success' => false, 'message' => "Cette commande a d'autres données liées qui empêchent sa suppression."], 400);
            }
            throw $e;
        }

        // Sans ça, les statistiques du tableau de bord restaient figées sur leur valeur mise en
        // cache d'avant la suppression (cache versionné, voir DashboardCache) — une commande
        // supprimée continuait d'apparaître dans les totaux jusqu'à expiration naturelle du cache.
        DashboardCache::bump();
        AuditLogger::log($request->user(), 'supprimer_commande', 'commandes', 'Commande', $id, $avant, null, $request);
        return response()->json(['success' => true, 'message' => 'Commande supprimée définitivement.']);
    }

    // Retourne un message de succès, ou false si l'appel gateway a échoué. En l'absence de clé
    // configurée (mode dev), simule comme le fait déjà PaymentController pour les paiements.
    // $amountCents non nul = remboursement partiel (litiges) ; null = remboursement total par
    // défaut de Stripe, comportement historique inchangé pour rembourserCommande().
    private function rembourserStripe(string $paymentIntentId, ?int $amountCents = null): string|false
    {
        $secret = config('services.stripe.secret');
        if (!$secret) {
            Log::info('[Stripe] STRIPE_SECRET non configurée — remboursement simulé (mode dev).');
            return 'Paiement remboursé (simulation, clé Stripe non configurée).';
        }
        try {
            $params = ['payment_intent' => $paymentIntentId];
            if ($amountCents !== null) {
                $params['amount'] = $amountCents;
            }
            (new StripeClient($secret))->refunds->create($params);
            return 'Paiement remboursé via Stripe.';
        } catch (ApiErrorException $e) {
            Log::error("[Stripe] Échec du remboursement : {$e->getMessage()}");
            return false;
        }
    }

    // $montant/$devise non nuls = remboursement partiel (litiges) ; null = remboursement total de
    // la capture, comportement historique inchangé pour rembourserCommande().
    private function rembourserPayPal(string $orderId, ?float $montant = null, ?string $devise = null): string|false
    {
        if (!config('services.paypal.client_id')) {
            Log::info('[PayPal] PAYPAL_CLIENT_ID non configurée — remboursement simulé (mode dev).');
            return 'Paiement remboursé (simulation, clé PayPal non configurée).';
        }
        $token = $this->paypalAccessToken();
        if (!$token) return false;

        $order = Http::withToken($token)->get($this->paypalBaseUrl()."/v2/checkout/orders/{$orderId}");
        $captureId = $order->json('purchase_units.0.payments.captures.0.id');
        if (!$order->successful() || !$captureId) {
            Log::error('[PayPal] Capture introuvable pour remboursement : ' . $order->body());
            return false;
        }

        $body = [];
        if ($montant !== null && $devise !== null) {
            $body = ['amount' => ['value' => number_format($montant, 2, '.', ''), 'currency_code' => $devise]];
        }

        $refund = Http::withToken($token)->post($this->paypalBaseUrl()."/v2/payments/captures/{$captureId}/refund", $body);
        if (!$refund->successful()) {
            Log::error('[PayPal] Échec du remboursement : ' . $refund->body());
            return false;
        }
        return 'Paiement remboursé via PayPal.';
    }

    // Ne tente une passerelle réelle que pour Stripe/PayPal (seules intégrations réversibles via
    // API dans cette app) — sinon le remboursement reste 'en_attente', à traiter manuellement,
    // exactement comme pour rembourserCommande() et les retraits vendeurs/livreurs.
    private function tenterRemboursementAutomatiqueLitige(LitigeRemboursement $remboursement, Commande $commande): void
    {
        $paiement = $commande->paiement;
        if (!$paiement || $paiement->statut !== 'valide' || !$paiement->reference_transaction_externe) {
            return;
        }

        $montant = (float) $remboursement->montant;
        $estRemboursementTotal = $montant >= (float) $commande->montant_total;
        $montantCents = (int) round($montant * 100);

        if ($paiement->methode === 'stripe') {
            $result = $this->rembourserStripe($paiement->reference_transaction_externe, $estRemboursementTotal ? null : $montantCents);
        } elseif ($paiement->methode === 'paypal') {
            $result = $this->rembourserPayPal($paiement->reference_transaction_externe, $estRemboursementTotal ? null : $montant, $estRemboursementTotal ? null : $remboursement->devise);
        } else {
            return;
        }

        if ($result !== false) {
            $remboursement->update(['statut' => 'termine']);
            if ($estRemboursementTotal) {
                $paiement->update(['statut' => 'rembourse']);
            }
        } else {
            $remboursement->update(['statut' => 'echoue']);
        }
    }

    private function paypalBaseUrl(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function paypalAccessToken(): ?string
    {
        $response = Http::asForm()
            ->withBasicAuth(config('services.paypal.client_id'), config('services.paypal.secret'))
            ->post($this->paypalBaseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        if (!$response->successful()) {
            Log::error('[PayPal] Échec authentification : ' . $response->body());
            return null;
        }

        return $response->json('access_token');
    }

    public function attribuerCommande(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['livreur_id'=>'required|uuid|exists:livreurs,id']);
        $c = Commande::with('client.user')->findOrFail($id);
        if ($c->livreur_id) {
            return response()->json(['success'=>false,'message'=>'Cette commande a déjà un livreur assigné.'],409);
        }
        $livreur = Livreur::with('user')->findOrFail($validated['livreur_id']);

        // Même transaction que l'auto-attribution côté livreur (accepterMission) : sans la
        // création de la Livraison, le livreur assigné n'a aucune mission exploitable (suivi,
        // collecte, confirmation de livraison reposent tous sur cette table).
        $livraison = DB::transaction(function () use ($c, $livreur) {
            $c->update(['livreur_id'=>$livreur->id,'mode_attribution'=>'manuelle','statut_commande'=>'en_route']);
            return Livraison::create(['commande_id'=>$c->id,'livreur_id'=>$livreur->id,'statut_livraison'=>'assigne']);
        });

        DashboardCache::bump();
        AuditLogger::log($request->user(), 'attribuer_commande', 'commandes', 'Commande', $c->id, ['livreur_id'=>null], ['livreur_id'=>$livreur->id], $request);

        if ($c->client?->user) {
            app(PushService::class)->envoyerAUtilisateur(
                $c->client->user,
                'Livreur en route',
                "Un livreur a été assigné à votre commande {$c->numero_commande} et arrive bientôt.",
                ['type' => 'commande', 'commande_id' => $c->id, 'statut' => 'en_route']
            );
        }
        if ($livreur->user) {
            app(PushService::class)->envoyerAUtilisateur(
                $livreur->user,
                'Nouvelle mission',
                "Une commande {$c->numero_commande} vous a été assignée.",
                ['type' => 'mission_assignee', 'commande_id' => $c->id]
            );
        }

        return response()->json(['success'=>true,'message'=>'Commande attribuée.','data'=>['livraison_id'=>$livraison->id]]);
    }

    public function modifierStatutCommande(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['statut'=>'required|in:confirmee,achat_marche,preparation,en_route,en_route_client,livree,annulee']);
        $c = Commande::findOrFail($id);
        $statutAvant = $c->statut_commande;

        if ($validated['statut'] === 'livree' && $statutAvant !== 'livree') {
            // Passe par le même règlement que confirmerLivraison() (crédite livreur + vendeur,
            // valide le paiement à la livraison) plutôt que de se contenter de changer le statut.
            $livraison = Livraison::where('commande_id', $c->id)->whereNotIn('statut_livraison', ['livree'])->latest()->first();
            if ($livraison) {
                $livraison->marquerLivree();
            } else {
                $c->update(['statut_commande'=>'livree']);
            }
        } else {
            $c->update(['statut_commande'=>$validated['statut']]);
        }

        DashboardCache::bump();
        AuditLogger::log($request->user(), 'modifier_statut_commande', 'commandes', 'Commande', $c->id, ['statut_commande'=>$statutAvant], ['statut_commande'=>$validated['statut']], $request);
        return response()->json(['success'=>true,'message'=>'Statut mis à jour.']);
    }

    // ----------------------------------------------------------------
    // LIVRAISONS (missions de livraison — distinctes des comptes livreurs)
    // ----------------------------------------------------------------
    public function livraisons(Request $request): JsonResponse
    {
        $q = Livraison::with(['livreur.user', 'commande.client.user', 'commande.vendeur'])
            ->orderBy('created_at', 'desc');
        if ($s = $request->get('statut')) $q->where('statut_livraison', $s);
        if ($livreurId = $request->get('livreur_id')) $q->where('livreur_id', $livreurId);
        return response()->json(['success' => true, 'data' => $q->paginate((int) $request->get('per_page', 20))]);
    }

    public function livraisonDetail(Request $request, string $id): JsonResponse
    {
        $livraison = Livraison::with([
            'livreur.user', 'commande.client.user', 'commande.vendeur',
            'suiviPositions' => fn ($q) => $q->orderByDesc('horodatage')->limit(50),
        ])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $livraison]);
    }

    public function reattribuerLivraison(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['livreur_id' => 'required|uuid|exists:livreurs,id']);
        $livraison = Livraison::findOrFail($id);
        $avant = $livraison->livreur_id;

        DB::transaction(function () use ($livraison, $validated) {
            $livraison->update(['livreur_id' => $validated['livreur_id']]);
            if ($livraison->commande) {
                $livraison->commande->update(['livreur_id' => $validated['livreur_id'], 'mode_attribution' => 'manuelle']);
            }
        });

        DashboardCache::bump();
        AuditLogger::log($request->user(), 'reattribuer_livraison', 'livraisons', 'Livraison', $livraison->id, ['livreur_id' => $avant], ['livreur_id' => $validated['livreur_id']], $request);
        return response()->json(['success' => true, 'message' => 'Livraison réattribuée.']);
    }

    // GET /admin/attribution/file-attente — commandes prêtes à être livrées mais sans livreur
    // assigné (ni auto-acceptées par un livreur, ni poussées manuellement par un admin).
    // Signale celles en attente depuis plus longtemps que le seuil configuré.
    public function attributionFileAttente(Request $request): JsonResponse
    {
        $delaiMinutes = (int) ParametrePlateforme::valeur('attribution_delai_alerte_minutes', '20');
        $seuil = now()->subMinutes($delaiMinutes);

        $commandes = Commande::with(['client.user', 'vendeur', 'beneficiaire'])
            ->whereNull('livreur_id')
            ->whereIn('statut_commande', ['confirmee', 'achat_marche', 'preparation'])
            ->orderBy('date_commande')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'numero_commande' => $c->numero_commande,
                'statut_commande' => $c->statut_commande,
                'mode_attribution' => $c->mode_attribution,
                'client' => $c->client?->user ? ['nom' => $c->client->user->nom, 'prenom' => $c->client->user->prenom] : null,
                'vendeur' => $c->vendeur?->nom_commerce,
                'adresse_livraison' => $c->adresse_livraison,
                'distance_estimee_km' => $c->distance_estimee_km,
                'montant_total' => $c->montant_total,
                'date_commande' => $c->date_commande,
                'en_retard' => $c->date_commande < $seuil,
            ]);

        return response()->json([
            'success' => true,
            'data' => $commandes->values(),
            'meta' => [
                'total' => $commandes->count(),
                'en_retard' => $commandes->where('en_retard', true)->count(),
                'delai_alerte_minutes' => $delaiMinutes,
            ],
        ]);
    }

    // GET /admin/attribution/livreurs — livreurs disponibles pour une attribution manuelle,
    // avec leur charge active comparée au seuil configuré (surcharge = déjà au-delà du seuil).
    public function attributionLivreursDisponibles(Request $request): JsonResponse
    {
        $maxMissions = (int) ParametrePlateforme::valeur('attribution_max_missions_simultanees', '3');

        $livreurs = Livreur::with('user')
            ->where('statut_disponibilite', 'disponible')
            ->where('statut_validation', 'valide')
            ->get()
            ->map(function ($l) use ($maxMissions) {
                $missionsActives = Livraison::where('livreur_id', $l->id)
                    ->whereIn('statut_livraison', ['assigne', 'en_cours'])
                    ->count();
                return [
                    'id' => $l->id,
                    'nom' => $l->user?->nom,
                    'prenom' => $l->user?->prenom,
                    'note_moyenne' => $l->note_moyenne,
                    'missions_actives' => $missionsActives,
                    'surcharge' => $missionsActives >= $maxMissions,
                ];
            })
            ->sortBy('missions_actives')
            ->values();

        return response()->json([
            'success' => true,
            'data' => $livreurs,
            'meta' => ['max_missions_simultanees' => $maxMissions],
        ]);
    }

    // GET /admin/livraisons/positions — dernière position GPS connue de chaque livraison active,
    // pour la carte temps réel. N'expose que les livraisons pour lesquelles une position a
    // effectivement été reçue (aucune coordonnée simulée).
    public function livraisonsPositions(Request $request): JsonResponse
    {
        $livraisons = Livraison::with(['livreur.user', 'commande.vendeur', 'commande.client.user'])
            ->whereIn('statut_livraison', ['assigne', 'en_cours'])
            ->get();

        if ($livraisons->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $dernieresPositions = DB::table('suivi_position_livraisons')
            ->selectRaw('DISTINCT ON (livraison_id) livraison_id, latitude, longitude, horodatage')
            ->whereIn('livraison_id', $livraisons->pluck('id'))
            ->orderBy('livraison_id')
            ->orderByDesc('horodatage')
            ->get()
            ->keyBy('livraison_id');

        $data = $livraisons->map(function ($l) use ($dernieresPositions) {
            $position = $dernieresPositions->get($l->id);
            if (!$position) return null;

            return [
                'livraison_id' => $l->id,
                'commande_id' => $l->commande_id,
                'numero_commande' => $l->commande?->numero_commande,
                'statut_livraison' => $l->statut_livraison,
                'livreur' => $l->livreur ? [
                    'id' => $l->livreur->id,
                    'nom' => trim(($l->livreur->user->prenom ?? '') . ' ' . ($l->livreur->user->nom ?? '')),
                ] : null,
                'client' => $l->commande?->client?->user
                    ? trim(($l->commande->client->user->prenom ?? '') . ' ' . ($l->commande->client->user->nom ?? ''))
                    : null,
                'position' => ['lat' => (float) $position->latitude, 'lng' => (float) $position->longitude, 'horodatage' => $position->horodatage],
                'destination' => $l->commande?->coordonnees_gps_livraison,
                'origine' => $l->commande?->vendeur?->coordonnees_gps,
            ];
        })->filter()->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function ajouterCategorie(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom_categorie' => 'required|string|max:100',
            'categorie_parente_id' => 'nullable|uuid|exists:categories,id',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
        ]);
        unset($validated['photo']);
        if ($request->hasFile('photo')) {
            $validated['icone'] = $request->file('photo')->store('photos/categories', 'supabase');
        }
        $categorie = Categorie::create($validated);
        AuditLogger::log($request->user(), 'creer_categorie', 'catalogue', 'Categorie', $categorie->id, null, $validated, $request);
        return response()->json(['success'=>true,'data'=>$categorie],201);
    }

    public function modifierCategorie(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'nom_categorie' => 'sometimes|string',
            'categorie_parente_id' => 'nullable|uuid|exists:categories,id',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
        ]);
        unset($validated['photo']);
        $categorie = Categorie::findOrFail($id);
        $avant = $categorie->only(array_keys($validated));
        if ($request->hasFile('photo')) {
            if ($categorie->icone && str_contains($categorie->icone, '/')) {
                Storage::disk('supabase')->delete($categorie->icone);
            }
            $validated['icone'] = $request->file('photo')->store('photos/categories', 'supabase');
        }
        $categorie->update($validated);
        AuditLogger::log($request->user(), 'modifier_categorie', 'catalogue', 'Categorie', $categorie->id, $avant, $validated, $request);
        return response()->json(['success'=>true,'data'=>$categorie]);
    }

    public function supprimerCategorie(Request $request, string $id): JsonResponse
    {
        $c = Categorie::findOrFail($id);
        if ($c->produits()->count() > 0) return response()->json(['success'=>false,'message'=>'Catégorie utilisée.'],400);
        if ($c->icone && str_contains($c->icone, '/')) {
            Storage::disk('supabase')->delete($c->icone);
        }
        AuditLogger::log($request->user(), 'supprimer_categorie', 'catalogue', 'Categorie', $c->id, $c->only(['nom_categorie']), null, $request);
        $c->delete();
        return response()->json(['success'=>true,'message'=>'Catégorie supprimée.']);
    }

    // GET /api/admin/types-boutique — la liste complète des types de boutique gérés par un admin
    // (App\Models\TypeBoutique), avec leur logo optionnel. Contrairement à
    // CatalogueController::vendeurTypesAvecLogos() (public, filtré aux types réellement utilisés par
    // une boutique ouverte/validée), cette liste est complète : un admin doit pouvoir créer/préparer
    // le logo d'un type avant même qu'un premier vendeur ne le choisisse.
    public function typesBoutique(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => TypeBoutique::orderBy('type')->get()]);
    }

    public function ajouterTypeBoutique(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|max:150|unique:types_boutique,type',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
        ]);
        unset($validated['logo']);
        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')->store('photos/types_boutique', 'supabase');
        }
        $typeBoutique = TypeBoutique::create($validated);
        AuditLogger::log($request->user(), 'creer_type_boutique', 'catalogue', 'TypeBoutique', $typeBoutique->id, null, $validated, $request);
        return response()->json(['success' => true, 'data' => $typeBoutique], 201);
    }

    public function modifierTypeBoutique(Request $request, string $id): JsonResponse
    {
        $typeBoutique = TypeBoutique::findOrFail($id);
        $validated = $request->validate([
            'type' => 'sometimes|string|max:150|unique:types_boutique,type,' . $typeBoutique->id,
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
        ]);
        unset($validated['logo']);
        $avant = $typeBoutique->only(['type', 'logo']);
        if ($request->hasFile('logo')) {
            if ($typeBoutique->logo) {
                Storage::disk('supabase')->delete($typeBoutique->logo);
            }
            $validated['logo'] = $request->file('logo')->store('photos/types_boutique', 'supabase');
        }

        // Un type renommé reste le même type pour les boutiques qui l'avaient déjà choisi — sinon
        // elles se retrouveraient silencieusement avec une categorie_principale qui n'existe plus
        // dans la liste gérée par l'admin (et redeviendraient rejetées à la moindre autre
        // modification de profil, la validation étant faite contre TypeBoutique::libellesValides()).
        if (!empty($validated['type']) && $validated['type'] !== $typeBoutique->type) {
            Vendeur::where('categorie_principale', $typeBoutique->type)->update(['categorie_principale' => $validated['type']]);
        }

        $typeBoutique->update($validated);
        AuditLogger::log($request->user(), 'modifier_type_boutique', 'catalogue', 'TypeBoutique', $typeBoutique->id, $avant, $validated, $request);
        return response()->json(['success' => true, 'data' => $typeBoutique->fresh()]);
    }

    public function supprimerTypeBoutique(Request $request, string $id): JsonResponse
    {
        $typeBoutique = TypeBoutique::findOrFail($id);
        if (Vendeur::where('categorie_principale', $typeBoutique->type)->exists()) {
            return response()->json(['success' => false, 'message' => 'Ce type de boutique est utilisé par au moins un vendeur.'], 400);
        }
        if ($typeBoutique->logo) {
            Storage::disk('supabase')->delete($typeBoutique->logo);
        }
        AuditLogger::log($request->user(), 'supprimer_type_boutique', 'catalogue', 'TypeBoutique', $typeBoutique->id, $typeBoutique->only(['type']), null, $request);
        $typeBoutique->delete();
        return response()->json(['success' => true, 'message' => 'Type de boutique supprimé.']);
    }

    public function zones(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>ZoneLivraison::orderBy('nom_zone')->paginate((int) $request->get('per_page', 20))]);
    }

    public function ajouterZone(Request $request): JsonResponse
    {
        $validated = $request->validate(['nom_zone'=>'required|string|max:100','ville'=>'required|string|max:100','quartiers_couverts'=>'required|array','frais_livraison_base'=>'required|numeric|min:0','delai_estime_min'=>'required|integer|min:0','delai_estime_max'=>'required|integer|gte:delai_estime_min']);
        $zone = ZoneLivraison::create($validated+['statut_actif'=>true]);
        AuditLogger::log($request->user(), 'creer_zone', 'zones', 'ZoneLivraison', $zone->id, null, $validated, $request);
        return response()->json(['success'=>true,'data'=>$zone],201);
    }

    public function modifierZone(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'nom_zone'=>'sometimes|string|max:100',
            'ville'=>'sometimes|string|max:100',
            'quartiers_couverts'=>'sometimes|array',
            'frais_livraison_base'=>'sometimes|numeric|min:0',
            'delai_estime_min'=>'sometimes|integer|min:0',
            'delai_estime_max'=>'sometimes|integer|gte:delai_estime_min',
            'statut_actif'=>'sometimes|boolean',
        ]);
        $zone = ZoneLivraison::findOrFail($id);
        $avant = $zone->only(array_keys($validated));
        $zone->update($validated);
        AuditLogger::log($request->user(), 'modifier_zone', 'zones', 'ZoneLivraison', $zone->id, $avant, $validated, $request);
        return response()->json(['success'=>true,'message'=>'Zone mise à jour.']);
    }

    public function supprimerZone(Request $request, string $id): JsonResponse
    {
        $zone = ZoneLivraison::findOrFail($id);
        AuditLogger::log($request->user(), 'supprimer_zone', 'zones', 'ZoneLivraison', $zone->id, $zone->only(['nom_zone']), null, $request);
        $zone->delete();
        return response()->json(['success'=>true,'message'=>'Zone supprimée.']);
    }

    // ----------------------------------------------------------------
    // CATALOGUE — PRODUITS & STOCK (admin, transversal à tous les vendeurs)
    // ----------------------------------------------------------------
    public function produits(Request $request): JsonResponse
    {
        $seuil = (int) ParametrePlateforme::firstOrCreate(
            ['cle' => 'seuil_stock_faible'],
            ['valeur' => '10', 'description' => "Quantité en stock en dessous de laquelle un produit est signalé « stock faible »."]
        )->valeur;

        $base = Produit::query();
        if ($cat = $request->get('categorie_id')) $base->where('categorie_id', $cat);
        if ($vendeurId = $request->get('vendeur_id')) $base->where('vendeur_id', $vendeurId);
        if ($search = $request->get('search')) $base->where('nom_produit', 'like', "%{$search}%");

        $etatConditions = [
            'rupture' => fn ($q) => $q->where(fn ($q) => $q->where('statut_disponibilite', 'rupture')->orWhere('quantite_stock', '<=', 0)),
            'stock_faible' => fn ($q) => $q->where('statut_disponibilite', 'disponible')->where('quantite_stock', '>', 0)->where('quantite_stock', '<=', $seuil),
            'disponible' => fn ($q) => $q->where('statut_disponibilite', 'disponible')->where('quantite_stock', '>', $seuil),
        ];

        $counts = [];
        foreach ($etatConditions as $etatKey => $condition) {
            $counts[$etatKey] = (clone $base)->tap($condition)->count();
        }

        $q = (clone $base)->with(['vendeur:id,nom_commerce', 'categorie:id,nom_categorie']);
        if ($etat = $request->get('etat_stock')) {
            if (isset($etatConditions[$etat])) $q->tap($etatConditions[$etat]);
        }

        $produits = $q->orderBy('nom_produit')->paginate((int) $request->get('per_page', 20))->withQueryString();

        $produits->getCollection()->transform(function ($p) use ($seuil) {
            $p->etat_stock = $p->statut_disponibilite === 'rupture' || $p->quantite_stock <= 0
                ? 'rupture'
                : ($p->quantite_stock <= $seuil ? 'stock_faible' : 'disponible');
            $p->seuil_stock_faible = $seuil;
            return $p;
        });

        return response()->json(['success' => true, 'data' => array_merge($produits->toArray(), ['counts' => $counts])]);
    }

    public function modifierProduit(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'nom_produit' => 'sometimes|string|max:150',
            'description' => 'sometimes|nullable|string',
            'prix_unitaire' => 'sometimes|numeric|min:0',
            'categorie_id' => 'sometimes|uuid|exists:categories,id',
            'quantite_stock' => 'sometimes|integer|min:0',
            'unite_mesure' => 'sometimes|string|max:50',
            'type_fraicheur' => 'sometimes|in:frais,fume,congele',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
        ]);
        unset($validated['photo']);
        $produit = Produit::findOrFail($id);
        $avant = $produit->only(array_keys($validated));
        if ($request->hasFile('photo')) {
            if ($produit->photo_produit) {
                Storage::disk('supabase')->delete($produit->photo_produit);
            }
            $validated['photo_produit'] = $request->file('photo')->store('photos/produits', 'supabase');
        }
        if (isset($validated['prix_unitaire'])) {
            $validated['date_maj_prix'] = now();
        }
        if (isset($validated['quantite_stock'])) {
            $validated['statut_disponibilite'] = $validated['quantite_stock'] > 0 ? 'disponible' : 'rupture';
        }
        $produit->update($validated);
        AuditLogger::log($request->user(), 'modifier_produit', 'produits', 'Produit', $produit->id, $avant, $validated, $request);
        return response()->json(['success' => true, 'message' => 'Produit mis à jour.', 'data' => $produit]);
    }

    public function activerProduit(Request $request, string $id): JsonResponse
    {
        $produit = Produit::findOrFail($id);
        $avant = $produit->statut_disponibilite;
        $produit->update(['statut_disponibilite' => 'disponible']);
        AuditLogger::log($request->user(), 'activer_produit', 'produits', 'Produit', $produit->id, ['statut_disponibilite' => $avant], ['statut_disponibilite' => 'disponible'], $request);
        return response()->json(['success' => true, 'message' => 'Produit activé.']);
    }

    public function desactiverProduit(Request $request, string $id): JsonResponse
    {
        $produit = Produit::findOrFail($id);
        $avant = $produit->statut_disponibilite;
        $produit->update(['statut_disponibilite' => 'rupture']);
        AuditLogger::log($request->user(), 'desactiver_produit', 'produits', 'Produit', $produit->id, ['statut_disponibilite' => $avant], ['statut_disponibilite' => 'rupture'], $request);
        return response()->json(['success' => true, 'message' => 'Produit désactivé.']);
    }

    public function supprimerProduit(Request $request, string $id): JsonResponse
    {
        $produit = Produit::findOrFail($id);
        if ($produit->lignesCommande()->count() > 0) {
            return response()->json(['success' => false, 'message' => 'Ce produit a déjà été commandé et ne peut pas être supprimé.'], 400);
        }
        if ($produit->photo_produit) Storage::disk('supabase')->delete($produit->photo_produit);
        AuditLogger::log($request->user(), 'supprimer_produit', 'produits', 'Produit', $produit->id, $produit->only(['nom_produit', 'vendeur_id']), null, $request);
        $produit->delete();
        return response()->json(['success' => true, 'message' => 'Produit supprimé.']);
    }

    // ----------------------------------------------------------------
    // MARKETING — PROMOTIONS
    // ----------------------------------------------------------------
    public function promotions(Request $request): JsonResponse
    {
        $q = Promotion::with('produits.produit')->orderBy('created_at', 'desc');
        if ($request->boolean('actif_uniquement')) {
            $q->where('statut_actif', true)->where('date_debut', '<=', now())->where('date_fin', '>=', now());
        }
        return response()->json(['success' => true, 'data' => $q->paginate((int) $request->get('per_page', 20))]);
    }

    public function creerPromotion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'titre' => 'required|string|max:150',
            'description' => 'nullable|string',
            'type_reduction' => 'required|in:pourcentage,montant_fixe',
            'valeur_reduction' => 'required|numeric|min:0',
            'date_debut' => 'required|date',
            'date_fin' => 'required|date|after:date_debut',
            'statut_actif' => 'sometimes|boolean',
            'image_bandeau' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
        ]);
        unset($validated['image_bandeau']);
        if ($request->hasFile('image_bandeau')) {
            $validated['image_bandeau'] = $request->file('image_bandeau')->store('photos/promotions', 'supabase');
        }
        $promotion = Promotion::create($validated + ['statut_actif' => $validated['statut_actif'] ?? true]);
        AuditLogger::log($request->user(), 'creer_promotion', 'marketing', 'Promotion', $promotion->id, null, $validated, $request);
        return response()->json(['success' => true, 'data' => $promotion], 201);
    }

    public function modifierPromotion(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'titre' => 'sometimes|string|max:150',
            'description' => 'sometimes|nullable|string',
            'type_reduction' => 'sometimes|in:pourcentage,montant_fixe',
            'valeur_reduction' => 'sometimes|numeric|min:0',
            'date_debut' => 'sometimes|date',
            'date_fin' => 'sometimes|date|after:date_debut',
            'statut_actif' => 'sometimes|boolean',
            'image_bandeau' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072',
        ]);
        unset($validated['image_bandeau']);
        $promotion = Promotion::findOrFail($id);
        if ($request->hasFile('image_bandeau')) {
            if ($promotion->image_bandeau) {
                Storage::disk('supabase')->delete($promotion->image_bandeau);
            }
            $validated['image_bandeau'] = $request->file('image_bandeau')->store('photos/promotions', 'supabase');
        }
        $avant = $promotion->only(array_keys($validated));
        $promotion->update($validated);
        AuditLogger::log($request->user(), 'modifier_promotion', 'marketing', 'Promotion', $promotion->id, $avant, $validated, $request);
        return response()->json(['success' => true, 'data' => $promotion]);
    }

    public function supprimerPromotion(Request $request, string $id): JsonResponse
    {
        $promotion = Promotion::findOrFail($id);
        AuditLogger::log($request->user(), 'supprimer_promotion', 'marketing', 'Promotion', $promotion->id, $promotion->only(['titre']), null, $request);
        $promotion->produits()->delete();
        $promotion->delete();
        return response()->json(['success' => true, 'message' => 'Promotion supprimée.']);
    }

    public function ajouterProduitPromotion(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'produit_id' => 'required|uuid|exists:produits,id',
            'prix_promo' => 'required|numeric|min:0',
        ]);
        $promotion = Promotion::findOrFail($id);
        $pp = PromotionProduit::updateOrCreate(
            ['promotion_id' => $promotion->id, 'produit_id' => $validated['produit_id']],
            ['prix_promo' => $validated['prix_promo']]
        );
        AuditLogger::log($request->user(), 'ajouter_produit_promotion', 'marketing', 'PromotionProduit', $pp->id, null, $validated, $request);
        return response()->json(['success' => true, 'data' => $pp->load('produit')], 201);
    }

    public function retirerProduitPromotion(Request $request, string $id, string $produitId): JsonResponse
    {
        $pp = PromotionProduit::where('promotion_id', $id)->where('produit_id', $produitId)->firstOrFail();
        AuditLogger::log($request->user(), 'retirer_produit_promotion', 'marketing', 'PromotionProduit', $pp->id, $pp->only(['produit_id', 'prix_promo']), null, $request);
        $pp->delete();
        return response()->json(['success' => true, 'message' => 'Produit retiré de la promotion.']);
    }

    // ----------------------------------------------------------------
    // MARKETING — COUPONS
    // ----------------------------------------------------------------
    public function coupons(Request $request): JsonResponse
    {
        $q = Coupon::withCount('commandes')->orderBy('created_at', 'desc');
        if ($statut = $request->get('statut')) {
            match ($statut) {
                'actif' => $q->where('statut_actif', true)->where('date_fin', '>=', now()),
                'expire' => $q->where('date_fin', '<', now()),
                'inactif' => $q->where('statut_actif', false),
                default => null,
            };
        }
        if ($search = $request->get('search')) $q->where('code', 'like', '%' . strtoupper($search) . '%');
        return response()->json(['success' => true, 'data' => $q->paginate((int) $request->get('per_page', 20))]);
    }

    public function creerCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:30|unique:coupons,code',
            'description' => 'nullable|string',
            'type_reduction' => 'required|in:pourcentage,montant_fixe',
            'valeur_reduction' => 'required|numeric|min:0',
            'montant_minimum_commande' => 'sometimes|numeric|min:0',
            'montant_maximum_reduction' => 'nullable|numeric|min:0',
            'date_debut' => 'required|date',
            'date_fin' => 'required|date|after:date_debut',
            'limite_utilisation_totale' => 'nullable|integer|min:1',
            'limite_utilisation_par_client' => 'nullable|integer|min:1',
            'statut_actif' => 'sometimes|boolean',
        ]);
        $validated['code'] = strtoupper(trim($validated['code']));
        $coupon = Coupon::create($validated);
        AuditLogger::log($request->user(), 'creer_coupon', 'marketing', 'Coupon', $coupon->id, null, $validated, $request);
        return response()->json(['success' => true, 'data' => $coupon], 201);
    }

    public function modifierCoupon(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'sometimes|nullable|string',
            'type_reduction' => 'sometimes|in:pourcentage,montant_fixe',
            'valeur_reduction' => 'sometimes|numeric|min:0',
            'montant_minimum_commande' => 'sometimes|numeric|min:0',
            'montant_maximum_reduction' => 'nullable|numeric|min:0',
            'date_debut' => 'sometimes|date',
            'date_fin' => 'sometimes|date|after:date_debut',
            'limite_utilisation_totale' => 'nullable|integer|min:1',
            'limite_utilisation_par_client' => 'nullable|integer|min:1',
            'statut_actif' => 'sometimes|boolean',
        ]);
        $coupon = Coupon::findOrFail($id);
        $avant = $coupon->only(array_keys($validated));
        $coupon->update($validated);
        AuditLogger::log($request->user(), 'modifier_coupon', 'marketing', 'Coupon', $coupon->id, $avant, $validated, $request);
        return response()->json(['success' => true, 'data' => $coupon]);
    }

    public function supprimerCoupon(Request $request, string $id): JsonResponse
    {
        $coupon = Coupon::findOrFail($id);
        if ($coupon->commandes()->count() > 0) {
            return response()->json(['success' => false, 'message' => 'Ce coupon a déjà été utilisé et ne peut pas être supprimé.'], 400);
        }
        AuditLogger::log($request->user(), 'supprimer_coupon', 'marketing', 'Coupon', $coupon->id, $coupon->only(['code']), null, $request);
        $coupon->delete();
        return response()->json(['success' => true, 'message' => 'Coupon supprimé.']);
    }

    // ----------------------------------------------------------------
    // NOTIFICATIONS (diffusion admin → utilisateurs)
    // ----------------------------------------------------------------
    public function campagnesNotifications(Request $request): JsonResponse
    {
        $q = CampagneNotification::orderBy('created_at', 'desc');
        if ($statut = $request->get('statut')) $q->where('statut', $statut);
        return response()->json(['success' => true, 'data' => $q->paginate((int) $request->get('per_page', 20))]);
    }

    public function creerCampagneNotification(Request $request, NotificationBroadcastService $broadcast): JsonResponse
    {
        $validated = $request->validate([
            'titre' => 'required|string|max:150',
            'message' => 'required|string|max:1000',
            'cible_type' => 'required|in:tous,clients,vendeurs,livreurs,diaspora',
            'envoyer_push' => 'sometimes|boolean',
            'envoyer_sms' => 'sometimes|boolean',
            'lien_action' => 'nullable|string|max:255',
            'date_envoi' => 'nullable|date',
        ]);

        $campagne = CampagneNotification::create($validated + [
            'statut' => 'programmee',
            'admin_id' => $request->user()->administrateur?->id,
        ]);

        AuditLogger::log($request->user(), 'creer_campagne_notification', 'notifications', 'CampagneNotification', $campagne->id, null, $validated, $request);

        if ($campagne->estDue()) {
            $broadcast->envoyer($campagne);
        }

        return response()->json(['success' => true, 'data' => $campagne->fresh()], 201);
    }

    public function supprimerCampagneNotification(Request $request, string $id): JsonResponse
    {
        $campagne = CampagneNotification::findOrFail($id);
        if ($campagne->statut !== 'programmee') {
            return response()->json(['success' => false, 'message' => 'Seule une campagne encore programmée peut être annulée.'], 400);
        }
        AuditLogger::log($request->user(), 'annuler_campagne_notification', 'notifications', 'CampagneNotification', $campagne->id, $campagne->only(['titre']), null, $request);
        $campagne->delete();
        return response()->json(['success' => true, 'message' => 'Campagne annulée.']);
    }

    // GET /api/admin/litige-motifs — remplace l'ancienne constante LitigeController::MOTIFS,
    // gérable ici plutôt que codée en dur indépendamment côté backend/mobile/web.
    public function litigeMotifs(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => LitigeMotif::orderBy('code')->get()]);
    }

    public function ajouterLitigeMotif(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:100|alpha_dash|unique:litige_motifs,code',
            'libelle' => 'required|string|max:150',
        ]);
        $motif = LitigeMotif::create($validated);
        AuditLogger::log($request->user(), 'creer_litige_motif', 'litiges', 'LitigeMotif', $motif->id, null, $validated, $request);
        return response()->json(['success' => true, 'data' => $motif], 201);
    }

    public function modifierLitigeMotif(Request $request, string $id): JsonResponse
    {
        $motif = LitigeMotif::findOrFail($id);
        $validated = $request->validate([
            'code' => 'sometimes|string|max:100|alpha_dash|unique:litige_motifs,code,' . $motif->id,
            'libelle' => 'sometimes|string|max:150',
        ]);
        $avant = $motif->only(['code', 'libelle']);

        // Un code renommé reste le même motif pour les litiges déjà ouverts sous ce code — sinon
        // ils se retrouveraient avec un `motif` qui n'existe plus dans la liste gérée par l'admin.
        if (!empty($validated['code']) && $validated['code'] !== $motif->code) {
            Litige::where('motif', $motif->code)->update(['motif' => $validated['code']]);
        }

        $motif->update($validated);
        AuditLogger::log($request->user(), 'modifier_litige_motif', 'litiges', 'LitigeMotif', $motif->id, $avant, $validated, $request);
        return response()->json(['success' => true, 'data' => $motif->fresh()]);
    }

    public function supprimerLitigeMotif(Request $request, string $id): JsonResponse
    {
        $motif = LitigeMotif::findOrFail($id);
        if (Litige::where('motif', $motif->code)->exists()) {
            return response()->json(['success' => false, 'message' => 'Ce motif est utilisé par au moins un litige.'], 400);
        }
        AuditLogger::log($request->user(), 'supprimer_litige_motif', 'litiges', 'LitigeMotif', $motif->id, $motif->only(['code', 'libelle']), null, $request);
        $motif->delete();
        return response()->json(['success' => true, 'message' => 'Motif supprimé.']);
    }

    public function litiges(Request $request): JsonResponse
    {
        $q = Litige::with(['commande', 'plaignant', 'adminTraitant.user'])->orderBy('date_ouverture', 'desc');
        if ($statut = $request->get('statut')) $q->where('statut', $statut);
        if ($motif = $request->get('motif')) $q->where('motif', $motif);
        if ($commandeId = $request->get('commande_id')) $q->where('commande_id', $commandeId);
        if ($debut = $request->get('date_debut')) $q->whereDate('date_ouverture', '>=', $debut);
        if ($fin = $request->get('date_fin')) $q->whereDate('date_ouverture', '<=', $fin);
        return response()->json(['success'=>true,'data'=>$q->paginate((int) $request->get('per_page', 20))]);
    }

    public function litigeDetail(Request $request, string $id): JsonResponse
    {
        $litige = Litige::with([
            'commande.client.user', 'commande.vendeur.user', 'commande.lignes.produit', 'commande.paiement',
            'plaignant', 'adminTraitant.user',
            'messages.user', 'piecesJointes.auteur', 'decisions.admin.user', 'remboursements',
        ])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $litige]);
    }

    public function historiqueLitige(Request $request, string $id): JsonResponse
    {
        Litige::findOrFail($id);
        $logs = LogActivite::with('user')
            ->where('details->module', 'litiges')
            ->where('details->cible_id', $id)
            ->orderBy('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $logs]);
    }

    // Traitement rapide historique (statut + décision libre, sans montant) — conservé pour la
    // modale "Traiter" existante du front-end. decisionLitige() ci-dessous est le point d'entrée
    // complet (type de décision structuré, remboursement, notifications) à utiliser depuis la
    // future page de détail.
    public function traiterLitige(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['statut'=>'required|in:en_cours,resolu,rejete','decision'=>'required|string|max:1000']);
        $litige = Litige::with(['plaignant', 'commande.vendeur.user'])->findOrFail($id);

        if (!$litige->peutTransitionnerVers($validated['statut'])) {
            return response()->json(['success' => false, 'message' => 'Transition de statut invalide depuis l\'état actuel (' . $litige->statut_label . ').'], 400);
        }

        $statutAvant = $litige->statut;
        $admin = $request->user()->administrateur;
        $estTerminal = in_array($validated['statut'], ['resolu', 'rejete'], true);

        DB::transaction(function () use ($litige, $validated, $admin, $request, $estTerminal) {
            $litige->update([
                'statut' => $validated['statut'],
                'decision' => $validated['decision'],
                'admin_traitant_id' => $admin?->id,
                'date_resolution' => $estTerminal ? now() : null,
            ]);

            if ($estTerminal) {
                LitigeDecision::create([
                    'litige_id' => $litige->id,
                    'admin_id' => $admin?->id,
                    'decision_type' => $validated['statut'] === 'resolu' ? 'acceptee' : 'rejetee',
                    'reason' => $validated['decision'],
                    'created_at' => now(),
                ]);
            }

            LitigeMessage::create([
                'litige_id' => $litige->id,
                'user_id' => $request->user()->id,
                'sender_type' => 'system',
                'message' => 'Décision administrative : ' . $validated['decision'],
            ]);
        });

        foreach (array_filter([$litige->plaignant, $litige->commande?->vendeur?->user]) as $destinataire) {
            app(PushService::class)->envoyerAUtilisateur(
                $destinataire,
                "Litige {$litige->numero} — mise à jour",
                Str::limit($validated['decision'], 100),
                ['type' => 'litige_decision', 'litige_id' => $litige->id]
            );
        }

        AuditLogger::log($request->user(), 'traiter_litige', 'litiges', 'Litige', $litige->id, ['statut'=>$statutAvant], ['statut'=>$validated['statut'],'decision'=>$validated['decision']], $request);
        return response()->json(['success'=>true,'message'=>'Litige traité.']);
    }

    public function demanderInformationsLitige(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'cible' => 'required|in:client,vendeur',
            'message' => 'required|string|max:1000',
        ]);

        $litige = Litige::with(['commande.vendeur.user', 'plaignant'])->findOrFail($id);

        if ($litige->estResolu()) {
            return response()->json(['success' => false, 'message' => 'Ce litige est déjà clôturé.'], 400);
        }

        $nouveauStatut = $validated['cible'] === 'client' ? 'attente_client' : 'attente_vendeur';
        if (!$litige->peutTransitionnerVers($nouveauStatut)) {
            return response()->json(['success' => false, 'message' => 'Transition de statut invalide.'], 400);
        }

        $admin = $request->user()->administrateur;

        DB::transaction(function () use ($litige, $validated, $nouveauStatut, $admin, $request) {
            LitigeMessage::create([
                'litige_id' => $litige->id,
                'user_id' => $request->user()->id,
                'sender_type' => 'admin',
                'message' => $validated['message'],
            ]);
            $litige->update(['statut' => $nouveauStatut, 'admin_traitant_id' => $admin?->id]);
        });

        $destinataire = $validated['cible'] === 'client' ? $litige->plaignant : $litige->commande?->vendeur?->user;
        if ($destinataire) {
            app(PushService::class)->envoyerAUtilisateur(
                $destinataire,
                "Litige {$litige->numero} — informations demandées",
                Str::limit($validated['message'], 100),
                ['type' => 'litige_demande_info', 'litige_id' => $litige->id]
            );
        }

        AuditLogger::log($request->user(), 'demander_informations_litige', 'litiges', 'Litige', $litige->id, null, ['cible' => $validated['cible'], 'statut' => $nouveauStatut], $request);

        return response()->json(['success' => true, 'message' => 'Demande d\'informations envoyée.']);
    }

    public function decisionLitige(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'decision_type' => 'required|in:acceptee,rejetee,remboursement_total,remboursement_partiel,remplacement_produit,retour_produit,dedommagement_vendeur,dedommagement_client,aucune_action',
            'reason' => 'required|string|max:1000',
            'amount' => 'nullable|numeric|min:0',
        ]);

        $litige = Litige::with(['commande.paiement', 'commande.vendeur.user', 'plaignant'])->findOrFail($id);

        if ($litige->estResolu()) {
            return response()->json(['success' => false, 'message' => 'Ce litige est déjà clôturé.'], 400);
        }

        $estRemboursement = in_array($validated['decision_type'], LitigeDecision::DECLENCHE_REMBOURSEMENT, true);
        if ($estRemboursement && empty($validated['amount'])) {
            return response()->json(['success' => false, 'message' => 'Un montant est requis pour un remboursement.'], 422);
        }

        $montantCommande = (float) $litige->commande->montant_total;
        $dejaRembourse = (float) $litige->remboursements()->whereIn('statut', ['en_attente', 'en_traitement', 'termine'])->sum('montant');
        if ($estRemboursement && ((float) $validated['amount'] + $dejaRembourse) > $montantCommande) {
            return response()->json(['success' => false, 'message' => 'Le montant demandé dépasse le montant remboursable restant (' . number_format($montantCommande - $dejaRembourse, 0, ',', ' ') . ' FCFA).'], 422);
        }

        $nouveauStatut = in_array($validated['decision_type'], ['rejetee', 'aucune_action'], true) ? 'rejete' : 'resolu';
        if (!$litige->peutTransitionnerVers($nouveauStatut)) {
            return response()->json(['success' => false, 'message' => 'Transition de statut invalide depuis l\'état actuel (' . $litige->statut_label . ').'], 400);
        }

        $admin = $request->user()->administrateur;
        $devise = $litige->commande->paiement?->devise ?? $litige->commande->devise_paiement ?? 'FCFA';
        $decision = null;
        $remboursement = null;

        DB::transaction(function () use (&$decision, &$remboursement, $litige, $validated, $nouveauStatut, $admin, $estRemboursement, $devise, $request) {
            $decision = LitigeDecision::create([
                'litige_id' => $litige->id,
                'admin_id' => $admin?->id,
                'decision_type' => $validated['decision_type'],
                'reason' => $validated['reason'],
                'amount' => $validated['amount'] ?? null,
                'currency' => $estRemboursement ? $devise : null,
                'created_at' => now(),
            ]);

            $litige->update([
                'statut' => $nouveauStatut,
                'decision' => $validated['reason'],
                'admin_traitant_id' => $admin?->id,
                'date_resolution' => now(),
            ]);

            if ($estRemboursement) {
                $remboursement = LitigeRemboursement::create([
                    'litige_id' => $litige->id,
                    'decision_id' => $decision->id,
                    'montant' => $validated['amount'],
                    'devise' => $devise,
                    'statut' => 'en_attente',
                ]);
            }

            LitigeMessage::create([
                'litige_id' => $litige->id,
                'user_id' => $request->user()->id,
                'sender_type' => 'system',
                'message' => 'Décision administrative : ' . $validated['reason'],
            ]);
        });

        if ($remboursement) {
            $this->tenterRemboursementAutomatiqueLitige($remboursement, $litige->commande);
        }

        DashboardCache::bump();
        AuditLogger::log($request->user(), 'decision_litige', 'litiges', 'Litige', $litige->id,
            null, ['decision_type' => $validated['decision_type'], 'amount' => $validated['amount'] ?? null], $request);

        foreach (array_filter([$litige->plaignant, $litige->commande?->vendeur?->user]) as $destinataire) {
            app(PushService::class)->envoyerAUtilisateur(
                $destinataire,
                "Litige {$litige->numero} résolu",
                Str::limit($validated['reason'], 100),
                ['type' => 'litige_decision', 'litige_id' => $litige->id, 'decision_type' => $validated['decision_type']]
            );
        }

        return response()->json(['success' => true, 'message' => 'Décision enregistrée.', 'data' => $decision->load('remboursement')]);
    }

    public function rembourserLitige(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['montant' => 'required|numeric|min:1']);
        $litige = Litige::with(['commande.paiement'])->findOrFail($id);

        $montantCommande = (float) $litige->commande->montant_total;
        $dejaRembourse = (float) $litige->remboursements()->whereIn('statut', ['en_attente', 'en_traitement', 'termine'])->sum('montant');
        if (($validated['montant'] + $dejaRembourse) > $montantCommande) {
            return response()->json(['success' => false, 'message' => 'Le montant demandé dépasse le montant remboursable restant (' . number_format($montantCommande - $dejaRembourse, 0, ',', ' ') . ' FCFA).'], 422);
        }

        $devise = $litige->commande->paiement?->devise ?? $litige->commande->devise_paiement ?? 'FCFA';
        $remboursement = LitigeRemboursement::create([
            'litige_id' => $litige->id,
            'montant' => $validated['montant'],
            'devise' => $devise,
            'statut' => 'en_attente',
        ]);

        $this->tenterRemboursementAutomatiqueLitige($remboursement, $litige->commande);

        AuditLogger::log($request->user(), 'rembourser_litige', 'litiges', 'Litige', $litige->id, null, ['montant' => $validated['montant']], $request);

        return response()->json(['success' => true, 'message' => 'Remboursement enregistré.', 'data' => $remboursement->fresh()]);
    }

    public function escaladerLitige(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['note' => 'nullable|string|max:1000']);
        $litige = Litige::findOrFail($id);

        if (!$litige->peutTransitionnerVers('escalade')) {
            return response()->json(['success' => false, 'message' => 'Ce litige ne peut plus être escaladé.'], 400);
        }

        DB::transaction(function () use ($litige, $validated, $request) {
            $litige->update(['statut' => 'escalade']);
            if (!empty($validated['note'])) {
                LitigeMessage::create([
                    'litige_id' => $litige->id,
                    'user_id' => $request->user()->id,
                    'sender_type' => 'admin',
                    'message' => $validated['note'],
                    'est_note_interne' => true,
                ]);
            }
        });

        $superAdmins = Administrateur::where('role_admin', 'super_admin')->with('user')->get()->pluck('user')->filter();
        app(PushService::class)->envoyerAUtilisateurs(
            $superAdmins,
            "Litige {$litige->numero} escaladé",
            $validated['note'] ?? 'Un litige nécessite une attention prioritaire.',
            ['type' => 'litige_escalade', 'litige_id' => $litige->id]
        );

        AuditLogger::log($request->user(), 'escalader_litige', 'litiges', 'Litige', $litige->id, null, ['note' => $validated['note'] ?? null], $request);

        return response()->json(['success' => true, 'message' => 'Litige escaladé.']);
    }

    // ----------------------------------------------------------------
    // SUPPORT — TICKETS (vue admin)
    // ----------------------------------------------------------------
    public function tickets(Request $request): JsonResponse
    {
        $q = TicketSupport::with(['user', 'adminAssigne.user', 'commande'])->orderBy('created_at', 'desc');
        if ($statut = $request->get('statut')) $q->where('statut', $statut);
        if ($categorie = $request->get('categorie')) $q->where('categorie', $categorie);
        if ($priorite = $request->get('priorite')) $q->where('priorite', $priorite);
        if ($request->boolean('non_assignes')) $q->whereNull('admin_assigne_id');
        return response()->json(['success' => true, 'data' => $q->paginate((int) $request->get('per_page', 20))]);
    }

    public function ticketDetail(Request $request, string $id): JsonResponse
    {
        $ticket = TicketSupport::with(['user', 'adminAssigne.user', 'commande', 'reponses.auteur'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $ticket]);
    }

    public function repondreTicket(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'est_note_interne' => 'sometimes|boolean',
        ]);
        $ticket = TicketSupport::findOrFail($id);
        $estNoteInterne = $validated['est_note_interne'] ?? false;

        TicketReponse::create([
            'ticket_id' => $ticket->id,
            'auteur_id' => $request->user()->id,
            'message' => $validated['message'],
            'est_note_interne' => $estNoteInterne,
        ]);

        $update = ['date_derniere_reponse' => now()];
        if (!$estNoteInterne && $ticket->statut === 'ouvert') {
            $update['statut'] = 'en_cours';
        }
        $ticket->update($update);

        AuditLogger::log($request->user(), 'repondre_ticket', 'support', 'TicketSupport', $ticket->id, null, ['est_note_interne' => $estNoteInterne], $request);
        return response()->json(['success' => true, 'message' => 'Réponse envoyée.']);
    }

    public function assignerTicket(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['admin_id' => 'required|uuid|exists:administrateurs,id']);
        $ticket = TicketSupport::findOrFail($id);
        $avant = $ticket->admin_assigne_id;
        $ticket->update(['admin_assigne_id' => $validated['admin_id']]);
        AuditLogger::log($request->user(), 'assigner_ticket', 'support', 'TicketSupport', $ticket->id, ['admin_assigne_id' => $avant], ['admin_assigne_id' => $validated['admin_id']], $request);
        return response()->json(['success' => true, 'message' => 'Ticket assigné.']);
    }

    public function modifierStatutTicket(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['statut' => 'required|in:ouvert,en_cours,en_attente,resolu,ferme']);
        $ticket = TicketSupport::findOrFail($id);
        $avant = $ticket->statut;
        $ticket->update(['statut' => $validated['statut']]);
        AuditLogger::log($request->user(), 'modifier_statut_ticket', 'support', 'TicketSupport', $ticket->id, ['statut' => $avant], ['statut' => $validated['statut']], $request);
        return response()->json(['success' => true, 'message' => 'Statut mis à jour.']);
    }

    public function modifierPrioriteTicket(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['priorite' => 'required|in:basse,normale,haute,urgente']);
        $ticket = TicketSupport::findOrFail($id);
        $avant = $ticket->priorite;
        $ticket->update(['priorite' => $validated['priorite']]);
        AuditLogger::log($request->user(), 'modifier_priorite_ticket', 'support', 'TicketSupport', $ticket->id, ['priorite' => $avant], ['priorite' => $validated['priorite']], $request);
        return response()->json(['success' => true, 'message' => 'Priorité mise à jour.']);
    }

    public function parametres(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>ParametrePlateforme::all()->keyBy('cle')]);
    }

    public function modifierParametre(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['valeur'=>'required|string']);
        $p = ParametrePlateforme::findOrFail($id);
        $valeurAvant = $p->valeur;
        $p->update(['valeur'=>$validated['valeur'],'admin_dernier_maj_id'=>$request->user()->administrateur?->id,'date_maj'=>now()]);
        DashboardCache::bump();
        AuditLogger::log($request->user(), 'modifier_parametre', 'parametres', 'ParametrePlateforme', $p->id, ['cle'=>$p->cle,'valeur'=>$valeurAvant], ['cle'=>$p->cle,'valeur'=>$validated['valeur']], $request);
        return response()->json(['success'=>true,'message'=>'Paramètre mis à jour.']);
    }

    public function clearCache(Request $request): JsonResponse
    {
        Cache::flush();
        DashboardCache::bump();
        return response()->json(['success'=>true,'message'=>'Cache effacé.']);
    }

    public function listRoles(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>RoleUser::select('role')->distinct()->pluck('role')]);
    }

    // Liste légère des comptes administrateur, utilisée pour les sélecteurs d'assignation
    // (tickets support, etc.) — le module complet de gestion des administrateurs viendra plus tard.
    public function listAdministrateurs(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => Administrateur::with('user')->orderBy('date_nomination', 'desc')->paginate((int) $request->get('per_page', 20))]);
    }

    // ----------------------------------------------------------------
    // ADMINISTRATION — COMPTES ADMINISTRATEUR (réservé super admin, cf. routes/api.php)
    // ----------------------------------------------------------------
    public function creerAdministrateur(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'telephone' => 'required|string|unique:users,telephone',
            'mot_de_passe' => 'required|string|min:8',
            'role_admin' => 'required|in:super_admin,support,finance,moderation',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string',
        ]);

        $user = User::create([
            'nom' => $validated['nom'],
            'prenom' => $validated['prenom'],
            'email' => $validated['email'],
            'telephone' => $validated['telephone'],
            'mot_de_passe_hash' => Hash::make($validated['mot_de_passe']),
            'type_utilisateur' => 'administrateur',
            'statut_compte' => 'actif',
            'consentement_cgu' => true,
        ]);

        $administrateur = Administrateur::create([
            'user_id' => $user->id,
            'role_admin' => $validated['role_admin'],
            'permissions' => $validated['permissions'] ?? [],
            'date_nomination' => now(),
        ]);

        AuditLogger::log($request->user(), 'creer_administrateur', 'administration', 'Administrateur', $administrateur->id, null, ['role_admin' => $validated['role_admin'], 'email' => $validated['email']], $request);

        return response()->json(['success' => true, 'data' => $administrateur->load('user')], 201);
    }

    public function modifierAdministrateur(Request $request, string $id): JsonResponse
    {
        $administrateur = Administrateur::with('user')->findOrFail($id);

        if ($administrateur->id === $request->user()->administrateur?->id) {
            return response()->json(['success' => false, 'message' => 'Vous ne pouvez pas modifier votre propre compte administrateur depuis cette page.'], 400);
        }

        $validated = $request->validate([
            'nom' => 'sometimes|string|max:100',
            'prenom' => 'sometimes|string|max:100',
            'email' => ['sometimes', 'email', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($administrateur->user_id)],
            'telephone' => ['sometimes', 'string', \Illuminate\Validation\Rule::unique('users', 'telephone')->ignore($administrateur->user_id)],
            'role_admin' => 'sometimes|in:super_admin,support,finance,moderation',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string',
        ]);

        $userFields = array_intersect_key($validated, array_flip(['nom', 'prenom', 'email', 'telephone']));
        if ($userFields) $administrateur->user->update($userFields);

        $adminFields = array_intersect_key($validated, array_flip(['role_admin', 'permissions']));
        if ($adminFields) {
            $avant = $administrateur->only(array_keys($adminFields));
            $administrateur->update($adminFields);
            AuditLogger::log($request->user(), 'modifier_administrateur', 'administration', 'Administrateur', $administrateur->id, $avant, $adminFields, $request);
        }

        return response()->json(['success' => true, 'data' => $administrateur->fresh('user')]);
    }

    public function suspendreAdministrateur(Request $request, string $id): JsonResponse
    {
        $administrateur = Administrateur::with('user')->findOrFail($id);
        if ($administrateur->id === $request->user()->administrateur?->id) {
            return response()->json(['success' => false, 'message' => 'Vous ne pouvez pas suspendre votre propre compte.'], 400);
        }
        $administrateur->user->update(['statut_compte' => 'suspendu']);
        $administrateur->user->tokens()->delete();
        AuditLogger::log($request->user(), 'suspendre_administrateur', 'administration', 'Administrateur', $administrateur->id, ['statut_compte' => 'actif'], ['statut_compte' => 'suspendu'], $request);
        return response()->json(['success' => true, 'message' => 'Administrateur suspendu.']);
    }

    public function activerAdministrateur(Request $request, string $id): JsonResponse
    {
        $administrateur = Administrateur::with('user')->findOrFail($id);
        $administrateur->user->update(['statut_compte' => 'actif']);
        AuditLogger::log($request->user(), 'activer_administrateur', 'administration', 'Administrateur', $administrateur->id, ['statut_compte' => 'suspendu'], ['statut_compte' => 'actif'], $request);
        return response()->json(['success' => true, 'message' => 'Administrateur activé.']);
    }

    // Catalogue des permissions granulaires + aperçu de ce que chaque rôle « système » accorde
    // aujourd'hui (PermissionHelper) — sert à pré-remplir le formulaire de permissions par rôle,
    // que l'on peut ensuite affiner librement avant enregistrement sur le compte administrateur.
    public function permissionsCatalogue(Request $request): JsonResponse
    {
        $permissions = DB::table('permissions')->orderBy('nom_permission')->pluck('nom_permission');
        $roles = ['super_admin', 'admin', 'support', 'finance', 'moderation', 'marketing'];
        $parRole = [];
        foreach ($roles as $role) {
            $parRole[$role] = PermissionHelper::getPermissionsByRole($role);
        }
        return response()->json(['success' => true, 'data' => ['permissions' => $permissions, 'roles' => $parRole]]);
    }

    public function assignRole(Request $request, string $id): JsonResponse
    {
        $role = $request->validate(['role'=>'required|string|max:50'])['role'];
        RoleUser::firstOrCreate(['user_id'=>$id,'role'=>$role]);
        AuditLogger::log($request->user(), 'assigner_role', 'roles', 'User', $id, null, ['role'=>$role], $request);
        return response()->json(['success'=>true,'message'=>'Rôle assigné.']);
    }

    public function removeRole(Request $request, string $id): JsonResponse
    {
        $role = $request->validate(['role'=>'required|string|max:50'])['role'];
        RoleUser::where('user_id',$id)->where('role',$role)->delete();
        AuditLogger::log($request->user(), 'retirer_role', 'roles', 'User', $id, ['role'=>$role], null, $request);
        return response()->json(['success'=>true,'message'=>'Rôle retiré.']);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $q = LogActivite::with('user')->orderBy('created_at', 'desc');
        if ($userId = $request->get('user_id')) $q->where('user_id', $userId);
        if ($action = $request->get('action')) $q->where('action', 'like', "%{$action}%");
        if ($module = $request->get('module')) $q->where('details->module', $module);
        if ($debut = $request->get('date_debut')) $q->whereDate('date_action', '>=', $debut);
        if ($fin = $request->get('date_fin')) $q->whereDate('date_action', '<=', $fin);
        return response()->json(['success'=>true,'data'=>$q->paginate((int) $request->get('per_page', 50))]);
    }
}