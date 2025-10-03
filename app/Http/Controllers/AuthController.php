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
                'login'    => 'required|string', // email ou user_name
                'password' => 'required|string',
            ]);

            $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'user_name';

            $user = User::leftJoin('usersenterprises as UE', 'users.id','=','UE.user_id')
                        ->where('users.' . $field, $data['login'])
                        ->where('users.status', 'enabled')
                        ->select('users.*','UE.enterprise_id')
                        ->first();

            if (!$user || !Hash::check($data['password'], $user->password)) {
                return $this->errorResponse('Les identifiants sont invalides.', 401);
            }

            // Ajouter l'entreprise active si nécessaire
            $actualEse = $this->getEse($user->id);
            if ($actualEse) {
                $user->enterprise_id = $actualEse['id'];
            }

            // Révoquer les anciens tokens
            $user->tokens()->delete();

            // Créer access token
            $accessToken = $user->createToken('api_token')->plainTextToken;
            if (!$accessToken) {
                throw new \Exception('Impossible de générer le token d’accès.');
            }

            // Créer refresh token
            $refreshTokenString = Str::random(64);
            $refreshToken = RefreshToken::create([
                'user_id'    => $user->id,
                'token'      => hash('sha256', $refreshTokenString),
                'expires_at' => Carbon::now()->addDays(7),
                'revoked'    => false,
            ]);

            if (!$refreshToken) {
                throw new \Exception('Impossible de générer le refresh token.');
            }

            // Récupérer roles et permissions
            $roles = $user->getRoleNames();
            $permissions = $user->getAllPermissions()->pluck('name');

            DB::commit(); // tout est ok, on valide

            return $this->successResponse('success', [
                'user'           => $user,
                'enterprise'     => $actualEse,
                'defaultmoney'   => $this->defaultmoney($actualEse['id'] ?? null),
                'access_token'   => $accessToken,
                'expires_in'     => 900,
                'refresh_token'  => $refreshTokenString,
                'roles'          => $roles,
                'permissions'    => $permissions,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack(); // annule tout en cas d'erreur
            return $this->errorResponse('error',$e->getMessage(),500);
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
