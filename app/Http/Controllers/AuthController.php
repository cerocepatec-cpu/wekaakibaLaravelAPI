<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;
use App\Models\RefreshToken;
use Illuminate\Http\Request;
use App\Models\PasswordReset;
use App\Models\TwoFactorRequest;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\SendSecurityLoginAlert;
use App\Services\UserSessionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\Mail\PasswordResetSuccessMail;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;
use App\Mail\PasswordChangedSecurityAlert;


class AuthController extends Controller
{
    // Configurable
    const OTP_TTL_MINUTES = 5;
    const OTP_MAX_ATTEMPTS = 5;
    const OTP_RESEND_COOLDOWN = 60;
    protected int $maxAttempts = 5;
    protected int $lockoutSeconds = 15 * 60; // 15 minutes

     /**
     * 1) Demande d’activation 2FA → envoi OTP par EMAIL
     */
   public function request2FA()
    {
        $user = auth()->user();

        if (!$user->email || !$user->email_verified_at) {
            return $this->errorResponse('Email non valide ou non vérifié.',422);
        }

        $cacheKey = "2fa_enable_{$user->id}";
        $existing = Cache::get($cacheKey);

        // ⛔ Anti spam (cooldown)
        if ($existing && now()->timestamp < ($existing['resend_available_at'] ?? 0)) {
            return $this->errorResponse('Veuillez patienter avant de renvoyer le code.',429);
        }

        $otp = random_int(100000, 999999);

        Cache::put($cacheKey, [
            'otp' => (string) $otp,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES)->timestamp,
            'resend_available_at' => now()->addSeconds(self::OTP_RESEND_COOLDOWN)->timestamp,
        ], now()->addMinutes(self::OTP_TTL_MINUTES));

