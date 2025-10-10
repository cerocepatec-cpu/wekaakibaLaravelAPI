<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\RefreshToken;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
class AuthController extends Controller
{
    public function updateSensitiveInfo(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();
        if (!$user) {
            return $this->errorResponse('Utilisateur non authentifié', 401);
        }

        $data = $request->all();

        // === VALIDATION DE BASE ===
        $validator = Validator::make($data, [
            'email' => 'sometimes|required|email',
            'user_phone' => 'sometimes|required|string',
            'old_pin' => 'sometimes|string',
            'new_pin' => 'sometimes|string|min:4|max:6',
            'confirm_pin' => 'sometimes|string',
            'old_password' => 'sometimes|string',
            'new_password' => 'sometimes|string|min:6',
            'confirm_password' => 'sometimes|string',
            'full_name' => 'sometimes|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        /**
         * ======================
         * EMAIL
         * ======================
         */
        if (!empty($data['email'])) {
            $existing = User::where('email', $data['email'])
                            ->where('id', '!=', $user->id)
                            ->first();
            if ($existing) {
                return $this->errorResponse('Cet email appartient déjà à un autre utilisateur', 422);
            }

            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return $this->errorResponse('Email invalide', 422);
            }

            $user->email = $data['email'];
        }

        /**
         * ======================
         * TÉLÉPHONE
         * ======================
         */
        if (!empty($data['user_phone'])) {
            $existingPhone = User::where('user_phone', $data['user_phone'])
                                ->where('id', '!=', $user->id)
                                ->first();
            if ($existingPhone) {
                return $this->errorResponse('Ce numéro de téléphone est déjà utilisé', 422);
            }

            if (!preg_match('/^\+[1-9]\d{1,14}$/', $data['user_phone'])) {
                return $this->errorResponse('Numéro de téléphone invalide (format attendu : +243...)', 422);
            }

            $user->user_phone = $data['user_phone'];
        }

        /**
         * ======================
         * PIN
         * ======================
         */
        if (!empty($data['old_pin']) || !empty($data['new_pin']) || !empty($data['confirm_pin'])) {
            if (empty($data['old_pin']) || empty($data['new_pin']) || empty($data['confirm_pin'])) {
                return $this->errorResponse('Veuillez renseigner tous les champs du PIN', 422);
            }

            if ($data['new_pin'] !== $data['confirm_pin']) {
                return $this->errorResponse('Les nouveaux PIN ne correspondent pas', 422);
            }

            if ($user->pin !== $data['old_pin']) {
                return $this->errorResponse('Ancien PIN incorrect', 422);
            }

            $weakPins = ['0000','1234','1111','9999',''];
            if (in_array($data['new_pin'], $weakPins)) {
                return $this->errorResponse('Le nouveau PIN est trop simple', 422);
            }

            $existingPin = User::where('pin', $data['new_pin'])
                            ->where('id', '!=', $user->id)
                            ->first();
            if ($existingPin) {
                return $this->errorResponse('Ce PIN est déjà utilisé par un autre utilisateur', 422);
            }

            $user->pin = $data['new_pin'];
        }

        /**
         * ======================
         * MOT DE PASSE
         * ======================
         */
        if (!empty($data['old_password']) || !empty($data['new_password']) || !empty($data['confirm_password'])) {
            if (empty($data['old_password']) || empty($data['new_password']) || empty($data['confirm_password'])) {
                return $this->errorResponse('Veuillez renseigner tous les champs du mot de passe', 422);
            }

            if (!Hash::check($data['old_password'], $user->password)) {
                return $this->errorResponse('Ancien mot de passe incorrect', 422);
            }

            if ($data['new_password'] !== $data['confirm_password']) {
                return $this->errorResponse('Les mots de passe ne correspondent pas', 422);
            }

            // Vérifie que le nouveau mot de passe n’est pas déjà utilisé
            $usersToCheck = User::where('id', '!=', $user->id)
                                ->where('status', 'active')
                                ->select('id','password')
                                ->get();

            foreach ($usersToCheck as $u) {
                if (Hash::check($data['new_password'], $u->password)) {
                    return $this->errorResponse('Ce mot de passe est déjà utilisé par un autre utilisateur', 422);
                }
            }

            $user->password = Hash::make($data['new_password']);
        }

        /**
         * ======================
         * AUTRES CHAMPS FILLABLES
         * ======================
         */
        $fillable = $user->getFillable();
        foreach ($fillable as $field) {
            if (in_array($field, ['email','user_phone','pin','password'])) continue;
            if (isset($data[$field])) {
                $user->$field = $data[$field];
            }
        }

        $user->save();

        return $this->successResponse('Informations sensibles mises à jour avec succès ✅', $user);
    }

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
            $tokenExpiration = now()->addMinutes(60);
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
                'expires_in'      => 3600,                   // 10 minutes en secondes
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
        $tokenExpiration = now()->addMinutes(60);
        $token = $user->createToken('api_token', ['*']);
        $plainTextToken = $token->plainTextToken;

        // Mettre à jour expires_at
        $token->accessToken->update([
            'expires_at' => $tokenExpiration,
        ]);

        return $this->successResponse('success', [
            'user'          => $user,
            'access_token'  => $plainTextToken,
            'expires_in'    =>3600 // 10 minutes
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
