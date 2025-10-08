<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Models\RefreshToken;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    // Register
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed', // expects password_confirmation
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']), // <-- hash ici
        ]);

        // créer token API
        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'message' => 'Utilisateur créé',
            'user'    => $user,
            'token'   => $token,
        ], 201);
    }

    // Login
   public function login(Request $request)
    {
        DB::beginTransaction();

        try {
            $data = $request->validate([
                'login'    => 'required|string',
                'password' => 'required|string',
            ]);

            $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'user_name';

            $user = User::leftJoin('usersenterprises as UE', 'users.id', '=', 'UE.user_id')
                        ->where('users.' . $field, $data['login'])
                        ->where('users.status', 'enabled')
                        ->select('users.*', 'UE.enterprise_id')
                        ->first();

            if (!$user || !Hash::check($data['password'], $user->password)) {
                return $this->errorResponse('Les identifiants sont invalides.', 401);
            }

            // Entreprise active
            $actualEse = $this->getEse($user->id);
            if ($actualEse) {
                $user->enterprise_id = $actualEse['id'];
            }

            // Supprimer anciens tokens
            $user->tokens()->delete();

            // ✅ Créer access token (en min)
            $tokenExpiration = Carbon::now()->addMinutes(30);

            // Génération du token en clair
            $plainTextToken = Str::random(80);

            // Création du token via la relation tokens()
            $token = $user->tokens()->create([
                'name'        => 'api_token',                  // Nom du token
                'token'       => hash('sha256', $plainTextToken), // Hash SHA256, 64 chars
                'abilities'   => json_encode(['*']),           // JSON ou texte
                'last_used_at'=> null,                         // au départ null
                'expires_at'  => $tokenExpiration,            // expiration du token
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // Access token à renvoyer au frontend
            $accessToken = $user->getKey() . '|' . $plainTextToken;

            // ✅ Créer refresh token (1 jour)
            $refreshTokenString = Str::random(64);
            $refreshToken=RefreshToken::create([
                'user_id'    => $user->id,
                'token'      => hash('sha256', $refreshTokenString),
                'expires_at' => Carbon::now()->addDay(),
                'revoked'    => false,
            ]);

            // Gestion super_admin
            if ($user->user_type === 'super_admin') {

                $rolesCount = $user->roles()->count();
                $permissionsCount = $user->permissions()->count();

                // ✅ Si l'utilisateur n'a aucun rôle, on lui attribue tous les rôles de son entreprise
                if ($rolesCount === 0) {
                    $enterpriseRoles = \Spatie\Permission\Models\Role::where('enterprise_id', $user->enterprise_id)->get();

                    if ($enterpriseRoles->isNotEmpty()) {
                        $user->syncRoles($enterpriseRoles);
                    }
                }

                // ✅ Si l'utilisateur n'a aucune permission, on lui attribue toutes les permissions existantes
                if ($permissionsCount === 0) {
                    $allPermissions = \Spatie\Permission\Models\Permission::all();
                    $user->syncPermissions($allPermissions);
                }
            }

           $user->roles->map(fn($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'title' => $r->title,
                'description' => $r->description,
            ]);

            $user->getAllPermissions()->map(fn($p) => ['id' => $p->id, 'name' => $p->name]);

            DB::commit();

            return $this->successResponse('success', [
                'user'           => $user,
                'enterprise'     => $actualEse,
                'defaultmoney'   => $this->defaultmoney($actualEse['id'] ?? null),
                'access_token'   => $accessToken,
                'expires_in'     => 1800,
                'refresh_token'  => $refreshTokenString,
                'refresh_expires_at'=>$refreshToken->expires_at
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->errorResponse('error', $e->getMessage(), 500);
        }
    }

    public function refresh(Request $request)
    {
        $request->validate([
            'refresh_token' => 'required|string',
            'password'      => 'required|string',
        ]);

        $hashed = hash('sha256', $request->refresh_token);

        $tokenRecord = RefreshToken::where('token', $hashed)
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->first();

        if (! $tokenRecord) {
            return $this->errorResponse('Refresh token invalide ou expiré',401);
        }

        $user = $tokenRecord->user;

        // Vérifie si le compte est actif
        if ($user->status !== 'enabled') {
            return $this->errorResponse('Compte désactivé. Contactez l’administrateur.',403);
        }

        // Vérifie le mot de passe ou le PIN
        $isValid = Hash::check($request->password, $user->password)|| (!empty($user->pin) && Hash::check($request->password, $user->pin));

        if (! $isValid) {
            // Incrémente les tentatives échouées
            $user->failed_attempts = ($user->failed_attempts ?? 0) + 1;

            // Désactive le compte après 4 échecs
            if ($user->failed_attempts >= 4) {
                $user->status = 'disabled';
                $user->save();
                return $this->errorResponse('Compte désactivé après plusieurs tentatives échouées.',403);
            }

            $user->save();
            return $this->errorResponse('Mot de passe ou PIN incorrect.',401);
        }

        // Succès : réinitialise le compteur d’échecs
        $user->failed_attempts = 0;
        $user->save();

        // Supprime les anciens access tokens
        $user->tokens()->delete();

        // Crée un nouveau access token
        $plainTextToken = Str::random(80);
        $tokenExpiration = now()->addMinutes(2);

        $user->tokens()->create([
            'name'         => 'api_token',
            'token'        => hash('sha256', $plainTextToken),
            'abilities'    => json_encode(['*']),
            'last_used_at' => null,
            'expires_at'   => $tokenExpiration,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $newAccessToken = $user->createToken('api_token')->plainTextToken;

        // Révoque le refresh token utilisé
        $tokenRecord->revoked = true;
        $tokenRecord->save();

        return $this->successResponse('success',[
            'user'=>$user,
            'access_token' => $newAccessToken,
            'expires_in'   =>60, // secondes (2 minutes)
        ]);
    }


    // Logout (révocation du token courant)
    public function logout(Request $request)
    {
        // révoquer le token qui a fait la requête
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnexion réussie']);
    }

    // Récupérer profil
    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
