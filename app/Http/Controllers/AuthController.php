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
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    // Étape 1 : demande de reset
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'type' => 'required|in:email,phone',
            'value' => 'required',
        ]);

        $user = $request->type === 'email'
            ? User::where('email', $request->value)->first()
            : User::where('user_phone', $request->value)->first();

        if (!$user) {
            return response()->json(['message' => 'Utilisateur introuvable'], 404);
        }

        // Génération du token sécurisé Laravel
        $token = Password::broker()->createToken($user);

        // Génération d’un OTP 6 chiffres pour SMS/email
        $code = rand(100000, 999999);

        // Stockage dans la table standard password_resets
        DB::table('password_resets')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => $token,
                'code' => $code,
                'created_at' => now(),
            ]
        );

        // Lire le token en DB pour cet email
        $tokenInDb = DB::table('password_resets')->where('email', $user->email)->value('token');
        Log::info('Token généré Laravel: ' . $token);
        Log::info('Token stocké en DB: ' . $tokenInDb);

        if ($request->type === 'email') {
            // Envoi par email
            Mail::raw("Votre code de réinitialisation est : $code", function ($message) use ($user) {
                $message->to($user->email)
                        ->subject('Réinitialisation du mot de passe');
            });
        } else {
            // Envoi par SMS via ton service (Twilio, Nexmo...)
            // SmsService::send($user->user_phone, "Code de réinitialisation : $code");
        }

        return response()->json(['message' => 'Code de réinitialisation envoyé.']);
    }

    // Étape 2 : vérification du code OTP
    public function verifyResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required',
        ]);

        $reset = DB::table('password_resets')
            ->where('email', $request->email)
            ->where('code', $request->code)
            ->first();

        if (!$reset || Carbon::parse($reset->created_at)->addMinutes(15)->isPast()) {
            return response()->json(['message' => 'Code invalide ou expiré.'], 400);
        }

        return response()->json(['message' => 'Code vérifié avec succès.', 'token' => $reset->token]);
    }

    // Étape 3 : réinitialisation du mot de passe
public function resetPassword(Request $request)
{
    $request->validate([
        'email' => 'required|email',
        'token' => 'required',
        'password' => 'required|min:6|confirmed'
    ]);

    // 1️⃣ Récupérer la ligne correspondant à l’email
    $reset = DB::table('password_resets')
        ->where('email', $request->email)
        ->where('token', $request->token)
        ->first();

    if (!$reset) {
        Log::warning('Token invalide ou introuvable pour email: ' . $request->email);
        return response()->json([
            'message' => 'Token invalide ou expiré.',
            'status_code' => 'invalid_token'
        ], 400);
    }

    // 2️⃣ Vérifier expiration (60 minutes par défaut)
    $expiresAt = \Carbon\Carbon::parse($reset->created_at)->addMinutes(60);
    if (\Carbon\Carbon::now()->gt($expiresAt)) {
        Log::warning('Token expiré pour email: ' . $request->email);
        return response()->json([
            'message' => 'Token expiré.',
            'status_code' => 'expired_token'
        ], 400);
    }

    // 3️⃣ Récupérer l’utilisateur et mettre à jour le mot de passe
    $user = \App\Models\User::where('email', $request->email)->first();
    if (!$user) {
        return response()->json([
            'message' => 'Utilisateur introuvable.',
            'status_code' => 'user_not_found'
        ], 404);
    }

    $user->password = Hash::make($request->password);
    $user->save();

    // 4️⃣ Supprimer le token pour éviter réutilisation
    DB::table('password_resets')->where('email', $request->email)->delete();

    Log::info('Mot de passe réinitialisé avec succès pour email: ' . $request->email);

    return response()->json([
        'message' => 'Mot de passe réinitialisé avec succès.'
    ]);
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
