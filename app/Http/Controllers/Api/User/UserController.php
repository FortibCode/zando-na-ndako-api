<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\JetonAppareil;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['client', 'vendeur', 'livreur', 'administrateur', 'roles']);
        return response()->json(['success'=>true,'data'=>$this->userPayload($user)]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'nom'=>'sometimes|string|max:100','prenom'=>'sometimes|string|max:100','date_naissance'=>'sometimes|date','sexe'=>'sometimes|in:homme,femme',
            'ville'=>'sometimes|string|max:100','adresse'=>'sometimes|string|max:500','email'=>'sometimes|email|unique:users,email,'.$user->id,
            'langue_preferee'=>'sometimes|in:francais,lingala,anglais','devise_preferee'=>'sometimes|in:FCFA,USD,EUR,GBP','pays_residence'=>'sometimes|string|max:100',
        ]);
        $user->update($validated);
        // Même forme que me() : $user->fresh() seul omettrait nom_complet (accesseur non listé dans
        // $appends sur le modèle) — le client stockait alors un profil sans nom_complet, provoquant
        // un crash (Cannot read properties of undefined (reading 'split')) à la prochaine lecture de
        // ce champ ailleurs dans l'app.
        return response()->json(['success'=>true,'message'=>'Profil mis à jour.','data'=>$this->userPayload($user->fresh()->load(['client', 'vendeur', 'livreur', 'administrateur', 'roles']))]);
    }

    private function userPayload($user): array
    {
        return [
            'id'=>$user->id,'nom'=>$user->nom,'prenom'=>$user->prenom,'nom_complet'=>$user->nom_complet,
            'email'=>$user->email,'telephone'=>$user->telephone,'date_naissance'=>$user->date_naissance,'sexe'=>$user->sexe,
            'ville'=>$user->ville,'adresse'=>$user->adresse,'type_utilisateur'=>$user->type_utilisateur,
            'statut_compte'=>$user->statut_compte,'photo_profil'=>$user->photo_profil,'langue_preferee'=>$user->langue_preferee,
            'devise_preferee'=>$user->devise_preferee,'pays_residence'=>$user->pays_residence,
            'date_inscription'=>$user->date_inscription,'derniere_connexion'=>$user->derniere_connexion,
            'est_diaspora'=>$user->type_utilisateur === 'client' ? ($user->client?->est_diaspora ?? false) : null,
            'profil'=>$user->getProfileRelation(),
            'roles'=>$user->roles->pluck('role')->values(),
            'administrateur'=>$user->administrateur ? [
                'role_admin'=>$user->administrateur->role_admin,
                'permissions'=>$user->getPermissions(),
            ] : null,
        ];
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate(['ancien_mot_de_passe'=>'required|string','nouveau_mot_de_passe'=>'required|string|min:8|confirmed']);
        if (!Hash::check($validated['ancien_mot_de_passe'], $user->mot_de_passe_hash)) {
            return response()->json(['success'=>false,'message'=>'Ancien mot de passe incorrect.'],400);
        }
        $user->update(['mot_de_passe_hash'=>Hash::make($validated['nouveau_mot_de_passe'])]);
        $user->tokens()->delete();
        return response()->json(['success'=>true,'message'=>'Mot de passe changé. Veuillez vous reconnecter.']);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate(['photo'=>'required|image|mimes:jpeg,png,jpg,webp|max:2048']);
        $user = $request->user();
        if ($user->photo_profil) Storage::disk('public')->delete($user->photo_profil);
        $path = $request->file('photo')->store('photos/profils','public');
        $user->update(['photo_profil'=>$path]);
        return response()->json(['success'=>true,'message'=>'Photo mise à jour.','photo_url'=>Storage::url($path)]);
    }

    public function deletePhoto(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->photo_profil) { Storage::disk('public')->delete($user->photo_profil); $user->update(['photo_profil'=>null]); }
        return response()->json(['success'=>true,'message'=>'Photo supprimée.']);
    }

    public function delete(Request $request): JsonResponse
    {
        $request->validate(['mot_de_passe'=>'required|string']);
        $user = $request->user();
        if (!Hash::check($request->mot_de_passe, $user->mot_de_passe_hash)) return response()->json(['success'=>false,'message'=>'Mot de passe incorrect.'],400);
        $user->tokens()->delete(); $user->update(['statut_compte'=>'suspendu']);
        return response()->json(['success'=>true,'message'=>'Compte désactivé.']);
    }

    public function notifications(Request $request): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>$request->user()->notifications()->orderBy('created_at','desc')->paginate(20),'non_lues'=>$request->user()->notifications()->where('statut_lecture',false)->count()]);
    }

    public function marquerNotificationLue(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->findOrFail($id)->marquerCommeLue();
        return response()->json(['success'=>true,'message'=>'Notification marquée comme lue.']);
    }

    public function marquerToutesNotificationsLues(Request $request): JsonResponse
    {
        $request->user()->notifications()->update(['statut_lecture'=>true]);
        return response()->json(['success'=>true,'message'=>'Toutes lues.']);
    }

    // POST /api/user/push-token — enregistre (ou ré-attribue) le jeton Expo Push de cet
    // appareil pour l'utilisateur connecté. Accessible aux 3 types d'utilisateurs (client,
    // vendeur, livreur), aucun rôle particulier requis.
    public function enregistrerPushToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'      => 'required|string|max:255',
            'plateforme' => 'sometimes|in:ios,android,web',
        ]);

        JetonAppareil::updateOrCreate(
            ['token' => $validated['token']],
            ['user_id' => $request->user()->id, 'plateforme' => $validated['plateforme'] ?? 'inconnue']
        );

        return response()->json(['success' => true, 'message' => 'Jeton de notification enregistré.']);
    }
}