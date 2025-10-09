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
            // 1️⃣ Validation des champs
            $data = $request->validate([
                'login'    => 'required|string',
                'password' => 'required|string',
            ]);

            $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'user_name';

            // 2️⃣ Récupération de l'utilisateur avec entreprise
            $user = User::leftJoin('usersenterprises as UE', 'users.id', '=', 'UE.user_id')
                        ->where('users.' . $field, $data['login'])
                        ->where('users.status', 'enabled')
                        ->select('users.*', 'UE.enterprise_id')
                        ->first();

            if (!$user || !Hash::check($data['password'], $user->password)) {
                return $this->errorResponse('Les identifiants sont invalides.', 401);
            }

            // 3️⃣ Entreprise active
            $actualEse = $this->getEse($user->id);
            if ($actualEse) {
                $user->enterprise_id = $actualEse['id'];
            }

            // 4️⃣ Supprimer anciens tokens
            $user->tokens()->delete();

            // 5️⃣ Créer un token via Sanctum
            $tokenExpiration = now()->addMinutes(10);
            $token = $user->createToken('api_token', ['*']);
            $plainTextToken = $token->plainTextToken;

            // 6️⃣ Mettre à jour expires_at dans la table
            $token->accessToken->update([
                'expires_at' => $tokenExpiration,
            ]);

            // 7️⃣ Créer un refresh token (1 jour)
            $refreshTokenString = Str::random(64);
            $refreshToken = RefreshToken::create([
                'user_id'    => $user->id,
                'token'      => hash('sha256', $refreshTokenString),
                'expires_at' => now()->addDay(),
                'revoked'    => false,
            ]);

            // 8️⃣ Gestion super_admin (rôles et permissions)
            if ($user->user_type === 'super_admin') {
                if ($user->roles()->count() === 0) {
                    $enterpriseRoles = \Spatie\Permission\Models\Role::where('enterprise_id', $user->enterprise_id)->get();
                    if ($enterpriseRoles->isNotEmpty()) {
                        $user->syncRoles($enterpriseRoles);
                    }
                }

                if ($user->permissions()->count() === 0) {
                    $allPermissions = \Spatie\Permission\Models\Permission::all();
                    $user->syncPermissions($allPermissions);
                }
            }

            DB::commit();

            // 9️⃣ Retour API
            return $this->successResponse('success', [
                'user'            => $user,
                'enterprise'      => $actualEse,
                'defaultmoney'    => $this->defaultmoney($actualEse['id'] ?? null),
                'access_token'    => $plainTextToken,       // token à utiliser pour Authorization Bearer
                'expires_in'      => 600,                   // 10 minutes en secondes
                'refresh_token'   => $refreshTokenString,
                'refresh_expires_at' => $refreshToken->expires_at,
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

        if (!$tokenRecord) {
            return $this->errorResponse('Refresh token invalide ou expiré', 401);
        }

        $user =$tokenRecord->user;

        if ($user->status !== 'enabled') {
            return $this->errorResponse('Compte désactivé. Contactez l’administrateur.', 403);
        }

        // Vérifie mot de passe ou PIN
        $isValid = Hash::check($request->password, $user->password) 
                || (!empty($user->pin) && Hash::check($request->password, $user->pin));

        if (!$isValid) {
            $user->failed_attempts = ($user->failed_attempts ?? 0) + 1;

            if ($user->failed_attempts >= 4) {
                $user->status = 'disabled';
                $user->save();
                return $this->errorResponse('Compte désactivé après plusieurs tentatives échouées.', 403);
            }

            $user->save();
            return $this->errorResponse('Mot de passe ou PIN incorrect.', 401);
        }

        // Reset compteur d'échecs
        $user->failed_attempts = 0;
        $user->save();

        // Supprime anciens access tokens
        $user->tokens()->delete();

        // Crée un nouveau access token via Sanctum
        $tokenExpiration = now()->addMinutes(10);
        $token = $user->createToken('api_token', ['*']);
        $plainTextToken = $token->plainTextToken;

        // Mettre à jour expires_at
        $token->accessToken->update([
            'expires_at' => $tokenExpiration,
        ]);

        return $this->successResponse('success', [
            'user'          => $user,
            'access_token'  => $plainTextToken,
            'expires_in'    => 600 // 10 minutes
        ]);
    }

    // Logout (révocation du token courant)
   public function logout(Request $request)
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
            return $this->successResponse('success', null);
        }

        return $this->errorResponse('Utilisateur non authentifié ou token invalide.', 401);
    }

    // Récupérer profil
    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
