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
          $tokenExpiration = Carbon::now()->addMinutes(2);

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
                if ($rolesCount === 0 && $permissionsCount === 0) {
                    $allPermissions = \Spatie\Permission\Models\Permission::all();
                    $user->syncPermissions($allPermissions);
                }
            }

            $roles = $user->getRoleNames();
            $permissions = $user->getAllPermissions()->map(fn($p) => ['id' => $p->id, 'name' => $p->name]);

            DB::commit();

            return $this->successResponse('success', [
                'user'           => $user,
                'enterprise'     => $actualEse,
                'defaultmoney'   => $this->defaultmoney($actualEse['id'] ?? null),
                'access_token'   => $accessToken,
                'expires_in'     => 120, // 15 min
                'refresh_token'  => $refreshTokenString,
                'refresh_expires_at'=>$refreshToken->expires_at,
                'roles'          => $roles,
                'permissions'    => $permissions,
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
        ]);

        $hashed = hash('sha256', $request->refresh_token);

        $tokenRecord = RefreshToken::where('token', $hashed)
            ->where('revoked', false)         // maintenant on peut vérifier si le token a été révoqué
            ->where('expires_at', '>', now())
            ->first();

        if (! $tokenRecord) {
            return response()->json(['message' => 'Refresh token invalide ou expiré'], 401);
        }

        $user = $tokenRecord->user;

        // Supprimer tous les anciens access tokens
        $user->tokens()->delete();

        // Créer un nouveau access token
        $newAccessToken = $user->createToken('api_token')->plainTextToken;

        // Révoquer le refresh token utilisé
        $tokenRecord->revoked = true;
        $tokenRecord->save();

        return response()->json([
            'access_token' => $newAccessToken,
            'expires_in'   => 900, // 15 min
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