        Mail::raw(
            "Code de confirmation WEKA AKIBA : {$otp}\n\nExpire dans 5 minutes.",
            fn ($m) => $m->to($user->email)->subject('Code 2FA – WEKA AKIBA')
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Code envoyé par email.',
            'expires_in' => self::OTP_TTL_MINUTES * 60
        ]);
    }


    /**
     * 2) Confirmation OTP → activation réelle du 2FA (EMAIL)
     */
    public function confirm2FA(Request $request)
    {
         /** @var User $user */
        $user =Auth::user();

        $data = $request->validate([
            'otp' => 'required|string'
        ]);

        $cacheKey = "2fa_enable_{$user->id}";
        $cached = Cache::get($cacheKey);

        if (!$cached) {
            return $this->errorResponse('Code expiré. Veuillez en demander un nouveau.',422);
        }

        // ⏱️ Expiration
        if (now()->timestamp > $cached['expires_at']) {
            Cache::forget($cacheKey);
            return $this->errorResponse('Code expiré OTP.',422);
        }

        // 🧪 Tentatives max
        if ($cached['attempts'] >= self::OTP_MAX_ATTEMPTS) {
            Cache::forget($cacheKey);
            return $this->errorResponse('Trop de tentatives. Activation bloquée.',429);
        }

        // ❌ Mauvais OTP
        if ($cached['otp'] !== $data['otp']) {
            $cached['attempts']++;
            Cache::put($cacheKey, $cached, now()->addMinutes(self::OTP_TTL_MINUTES));
            return $this->errorResponse('Code incorrect.',422);
        }

        // ✅ Succès → activer 2FA
        $user->update([
            'two_factor_enabled' => true,
            'two_factor_channel' => 'email'
        ]);

        Cache::forget($cacheKey);

        return $this->successResponse('success');
    }


    /**
     * 3) Désactivation simple du 2FA
     */
    public function disable2FA()
    {
         /** @var User $user */
        $user = Auth::user();

        $user->update([
            'two_factor_enabled' => false,
            'two_factor_channel' => null
        ]);

        Cache::forget("2fa_enable_{$user->id}");

        return $this->successResponse('success',[]);
    }

    public function verifyPin(Request $request)
    {
        $request->validate([
            'pin' => 'required|string|size:4',
        ]);

        $user = $request->user();
        if (!$user) {
            return $this->errorResponse('Utilisateur non authentifié', 401);
        }

        // Vérification si compte déjà bloqué
        if ($user->status === 'disabled') {
            return $this->errorResponse('Compte désactivé. Veuillez contacter l’administrateur.', 403);
        }

        // Vérification du PIN (hashé)
        if (Hash::check($request->pin, $user->pin)) {
            // PIN correct → reset failed_attempts
            $user->failed_attempts = 0;
            $user->pin_locked_until = null;
            $user->save();

            return $this->successResponse('success', $user);
        }

        // PIN incorrect → incrémente failed_attempts
        $user->failed_attempts++;

        if ($user->failed_attempts >= $this->maxAttempts) {
            // Bloquer le compte
            $user->status = 'disabled';
            $user->pin_locked_until = now()->addSeconds($this->lockoutSeconds); // optionnel
        }

        $user->save();

        // Message pour le front
        $remaining = max(0, $this->maxAttempts - $user->failed_attempts);
        $message = $user->status === 'disabled'
            ? "PIN incorrect. Compte temporairement désactivé pour {$this->lockoutSeconds} secondes."
            : "PIN incorrect. Il vous reste {$remaining} tentative(s).";

        return $this->errorResponse($message, 403);
    }

    protected function getCacheKey($userId): string
    {
        return "pin_attempts_user_{$userId}";
    }

    public function resetPin(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_pin' => 'required|string|size:4',
        ]);

        // Récupère l'utilisateur automatiquement via token
        $user = $request->user(); // avec sanctum ou auth middleware

        // Vérifie que le mot de passe fourni correspond
        if (!Hash::check($request->current_password, $user->password)) {
            return $this->errorResponse('Mot de passe incorrect', 422);
        }

        // Vérifie la complexité du PIN
        $weakPins = ['0000', '1234', '1111', '9999', '2222', '3333', '4444', '5555', '6666', '7777', '8888'];
        if (in_array($request->new_pin, $weakPins)) {
            return $this->errorResponse('Le nouveau PIN est trop simple', 422);
        }

        // Met à jour le PIN
        $user->pin = Hash::make($request->new_pin);
        $user->save();

        // ✅ Utilisation de successResponse pour la réponse
        return $this->successResponse('success', $user);
    }

    // Étape 1 : demande de reset
    public function forgotPassword(Request $request)
    {
        // Vérifie que le type est fourni
        if (!$request->has('type') || empty($request->type)) {
            return $this->errorResponse('Le type de récupération (email ou phone) est requis', 422);
        }

        // Validation de base
        $request->validate([
            'type' => 'in:email,phone',
            'value' => 'required',
        ]);

        // Vérifie la validité du format selon le type
        if ($request->type === 'email' && !filter_var($request->value, FILTER_VALIDATE_EMAIL)) {
            return $this->errorResponse('Adresse email invalide', 422);
        }

        if ($request->type === 'phone' && !preg_match('/^\+?[0-9]{8,15}$/', $request->value)) {
            return $this->errorResponse('Numéro de téléphone invalide', 422);
        }

        // Recherche de l’utilisateur selon le type
        $user = $request->type === 'email'
            ? User::where('email', $request->value)->first()
            : User::where('user_phone', $request->value)->first();

        if (!$user) {
            return $this->errorResponse('Utilisateur introuvable', 404);
        }

        // Génération du token sécurisé Laravel
        $passwordReset = PasswordReset::generateOTP($user->email);

        // Envoi selon le type choisi
        if ($request->type === 'email') {
            try {
                Mail::raw("Votre code de réinitialisation est : $passwordReset->code", function ($message) use ($user) {
                    $message->to($user->email)
                        ->subject('🔐 Réinitialisation du mot de passe');
                });
            } catch (\Exception $e) {
                return $this->errorResponse('Erreur lors de l’envoi de l’email. Veuillez réessayer plus tard.', 500);
            }
        } else {
            // Envoi SMS (si un service SMS est connecté)
            // SmsService::send($user->user_phone, "Code de réinitialisation : $code");
        }

        return $this->successResponse('Code de réinitialisation envoyé avec succès', [
            'type' => $request->type,
            'value' => $request->value,
        ]);
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
            return $this->errorResponse('Code invalide ou expiré', 400);
        }

        return $this->successResponse('success', ['token' => $reset->token]);
    }

    // Étape 3 : réinitialisation du mot de passe
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'nullable|email',
            'user_phone' => 'nullable|string',
            'token' => 'required',
            'password' => 'required|min:6|confirmed'
        ]);

        DB::beginTransaction();

        try {
            // 🔹 1️⃣ Identifier la méthode utilisée
            $isEmailReset = !empty($request->email);
            $isPhoneReset = !empty($request->user_phone);

            if (!$isEmailReset && !$isPhoneReset) {
                return $this->errorResponse("Veuillez fournir un email ou un numéro de téléphone.", 400);
            }

            // 🔹 2️⃣ Vérifier le token dans password_resets
            $resetQuery = DB::table('password_resets')
                ->where('token', $request->token);

            if ($isEmailReset) {
                $resetQuery->where('email', $request->email);
            } else {
                $resetQuery->where('user_phone', $request->user_phone);
            }

            $reset = $resetQuery->first();

            if (!$reset) {
                DB::rollBack();
                return $this->errorResponse("Token invalide ou expiré.", 400);
            }

            // 🔹 3️⃣ Vérifier expiration (60 minutes)
            $expiresAt = \Carbon\Carbon::parse($reset->created_at)->addMinutes(60);
            if (\Carbon\Carbon::now()->gt($expiresAt)) {
                DB::rollBack();
                return $this->errorResponse("Token expiré.", 400);
            }

            // 🔹 4️⃣ Récupérer l’utilisateur
            $userQuery = \App\Models\User::query();
            if ($isEmailReset) {
                $userQuery->where('email', $request->email);
            } else {
                $userQuery->where('user_phone', $request->user_phone);
            }

            $user = $userQuery->first();

            if (!$user) {
                DB::rollBack();
                return $this->errorResponse("Utilisateur introuvable.", 404);
            }

            // 🔹 5️⃣ Mettre à jour le mot de passe
            $user->password = Hash::make($request->password);
            $user->save();

            // 🔹 6️⃣ Supprimer le token utilisé
            DB::table('password_resets')
                ->where($isEmailReset ? 'email' : 'user_phone', $isEmailReset ? $request->email : $request->user_phone)
                ->delete();


            // 🔹 7️⃣ Notification selon le mode de réinitialisation
            if ($isEmailReset && $user->email) {
                Mail::to($user->email)->send(new PasswordResetSuccessMail($user));
            } elseif ($isPhoneReset && $user->user_phone) {
                $smsText = "Bonjour, votre mot de passe a été réinitialisé avec succès. Si ce n'est pas vous, contactez le support immédiatement.";
                Log::info("SMS à {$user->user_phone}: {$smsText}");

                // Exemple Twilio :
                // Twilio::messages()->create($user->user_phone, [
                //     'from' => env('TWILIO_NUMBER'),
                //     'body' => $smsText
                // ]);
            }

            DB::commit();
            return $this->successResponse("Mot de passe réinitialisé avec succès.", null);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la réinitialisation du mot de passe: ' . $e->getMessage());
            return $this->errorResponse("Échec de la réinitialisation du mot de passe. " . $e->getMessage(), 500);
        }
    }

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
            'name' => 'sometimes|string|max:255',
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
            // Vérifie que tous les champs PIN sont remplis
            if (empty($data['old_pin']) || empty($data['new_pin']) || empty($data['confirm_pin'])) {
                return $this->errorResponse('Veuillez renseigner tous les champs du PIN', 422);
            }

            // Vérifie la correspondance des nouveaux PIN
            if ($data['new_pin'] !== $data['confirm_pin']) {
                return $this->errorResponse('Les nouveaux PIN ne correspondent pas', 422);
            }

            // Vérifie que l'ancien PIN est correct (via Hash)
            if (!Hash::check($data['old_pin'], $user->pin)) {
                return $this->errorResponse('Ancien PIN incorrect', 422);
            }

            // Vérifie la longueur du PIN
            if (strlen($data['new_pin']) !== 4) {
                return $this->errorResponse('Le PIN doit comporter exactement 4 chiffres', 422);
            }

            // Vérifie la complexité / PIN faibles
            $weakPins = ['0000', '1234', '1111', '9999', '2222', '3333', '4444', '5555', '6666', '7777', '8888'];
            if (in_array($data['new_pin'], $weakPins)) {
                return $this->errorResponse('Le nouveau PIN est trop simple', 422);
            }

            // Stocke le nouveau PIN hashé
            $user->pin = Hash::make($data['new_pin']);
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
                ->select('id', 'password')
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
            if (in_array($field, ['email', 'user_phone', 'pin', 'password'])) continue;
            if (isset($data[$field])) {
                $user->$field = $data[$field];
            }
        }

        if (!$user->uuid && $user->created_at) {
            $random = substr(uniqid(), -3);
            $user->uuid = 'GOM' . $user->created_at->format('YdmHis') . strtoupper($random);
        }

        $user->save();

        return $this->successResponse('success', $user);
    }

    public function updateSensitiveInfoPassword(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user) {
            return $this->errorResponse('Utilisateur non authentifié', 401);
        }

        // === COMPTE BLOQUÉ ?
        if ($user->failed_attempts >= 3) {
            return $this->errorResponse(
                "Votre compte est temporairement bloqué après plusieurs tentatives échouées.",
                423
            );
        }

        // === VALIDATION STRICTE ===
        $validator = Validator::make($request->all(), [
            'old_password'     => 'required|string',
            'new_password'     => 'required|string|min:8',
            'confirm_password' => 'required|string|same:new_password',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        // === VÉRIFICATION DE L'ANCIEN MOT DE PASSE
        if (!Hash::check($request->old_password, $user->password)) {

            $user->failed_attempts++;
            $user->save();

            if ($user->failed_attempts >= 3) {
                $user->status = 'blocked';
                $user->save();
                return $this->errorResponse("Compte bloqué après 3 tentatives échouées.", 423);
            }

            return $this->errorResponse("Ancien mot de passe incorrect", 422);
        }

        // reset compteur après succès
        $user->failed_attempts = 0;

        // === PASSWORD STRENGTH CHECKER
        $new = $request->new_password;

        if (
            !preg_match('/[A-Z]/', $new) ||
            !preg_match('/[a-z]/', $new) ||
            !preg_match('/[0-9]/', $new) ||
            !preg_match('/[\W]/', $new)
        ) {
            return $this->errorResponse(
                "Le mot de passe doit contenir au moins : une majuscule, une minuscule, un chiffre et un symbole.",
                422
            );
        }

        // === MISE À JOUR MDP ===
        $user->password = Hash::make($new);

        // === Génération UUID si manquant
        if (!$user->uuid && $user->created_at) {
            $random = substr(uniqid(), -3);
            $user->uuid = 'GOM' . $user->created_at->format('YdmHis') . strtoupper($random);
        }

        $user->save();

        // --- INFOS SÉCURITÉ ---
        $ip = $request->ip();
        $userAgent = $request->header('User-Agent');

        $device  = $this->detectDevice($userAgent);
        $os      = $this->detectOS($userAgent);
        $browser = $this->detectBrowser($userAgent);

        // --- ENVOI EMAIL ASYNC APRÈS LA RÉPONSE ---
        if (!empty($user->email)) {
            Mail::to($user->email)->queue(
                new PasswordChangedSecurityAlert(
                    $user,
                    $ip,
                    $device,
                    $os,
                    $browser
                )
            );
        }

        return $this->successResponse("success", $user);
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
            'password' => Hash::make($data['password']),
            'pin' => Hash::make($data['pin'])
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
        $data = $request->validate([
            'login'       => 'required|string',
            'password'    => 'required|string',
            'device_type' => 'required|in:web,mobile',
        ]);

        DB::beginTransaction();

        try {

            // 🔍 USER
            $login = trim($data['login']);

            $user = User::leftJoin('usersenterprises as UE', 'users.id', '=', 'UE.user_id')
                ->where('users.status', 'enabled')
                ->where(function ($q) use ($login) {
                    if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
                        $q->where('users.email', $login);
                    } else {
                        $q->where('users.uuid', $login)
                          ->orWhere('users.user_name', $login);
                    }
                })
                ->select('users.*', 'UE.enterprise_id')
                ->first();

            if (!$user || !Hash::check($data['password'], $user->password)) {
                return $this->errorResponse('Les identifiants sont invalides.', 401);
            }

            // 🧠 VÉRIFICATION SESSION VIVANTE (TTL LOGIQUE)
            $deviceType = $data['device_type'];

            if (
                UserSessionService::hasActiveSession(
                    $user->id,
                    $deviceType
                )
            ) {
                UserSession::where('user_id', $user->id)
                ->where('device_type', $deviceType)
                ->where('status', 'active')
                ->where('last_seen_at', '<', now()->subMinutes(2))
                ->update([
                    'status' => 'expired',
                ]);

                // ⚠️ Une session ACTIVE et VIVANTE existe réellement

                $pendingId = $this->createPendingLogin(
                    $user->id,
                    $deviceType,
                    $request
                );

                // 📢 notifier ancien appareil
                Redis::publish('user.authentifications', json_encode([
                    'type' => 'new-login',
                    'data' => [
                        'userId'      => $user->id,
                        'pending_id'  => $pendingId,
                        'device_type'=> $deviceType,
                        'ip'          => $request->ip(),
                        'user_agent'  => $request->userAgent(),
                    ]
                ]));

                return response()->json([
                    'status'     => 202,
                    'message'    => 'PENDING_APPROVAL',
                    'pending_id' => $pendingId
                ], 202);
            }

            // 🔐 2FA (si activé)
            if ($user->two_factor_enabled) {

                DB::rollBack();

                $challengeId = Str::uuid()->toString();

                Cache::put(
                    "login_challenge:{$challengeId}",
                    [
                        'user_id'     => $user->id,
                        'device_type' => $deviceType,
                        'expires_at'  => now()->addMinutes(10),
                    ],
                    now()->addMinutes(10)
                );

                if ($user->two_factor_channel === 'email') {
                    TwoFactorService::initiate($user, $challengeId);
                }

                return response()->json([
                    'message'            => '2FA_REQUIRED',
                    'channel'            => $user->two_factor_channel,
                    'login_challenge_id' => $challengeId,
                ], 403);
            }

            // ⬇️ LOGIN DIRECT (aucune session vivante + pas de 2FA)
            return $this->finalizeLogin($user, $deviceType, $request);

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->errorResponse('error', $e->getMessage(), 500);
        }
    }

    // public function login(Request $request)
    // {
    //     $data = $request->validate([
    //         'login'       => 'required|string',
    //         'password'    => 'required|string',
    //         'device_type' => 'required|in:web,mobile',
    //     ]);

    //     DB::beginTransaction();

    //     try {

    //         $login = trim($data['login']);

    //         $user = User::leftJoin('usersenterprises as UE', 'users.id', '=', 'UE.user_id')
    //             ->where('users.status', 'enabled')
    //             ->where(function ($q) use ($login) {
    //                 if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
    //                     $q->where('users.email', $login);
    //                 } else {
    //                     $q->where('users.uuid', $login)
    //                         ->orWhere('users.user_name', $login);
    //                 }
    //             })
    //             ->select('users.*', 'UE.enterprise_id')
    //             ->first();

    //         if (!$user || !Hash::check($data['password'], $user->password)) {
    //             return $this->errorResponse('Les identifiants sont invalides.', 401);
    //         }

    //         $deviceType = $data['device_type'];

    //         /* =====================================================
    //        🧠 CONFLIT DE SESSION RÉEL ?
    //     ===================================================== */
    //         if (UserSessionService::hasRealActiveSession($user->id, $deviceType, $request->ip())) {

    //             $pendingId = $this->createPendingLogin(
    //                 $user->id,
    //                 $deviceType,
    //                 $request
    //             );

    //             Redis::publish('user.authentifications', json_encode([
    //                 'type' => 'new-login',
    //                 'data' => [
    //                     'userId'      => $user->id,
    //                     'pending_id'  => $pendingId,
    //                     'device_type' => $deviceType,
    //                     'ip'          => $request->ip(),
    //                     'user_agent'  => $request->userAgent(),
    //                 ]
    //             ]));

    //             return response()->json([
    //                 'message'    => 'PENDING_APPROVAL',
    //                 'pending_id' => $pendingId
    //             ], 202);
    //         }

    //         /* =====================================================
    //        🔐 2FA ?
    //     ===================================================== */
    //         if ($user->two_factor_enabled) {

    //             DB::rollBack();

    //             $challengeId = Str::uuid()->toString();

    //             Cache::put(
    //                 "login_challenge:{$challengeId}",
    //                 [
    //                     'user_id'     => $user->id,
    //                     'device_type' => $deviceType,
    //                     'expires_at'  => now()->addMinutes(10),
    //                 ],
    //                 now()->addMinutes(10)
    //             );

    //             TwoFactorService::initiate($user, $challengeId);

    //             return response()->json([
    //                 'message'            => '2FA_REQUIRED',
    //                 'channel'            => $user->two_factor_channel,
    //                 'login_challenge_id' => $challengeId,
    //             ], 403);
    //         }

    //         /* =====================================================
    //        ⬇️ LOGIN DIRECT
    //     ===================================================== */
    //         return $this->finalizeLogin($user, $deviceType, $request);
    //     } catch (\Throwable $e) {
    //         DB::rollBack();
    //         return $this->errorResponse('error', $e->getMessage(), 500);
    //     }
    // }
    protected function finalizeLogin(User $user, string $deviceType, Request $request)
    {
        // 🏢 Entreprise active
        $actualEse = $this->getEse($user->id);
        if ($actualEse) {
            $user->enterprise_id = $actualEse['id'];
        }
        // UserSession::where('user_id', $user->id)
        //     ->where('device_type', $deviceType)
        //     ->where('status', 'active')
        //     ->lockForUpdate()
        //     ->get();
        // // 🔥 RÈGLE D’EXCLUSIVITÉ (OBLIGATOIRE)
        // UserSession::where('user_id', $user->id)
        //     ->where('device_type', $deviceType)
        //     ->where('status', 'active')
        //     ->update([
        //         'status' => 'revoked',
        //         'revoked_at' => now(),
        //     ]);

        // 🔑 Token Sanctum
        $tokenExpiration = now()->addMinutes(60);
        $tokenResult = $user->createToken('api_token', ['*']);
        $plainTextToken = $tokenResult->plainTextToken;

        $tokenResult->accessToken->update([
            'expires_at' => $tokenExpiration,
        ]);

        // 📱 Session
        $agent = new Agent();

        $session = UserSession::create([
            'user_id'         => $user->id,
            'device_type'     => $deviceType,
            'device_name'     => $agent->device() ?: 'Unknown',
            'ip_address'      => $request->ip(),
            'user_agent'      => $request->userAgent(),
            'access_token_id' => $tokenResult->accessToken->id,
            'status'          => 'active',
            'last_seen_at'    => now(),
        ]);

        // 🔁 Refresh token
        $refreshTokenString = Str::random(64);
        $refreshToken = RefreshToken::create([
            'user_id'    => $user->id,
            'token'      => hash('sha256', $refreshTokenString),
            'expires_at' => now()->addDay(),
            'revoked'    => false,
        ]);

        // 📢 Redis
        Redis::publish('user.authentifications', json_encode([
            'type' => 'login-confirmed',
            'data' => [
                'userId'      => $user->id,
                'sessionId'   => $session->id,
                'device_type' => $deviceType,
            ]
        ]));

        DB::commit();

        return $this->successResponse('success', [
            'user'               => $user,
            'sessionId'          => $session->id,
            'enterprise'         => $actualEse,
            'access_token'       => $plainTextToken,
            'expires_in'         => 3600,
            'refresh_token'      => $refreshTokenString,
            'refresh_expires_at' => $refreshToken->expires_at,
        ]);
    }



    // protected function finalizeLogin(User $user, string $deviceType, Request $request)
    // { 
    //     // 🏢 Entreprise active
    //     $actualEse = $this->getEse($user->id);
    //     if ($actualEse) {
    //         $user->enterprise_id = $actualEse['id'];
    //     }

    //     // 🔑 Token Sanctum
    //     $tokenExpiration = now()->addMinutes(60);
    //     $tokenResult = $user->createToken('api_token',['*']);
    //     $plainTextToken = $tokenResult->plainTextToken;

    //     $tokenResult->accessToken->update([
    //         'expires_at' => $tokenExpiration,
    //     ]);

    //     // 📱 Session
    //     $agent = new Agent();

    //     $session = UserSession::create([
    //         'user_id'         => $user->id,
    //         'device_type'     => $deviceType,
    //         'device_name'     => $agent->device() ?: 'Unknown',
    //         'ip_address'      => $request->ip(),
    //         'user_agent'      => $request->userAgent(),
    //         'access_token_id' => $tokenResult->accessToken->id,
    //         'status'          => 'active',
    //         'last_seen_at'    => now(), 
    //     ]);

    //     // 🔁 Refresh token
    //     $refreshTokenString = Str::random(64);
    //     $refreshToken = RefreshToken::create([
    //         'user_id'    => $user->id,
    //         'token'      => hash('sha256', $refreshTokenString),
    //         'expires_at' => now()->addDay(),
    //         'revoked'    => false,
    //     ]);

    //     // 📢 Redis (login confirmé)
    //     Redis::publish('user.authentifications', json_encode([
    //         'type' => 'login-confirmed',
    //         'data' => [
    //             'userId'    => $user->id,
    //             'sessionId' => $session->id,
    //             'device_type' => $deviceType
    //         ]
    //     ]));
    //     DB::commit();
    //     return $this->successResponse('success', [
    //         'user'               => $user,
    //         'sessionId' => $session->id,
    //         'enterprise'         => $actualEse,
    //         'access_token'       => $plainTextToken,
    //         'expires_in'         => 3600,
    //         'refresh_token'      => $refreshTokenString,
    //         'refresh_expires_at' => $refreshToken->expires_at,
    //     ]);
    // }

    // public function login(Request $request)
    // {
    //     DB::beginTransaction();

    //     try {

    //         $data = $request->validate([
    //             'login'    => 'required|string',
    //             'password' => 'required|string',
    //         ]);


    //         $login = trim($data['login']);

    //         $user = User::leftJoin('usersenterprises as UE', 'users.id', '=', 'UE.user_id')
    //             ->where('users.status', 'enabled')
    //             ->where(function ($q) use ($login) {
    //                 if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
    //                     $q->where('users.email', $login);
    //                 } else {
    //                     $q->where('users.uuid', $login)
    //                     ->orWhere('users.user_name', $login);
    //                 }
    //             })
    //             ->select('users.*', 'UE.enterprise_id')
    //             ->first();

    //             if (!$user || !Hash::check($data['password'], $user->password)) {
    //                 return $this->errorResponse('Les identifiants sont invalides.', 401);
    //             }

    //         // 🔐 2FA
    //         if ($user->two_factor_enabled) {

    //             DB::rollBack();

    //             // 🔗 1️⃣ Générer le challenge AVANT
    //             $challengeId = Str::uuid()->toString();

    //             // 🔥 2️⃣ Stocker le challenge
    //             Cache::put(
    //                 "login_challenge:{$challengeId}",
    //                 [
    //                     'user_id'    => $user->id,
    //                     'expires_at' => now()->addMinutes(10),
    //                 ],
    //                 now()->addMinutes(10)
    //             );

    //             // 📧 3️⃣ Initier le 2FA AVEC le challenge
    //             if ($user->two_factor_channel === 'email') {
    //                 TwoFactorService::initiate($user, $challengeId);
    //             }

    //             // 🔁 4️⃣ Réponse frontend
    //             return response()->json([
    //                 'message'            => '2FA_REQUIRED',
    //                 'channel'            => $user->two_factor_channel,
    //                 'login_challenge_id' => $challengeId,
    //             ],403);
    //         }

    //         // ⬇️⬇️⬇️ LOGIN NORMAL (2FA désactivé) ⬇️⬇️⬇️

    //         $actualEse = $this->getEse($user->id);
    //         if ($actualEse) {
    //             $user->enterprise_id = $actualEse['id'];
    //         }

    //         $user->tokens()->delete();

    //         $tokenExpiration = now()->addMinutes(60);
    //         $token = $user->createToken('api_token', ['*']);
    //         $plainTextToken = $token->plainTextToken;

    //         $token->accessToken->update([
    //             'expires_at' => $tokenExpiration,
    //         ]);

    //         $refreshTokenString = Str::random(64);
    //         $refreshToken = RefreshToken::create([
    //             'user_id'    => $user->id,
    //             'token'      => hash('sha256', $refreshTokenString),
    //             'expires_at' => now()->addDay(),
    //             'revoked'    => false,
    //         ]);

    //         DB::commit();

    //         Redis::publish('user.authentifications', json_encode([
    //             'type' => 'login',
    //             'data' => [
    //                 'userId' => $user->id,
    //                 'access_token' => $plainTextToken,
    //                 'refresh_token' => $refreshTokenString
    //             ]
    //         ]));

    //         return $this->successResponse('success', [
    //             'user'               => $user,
    //             'enterprise'         => $actualEse,
    //             'access_token'       => $plainTextToken,
    //             'expires_in'         => 3600,
    //             'refresh_token'      => $refreshTokenString,
    //             'refresh_expires_at' => $refreshToken->expires_at,
    //         ]);

    //     } catch (\Throwable $e) {
    //         DB::rollBack();
    //         return $this->errorResponse('error', $e->getMessage(), 500);
    //     }
    // }

    protected function createPendingLogin(
        int $userId,
        string $deviceType,
        Request $request
    ): string {
        $pendingId = Str::uuid()->toString();

        Cache::put(
            "pending_login:{$pendingId}",
            [
                'user_id'     => $userId,
                'device_type' => $deviceType,
                'ip'          => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'expires_at'  => now()->addMinutes(5),
            ],
            now()->addMinutes(5)
        );

        return $pendingId;
    }

    public function forceLogoutOther(Request $request)
    {
        $data = $request->validate([
            'session_id' => 'required|integer|exists:user_sessions,id',
        ]);

        $currentUser = $request->user();

        $session = UserSession::where('id', $data['session_id'])
            ->where('user_id', $currentUser->id)
            ->where('status', 'active')
            ->firstOrFail();

        // 🔥 Révoquer la session distante
        $session->update([
            'status'     => 'revoked',
            'revoked_at' => now(),
        ]);

        // 🔑 Révoquer le token Sanctum lié
        if ($session->access_token_id) {
            PersonalAccessToken::find($session->access_token_id)?->delete();
        }

        // 📢 Notifier l’autre appareil
        Redis::publish('user.authentifications', json_encode([
            'type' => 'logout',
            'data' => [
                'userId'    => $session->user_id,
                'sessionId' => $session->id,
            ]
        ]));

        return $this->successResponse('SESSION_REVOKED');
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

        $user = $tokenRecord->user;

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
            'expires_in'    => 3600, // 10 minutes 
            'token_created_at' => $token->accessToken->created_at
        ]);
    }

    // Logout (révocation du token courant)
    public function logout(Request $request)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (!$token) {
            return $this->errorResponse('Token invalide.', 401);
        }

        // 🔥 Session liée
        UserSession::where('access_token_id', $token->id)
            ->update([
                'status' => 'revoked',
                'revoked_at' => now()
            ]);

        $token->delete();

        Redis::publish('user.authentifications', json_encode([
            'type' => 'logout',
            'data' => ['userId' => $user->id]
        ]));

        return $this->successResponse('success', null);
    }


    // Récupérer profil
    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    /***
     * 2FA Methods
     */
    public function verify($token)
    {
        $request = TwoFactorRequest::where('token', $token)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $request->update([
            'status'      => 'approved',
            'approved_at' => now()
        ]);

        event(new \App\Events\TwoFactorAuthEvent(
            $request->user_id,
            $request->token
        ));

        return redirect(config('app.frontend_url') . '/assets/2fa-success.html');
    }

    public function reject($token)
    {
        $request = TwoFactorRequest::where('token', $token)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $request->update([
            'status'      => 'rejected',
            'approved_at' => now()
        ]);

        event(new \App\Events\TwoFactorAuthEvent(
            $request->user_id,
            $request->token
        ));

        return redirect('/2fa/success');
    }

    public function trigger(Request $request)
    {
        $user = $request->user();

        // Simule une action sensible
        return TwoFactorService::test($user);
    }

    /**
     * COMPLETE LOGIN WITH 2FA
     */
    public function completeLogin(Request $request)
    {
        $data = $request->validate([
            'login_challenge_id' => 'required|string',
        ]);

        $challenge = Cache::get("login_challenge:{$data['login_challenge_id']}");

        if (!$challenge || now()->greaterThan($challenge['expires_at'])) {
            return $this->errorResponse('INVALID_OR_EXPIRED_CHALLENGE', 401);
        }

        return DB::transaction(function () use ($challenge, $request, $data) {

            $userId     = $challenge['user_id'];
            $deviceType = $challenge['device_type'];

            $twoFa = TwoFactorRequest::where('user_id', $userId)
                ->where('challenge_id', $data['login_challenge_id'])
                ->where('status', 'approved')
                ->whereNull('consumed_at')
                ->where('expires_at', '>=', now())
                ->first();

            if (!$twoFa) {
                return $this->errorResponse('2FA_NOT_APPROVED', 200);
            }

            $user = User::findOrFail($userId);

            /* =====================================================
           🧠 CONFLIT DE SESSION RÉEL ?
        ===================================================== */
            if (UserSessionService::hasRealActiveSession($user->id, $deviceType, $request->ip())) {

                $pendingId = $this->createPendingLogin(
                    $user->id,
                    $deviceType,
                    $request
                );

                Redis::publish('user.authentifications', json_encode([
                    'type' => 'new-login',
                    'data' => [
                        'userId'      => $user->id,
                        'pending_id'  => $pendingId,
                        'device_type' => $deviceType,
                        'ip'          => $request->ip(),
                        'user_agent'  => $request->userAgent(),
                    ]
                ]));

                return response()->json([
                    'message'    => 'PENDING_APPROVAL',
                    'pending_id' => $pendingId
                ], 202);
            }

            /* =====================================================
           ✅ LOGIN FINAL
        ===================================================== */
            $response = $this->finalizeLogin($user, $deviceType, $request);

            $twoFa->update(['consumed_at' => now()]);
            Cache::forget("login_challenge:{$data['login_challenge_id']}");

            return $response;
        });
    }


    public function approveLogin(Request $request)
    {
        $data = $request->validate([
            'pending_id' => 'required|string',
        ]);

        $user = $request->user();

        // 🔎 Vérifier le pending login
        $pending = Cache::get("pending_login:{$data['pending_id']}");

        if (!$pending || $pending['user_id'] !== $user->id) {
            return $this->errorResponse('INVALID_PENDING_LOGIN', 403);
        }

        // ✅ Marquer la décision
        Cache::put(
            "pending_login_decision:{$data['pending_id']}",
            'approved',
            now()->addMinutes(5)
        );

        // 🔌 Déconnecter CET appareil (celui qui approuve)
        $currentToken = $user->currentAccessToken();

        if ($currentToken) {
            // Marquer la session comme révoquée
            UserSession::where('access_token_id', $currentToken->id)
                ->update([
                    'status'     => 'revoked',
                    'revoked_at' => now(),
                ]);

            $currentToken->delete();
        }

        // 📢 Notifier (optionnel mais utile)
        Redis::publish('user.authentifications', json_encode([
            'type' => 'login-approved',
            'data' => [
                'userId'    => $user->id,
                'pending_id' => $data['pending_id'],
            ]
        ]));

        return $this->successResponse('LOGIN_APPROVED');
    }

    public function rejectLogin(Request $request)
    {
        $data = $request->validate([
            'pending_id' => 'required|string',
        ]);

        $user = $request->user();

        // 🔎 Vérifier le pending login
        $pending = Cache::get("pending_login:{$data['pending_id']}");

        if (!$pending || $pending['user_id'] !== $user->id) {
            return $this->errorResponse('INVALID_PENDING_LOGIN', 403);
        }

        // ❌ Marquer la décision
        Cache::put(
            "pending_login_decision:{$data['pending_id']}",
            'rejected',
            now()->addMinutes(5)
        );

        // 📢 Notifier (optionnel)
        Redis::publish('user.authentifications', json_encode([
            'type' => 'login-rejected',
            'data' => [
                'userId'    => $user->id,
                'pending_id' => $data['pending_id'],
            ]
        ]));

        return $this->successResponse('LOGIN_REJECTED');
    }

    public function pendingStatus(Request $request)
    {
        $data = $request->validate([
            'pending_id' => 'required|string',
        ]);

        $pendingKey  = "pending_login:{$data['pending_id']}";
        $decisionKey = "pending_login_decision:{$data['pending_id']}";
        $lockKey     = "pending_login_lock:{$data['pending_id']}";

        $pending = Cache::get($pendingKey);

        // ⏱️ Expiré ou inexistant
        if (
            !$pending ||
            now()->greaterThan(\Carbon\Carbon::parse($pending['expires_at']))
        ) {
            Cache::forget($pendingKey);
            Cache::forget($decisionKey);

            return response()->json([
                'status' => 'expired'
            ]);
        }

        $decision = Cache::get($decisionKey);

        // ⏳ Toujours en attente
        if (!$decision) {
            return response()->json([
                'status' => 'pending'
            ]);
        }

        // ❌ Refusé
        if ($decision === 'rejected') {
            Cache::forget($pendingKey);
            Cache::forget($decisionKey);

            return response()->json([
                'status' => 'rejected'
            ]);
        }

        // ✅ Approuvé → LOGIN FINAL
        if ($decision === 'approved') {

            // 🔒 Verrou anti double exécution
            if (!Cache::add($lockKey, true, 30)) {
                return response()->json([
                    'status' => 'pending'
                ]);
            }

            $user = User::findOrFail($pending['user_id']);
            $deviceType = $pending['device_type'];

            // 🔑 Token Sanctum
            $tokenExpiration = now()->addMinutes(60);
            $tokenResult = $user->createToken('api_token');
            $plainTextToken = $tokenResult->plainTextToken;

            $tokenResult->accessToken->update([
                'expires_at' => $tokenExpiration,
            ]);

            // UserSession::where('user_id', $user->id)
            //     ->where('device_type', $deviceType)
            //     ->where('status', 'active')
            //     ->lockForUpdate()
            //     ->get();



            // // 🔥 RÈGLE D’EXCLUSIVITÉ (OBLIGATOIRE)
            // UserSession::where('user_id', $user->id)
            //     ->where('device_type', $deviceType)
            //     ->where('status', 'active')
            //     ->update([
            //         'status' => 'revoked',
            //         'revoked_at' => now(),
            //     ]);


            // 📱 Session
            $agent = new Agent();


            $session = UserSession::create([
                'user_id'         => $user->id,
                'device_type'     => $deviceType,
                'device_name'     => $agent->device() ?: 'Unknown',
                'ip_address'      => $request->ip(),
                'user_agent'      => $request->userAgent(),
                'access_token_id' => $tokenResult->accessToken->id,
                'status'          => 'active',
                'last_seen_at'    => now(),
            ]);

            // 🔁 Refresh token
            $refreshTokenString = Str::random(64);
            $refreshToken = RefreshToken::create([
                'user_id'    => $user->id,
                'token'      => hash('sha256', $refreshTokenString),
                'expires_at' => now()->addDay(),
                'revoked'    => false,
            ]);

            // 🧹 Nettoyage cache
            Cache::forget($pendingKey);
            Cache::forget($decisionKey);
            // 🏢 Entreprise active
            $actualEse = $this->getEse($user->id);
            if ($actualEse) {
                $user->enterprise_id = $actualEse['id'];
            }


            return response()->json([
                'status' => 'approved',
                'data' => [
                    'user'               => $user,
                    'enterprise'         => $actualEse,
                    'access_token'       => $plainTextToken,
                    'expires_in'         => 3600,
                    'refresh_token'      => $refreshTokenString,
                    'refresh_expires_at' => $refreshToken->expires_at,
                    'session_id'         => $session->id,
                ]
            ]);
        }

        return response()->json(['status' => 'pending']);
    }

    

    // public function completeLogin(Request $request)
    // {
    //     $data = $request->validate([
    //         'login_challenge_id' => 'required|string',
    //     ]);

    //     $challenge = Cache::get("login_challenge:{$data['login_challenge_id']}");

    //     if (!$challenge || now()->greaterThan($challenge['expires_at'])) {
    //         return $this->errorResponse('INVALID_OR_EXPIRED_CHALLENGE', 401);
    //     }

    //     return DB::transaction(function () use ($challenge, $request, $data) {

    //         $userId     = $challenge['user_id'];
    //         $deviceType = $challenge['device_type'];

    //         // 🔐 2FA validé
    //         $twoFa = TwoFactorRequest::where('user_id', $userId)
    //             ->where('challenge_id', $data['login_challenge_id'])
    //             ->where('status', 'approved')
    //             ->whereNull('consumed_at')
    //             ->where('expires_at', '>=', now())
    //             ->first();

    //         if (!$twoFa) {
    //             return $this->errorResponse('2FA_NOT_APPROVED', 200);
    //         }

    //         // 🔓 USER
    //         $user = User::findOrFail($userId);

    //         // 🧠 Re-vérifier sessions actives
    //         if (
    //             UserSession::where('user_id', $user->id)
    //                 ->where('device_type', $deviceType)
    //                 ->where('status', 'active')
    //                 ->exists()
    //         ) {
    //             return $this->errorResponse('SESSION_CONFLICT', 409);
    //         }

    //         // 🔑 TOKEN
    //         $tokenExpiration = now()->addMinutes(60);
    //         $tokenResult = $user->createToken('api_token');
    //         $plainTextToken = $tokenResult->plainTextToken;

    //         $tokenResult->accessToken->update([
    //             'expires_at' => $tokenExpiration,
    //         ]);

    //         // 📱 SESSION
    //         $agent = new Agent();

    //         UserSession::create([
    //             'user_id'         => $user->id,
    //             'device_type'     => $deviceType,
    //             'device_name'     => $agent->device() ?: 'Unknown',
    //             'ip_address'      => $request->ip(),
    //             'user_agent'      => $request->userAgent(),
    //             'access_token_id' => $tokenResult->accessToken->id,
    //             'status'          => 'active',
    //         ]);

    //         // 🧾 Consommer 2FA
    //         $twoFa->update(['consumed_at' => now()]);
    //         Cache::forget("login_challenge:{$data['login_challenge_id']}");

    //         // 🔁 Refresh token
    //         $refreshTokenString = Str::random(64);
    //         $refreshToken = RefreshToken::create([
    //             'user_id'    => $user->id,
    //             'token'      => hash('sha256', $refreshTokenString),
    //             'expires_at' => now()->addDay(),
    //             'revoked'    => false,
    //         ]);

    //         // 🔔 Alerte sécurité
    //         dispatch(new SendSecurityLoginAlert($user, [
    //             'ip'       => $request->ip(),
    //             'device'   => $agent->device(),
    //             'browser'  => $agent->browser(),
    //             'location' => $twoFa->city ?? $twoFa->country ?? 'Inconnue',
    //         ]));

    //         return $this->successResponse('success', [
    //             'user'               => $user,
    //             'access_token'       => $plainTextToken,
    //             'expires_in'         => 3600,
    //             'refresh_token'      => $refreshTokenString,
    //             'refresh_expires_at' => $refreshToken->expires_at,
    //         ]);
    //     });
    // }


    // public function completeLogin(Request $request)
    // {
    //     $challengeId = $request->input('login_challenge_id');

    //     if (!$challengeId) {
    //         return $this->errorResponse('MISSING_CHALLENGE', 400);
    //     }

    //     $challenge = Cache::get("login_challenge:{$challengeId}");

    //     if (!$challenge) {
    //         return $this->errorResponse('INVALID_OR_EXPIRED_CHALLENGE', 401);
    //     }

    //     return DB::transaction(function () use ($challengeId, $challenge) {

    //         $userId = $challenge['user_id'];

    //         // 🔐 Vérifier 2FA strictement lié au challenge
    //         $twoFa = TwoFactorRequest::where('user_id', $userId)
    //             ->where('challenge_id', $challengeId)
    //             ->where('status', 'approved')
    //             ->whereNull('consumed_at')
    //             ->where('expires_at', '>=', now())
    //             ->first();

    //         if (!$twoFa) {
    //             return $this->errorResponse('2FA_NOT_APPROVED', 200);
    //         }

    //         // 🔓 Charger l'utilisateur
    //         $user = User::findOrFail($userId);

    //         // 🏢 Entreprise active
    //         $actualEse = $this->getEse($user->id);
    //         if ($actualEse) {
    //             $user->enterprise_id = $actualEse['id'];
    //         }

    //         // 🔐 Révoquer anciens tokens
    //         $user->tokens()->delete();

    //         // 🔑 Token Sanctum
    //         $tokenExpiration = now()->addMinutes(60);
    //         $token = $user->createToken('api_token', ['*']);
    //         $plainTextToken = $token->plainTextToken;

    //         $token->accessToken->update([
    //             'expires_at' => $tokenExpiration,
    //         ]);

    //         // 🧾 Consommer le 2FA
    //         $twoFa->update([
    //             'consumed_at' => now(),
    //         ]);

    //         // 🧹 Consommer le challenge APRÈS succès
    //         Cache::forget("login_challenge:{$challengeId}");

    //         // 🔁 Refresh token
    //         $refreshTokenString = Str::random(64);
    //         $refreshToken = RefreshToken::create([
    //             'user_id'    => $user->id,
    //             'token'      => hash('sha256', $refreshTokenString),
    //             'expires_at' => now()->addDay(),
    //             'revoked'    => false,
    //         ]);

    //         $agent = new Agent();

    //         dispatch(new SendSecurityLoginAlert($user, [
    //             'ip'       => request()->ip(),
    //             'device'   => $agent->device(),
    //             'browser'  => request()->userAgent(),
    //             'location' => $twoFa->city ?? $twoFa->country ?? 'Inconnue',
    //         ]));

    //         return $this->successResponse('success', [
    //             'user'               => $user,
    //             'enterprise'         => $actualEse,
    //             'access_token'       => $plainTextToken,
    //             'expires_in'         => 3600,
    //             'refresh_token'      => $refreshTokenString,
    //             'refresh_expires_at' => $refreshToken->expires_at,
    //         ]);
    //     });
    // }

}
