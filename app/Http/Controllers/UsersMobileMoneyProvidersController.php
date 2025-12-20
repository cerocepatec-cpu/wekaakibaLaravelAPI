<?php

namespace App\Http\Controllers;

use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\MobileMoneyProviders;
use App\Models\UsersMobileMoneyProviders;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class UsersMobileMoneyProvidersController extends Controller
{
    public function store(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return $this->errorResponse('Utilisateur non authentifié', 401);
            }

            $validator = Validator::make($request->all(), [
                'user_id'                  => 'required|integer',
                'mobile_money_provider_id' => 'required|integer',
                'phone_number'             => 'required|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Données invalides', 422);
            }

            // 🔐 user connecté uniquement
            if ((int) $request->user_id !== $user->id) {
                return $this->errorResponse('Action non autorisée', 403);
            }

            // 📞 Validation pays (helper existant)
            if (!PhoneHelper::isValidPhoneNumber($request->phone_number, "CD")) {
                return $this->errorResponse("Numéro invalide pour le pays sélectionné", 422);
            }

            // ✅ Provider valide & actif
            $provider = MobileMoneyProviders::where('id', $request->mobile_money_provider_id)
                ->where('status', 'enabled')
                ->first();

            if (!$provider) {
                return $this->errorResponse('Provider mobile money invalide ou inactif', 404);
            }

            // 🏢 Vérifier entreprise
            $enterprise = $this->getEse($user->id);
            if (!$enterprise || $enterprise->id !== $provider->enterprise_id) {
                return $this->errorResponse("Ce provider n'appartient pas à votre entreprise", 403);
            }

            // 📶 Validation par PRÉFIXE PROVIDER (metadata)
            $prefixes = collect($provider->metadata)
            ->pluck('prefix')
            ->flatten()
            ->filter()
            ->values()
            ->toArray();

            $raw = preg_replace('/\D/', '', $request->phone_number);

            // Normalisation RDC
            if (str_starts_with($raw, '243')) {
                $msisdn = substr($raw, 3);
            } elseif (str_starts_with($raw, '0')) {
                $msisdn = substr($raw, 1);
            } else {
                $msisdn = $raw;
            }

            if (strlen($msisdn) !== 9) {
                return $this->errorResponse("Numéro invalide", 422);
            }

            // Préfixe réel
            $numberPrefix = substr($msisdn, 0, 2);

            // Vérification PROVIDER
            if (!empty($prefixes) && !in_array($numberPrefix, $prefixes, true)) {
                return $this->errorResponse(
                    "Ce numéro ne correspond pas au réseau {$provider->name}",
                    422
                );
            }

            // 🔁 Numéro déjà utilisé sur un autre provider ?
            $alreadyUsed = UsersMobileMoneyProviders::where('user_id', $user->id)
                ->where('phone_number', $request->phone_number)
                ->where('mobile_money_provider_id', '!=', $provider->id)
                ->first();

            if ($alreadyUsed) {
                return $this->errorResponse(
                    'Ce numéro est déjà associé à un autre réseau mobile',
                    409
                );
            }

            // 💾 Save (update ou create)
            $record = UsersMobileMoneyProviders::updateOrCreate(
                [
                    'user_id'                  => $user->id,
                    'mobile_money_provider_id' => $provider->id,
                ],
                [
                    'phone_number' => $request->phone_number,
                    'status'       => 'active',
                ]
            );

            return response()->json([
                'status'  => 200,
                'message' => 'success',
                'error'   => null,
                'data'    => $this->show($record),
            ]);

        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Erreur lors de la configuration du Mobile Money',
                500
            );
        }
    }

    public function show(UsersMobileMoneyProviders $usermobileprovider){
       return UsersMobileMoneyProviders::join('mobile_money_providers','users_mobile_money_providers.mobile_money_provider_id','=','mobile_money_providers.id')
       ->where('users_mobile_money_providers.id',$usermobileprovider->id)->get(['users_mobile_money_providers.*', 'mobile_money_providers.provider',
        'mobile_money_providers.country',
        'mobile_money_providers.name',
        'mobile_money_providers.metadata',])->first();
    }
}
