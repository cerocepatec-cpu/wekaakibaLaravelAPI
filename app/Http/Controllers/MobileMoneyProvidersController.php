<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\serdipays;
use Illuminate\Http\Request;
use App\Models\wekamemberaccounts;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\MobileMoneyProviders;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Http\Requests\StorewekaAccountsTransactionsRequest;

class MobileMoneyProvidersController extends Controller
{
    /**
     * get all providers
     */
    public function index($enterpriseId)
    {
        $user = Auth::user();
        if (!$user) {
            return $this->errorResponse('Utilisateur non authentifié', 401);
        }

        if (!is_numeric($enterpriseId)) {
            return $this->errorResponse('Enterprise ID invalide', 400);
        }

        // Vérifier l'entreprise de l'utilisateur
        $enterprise = $this->getEse($user->id);
        if (!$enterprise) {
            return $this->errorResponse('Entreprise introuvable', 404);
        }

        if ($enterprise->id != $enterpriseId) {
            return $this->errorResponse("Vous n'appartenez pas à cette entreprise", 403);
        }

        try {
            $providers = MobileMoneyProviders::where('enterprise_id', $enterpriseId)
                ->where('status', 'enabled')
                ->get();

            if ($providers->isEmpty()) {
                return $this->errorResponse('Aucun provider mobile money trouvé pour cette entreprise', 404);
            }

            return response()->json([
                "status"  => 200,
                "message" => "success",
                "error"   => null,
                "data"    => $providers
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Une erreur est survenue', 500);
        }
    }


    public function withdrawbymobilemoney(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'currency' => 'required|string|max:3',
            'telecom' => 'required|string|max:50',
            'user_id' => 'required|number|max:50',
            'clientPhone' => 'required|string|max:50',
            'account_id' => 'required|number|min:1',
            'enterprise_id' => 'required|number|min:1',
            'pin' => 'required|string|min:4',
        ]);

        $affected=$this-> userenterpriseaffectation($request['user_id'],$request['enterprise_id']);
        if (!$affected) {
            return $this->errorResponse('user not affected');
        }

        $ese=$this->getEse($request['user_id']);
        if(!$ese){
            return $this->errorResponse('enterprise not find');
        }

        $actualuser=$this->getinfosuser($request['user_id']);
        $clientPhone=null;
        
        if(!$actualuser || $actualuser->status!=='enabled'){
            return $this->errorResponse('user not found');
        }
        
        if($actualuser->pin!==$request['pin']){
            return $this->errorResponse('incorrect pin');
        }
            $config = $actualuser->getMobileMoneyProviderConfigDetails($request->telecom);

            if ($config) {
                $clientPhone= $config->phone_number ?: $request->clientPhone;
                if (!$clientPhone) {
                    return $this->errorResponse('no phone number configured for this user.');
                }
            } else {
                return $this->errorResponse('any provider set for this user.');
            }

        $serdiconfig=serdipays::latest()->first();
        if(!$serdiconfig){
            return $this->errorResponse('configuration not found');
        }

        $url =$serdiconfig->merchant_payment_endpoint;

        $payload = [
            "api_id" =>$serdiconfig->merchant_api_id,
            "api_password" =>$serdiconfig->password,
            "merchantCode" =>$serdiconfig->merchantCode,
            "merchant_pin" =>$serdiconfig->merchant_pin,
            "clientPhone" =>$clientPhone,
            "amount" =>$request['amount'],
            "currency" =>$request['currency'],
            "telecom" =>$request['telecom'],
        ];

        try {
            $account = $this->accountmembersold($request['account_id'], $request['user_id']);
            if (!$account || $account->status=='disabled') {
                return $this->errorResponse('account not found');
            }
            $accountstransactionctrl=new WekaAccountsTransactionsController();
            $mouvement=$accountstransactionctrl->store(new StorewekaAccountsTransactionsRequest([
                'member_account_id' => $request['account_id'],
                'amount' => $request['amount']+($config->b2c_fees*$request['amount']/100)+
                ($config->additional_fees*$request['amount']/100),
                'type' => 'withdraw',
                'description' => 'withdraw by mobile money',
                'user_id'=>$request['user_id'],
                'operation_done_by'=>$request['user_id'],
                'fees'=>$request['fees'],
                'phone' => $clientPhone,
                'enterprise_id' => $request['enterprise_id'],
                'motif' => 'Retrait mobile money',
            ]));
            
            $response = Http::post($url, $payload);

            if ($response->successful()) {
                return response()->json([
                    'status' => 'success',
                    'data' => $response->json()
                ]);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Requête échouée',
                    'error' => $response->body()
                ], $response->status());
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la connexion à l’API',
                'exception' =>$e->getMessage()
            ], 500);
        }
    }
    
    public function depositbymobilemoney(Request $request)
    {
        $user=Auth::user();
        if (!$user) {
          return $this->errorResponse("Vous n'êtes pas authentifié. Désolé.",400);  # code...
        }

        if ($user->status!="enabled") {
           return $this->errorResponse("Votre compte devrait être désactivé",400);  # 
        }

        $request->validate([
            'amount' => 'required|numeric|min:1',
            'provider_id' => 'required|numeric|min:1',
            'phone_number' => 'nullable|string|max:50',
            'account_id' => 'required|number|min:1',
            'motif' => 'nullable|string|min:4',
        ]);

        $affected=$this->userenterpriseaffectation($user->id,$this->getEse($user->id));
        if (!$affected) {
            return $this->errorResponse("Nous n'arrivons pas à vous identifier");
        }

        $account=wekamemberaccounts::find($request['account_id']);
        if (!$account || !$account->isAvailable()) {
            return $this->errorResponse("Nous n'avons pas pu vérifier votre compte WEKA AKIBA",400);  # 
        }

        if (!$account->ismine($user->id)) {
            return $this->errorResponse("Le compte WEKA AKIBA envoyé n'est pas le vôtre",400);
        }

        $clientPhone=$request['phone_number'];
        if (!$clientPhone) {
            return $this->errorResponse("Le numero de telephone n'est pas envoyé",400);
        }

        $actualuser=User::find($user->id);
        $provider=MobileMoneyProviders::find($request->provider_id);
        if (!$provider) {
            return $this->errorResponse("Le fournisseur mobile n'est pas envoyé",400);
        }

        // $config = $actualuser->getMobileMoneyProviderConfigDetails($request->provider_id);

        // if ($config) {
        //     $clientPhone=$config->phone_number ?: $request->phone_number;
        //     if (!$clientPhone) {
        //         return $this->errorResponse('Aucun numero de téléphone trouvé.');
        //     }
        // }

        try {
            $serdiconfig = serdipays::configFor("test");
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        // Champs obligatoires
        $requiredFields = [
            'merchant_payment_endpoint',
            'merchant_api_id',
            'password',
            'merchantCode',
            'merchant_pin'
        ];

        // Vérification
        $check = $serdiconfig->checkRequiredFields($requiredFields);

        if (!$check['ok']) {
            return $this->errorResponse("Le champ '{$check['field']}' n'est pas configuré.", 400);
        }

        // Maintenant tu es sûr que tout est rempli
        $url = $serdiconfig->merchant_payment_endpoint;

        $payload = [
            "api_id"       => $serdiconfig->merchant_api_id,
            "api_password" => $serdiconfig->password,
            "merchantCode" => $serdiconfig->merchantCode,
            "merchant_pin" => $serdiconfig->merchant_pin,
            "clientPhone"  => $clientPhone,
            "amount"       => $request['amount'],
            "currency"     =>'USD',
            "telecom"      => $provider->provider,
        ];

        try {
            $account =wekamemberaccounts::find($request['account_id']);
            if (!$account || $account->account_status!='enabled') {
                return $this->errorResponse('Votre compte est indisponible pour le moment.');
            }

            $account->update(['sold'=>($account->sold+$request['amount'])]);
            // $accountstransactionctrl=new WekaAccountsTransactionsController();
            // $mouvement=$accountstransactionctrl->store(new StorewekaAccountsTransactionsRequest([
            //     'member_account_id' => $request['account_id'],
            //     'amount' => $request['amount']+($config->b2c_fees*$request['amount']/100)+
            //     ($config->additional_fees*$request['amount']/100),
            //     'type' => 'withdraw',
            //     'description' => 'withdraw by mobile money',
            //     'user_id'=>$request['user_id'],
            //     'operation_done_by'=>$request['user_id'],
            //     'fees'=>$request['fees'],
            //     'phone' => $clientPhone,
            //     'enterprise_id' => $request['enterprise_id'],
            //     'motif' => 'Retrait mobile money',
            // ]));
            $token = $serdiconfig->token;
            $headers = [
            "Authorization" => "Bearer {$token}",
            "Accept" => "application/json"
            ];
           $response = Http::withHeaders($headers)->post($url, $payload);

            if ($response->status() === 401) {

                // Ré-émission du token
                $refresh = app(\App\Http\Controllers\SerdipaysController::class)->getToken();

                if ($refresh->getStatusCode() != 200) {
                    DB::rollBack();
                    return $this->errorResponse("Impossible de régénérer le token SerdiPay.");
                }

                $newToken = json_decode($refresh->getContent(), true)['data'];

                $serdiconfig->update(['token' => $newToken]);

                // Requête avec le token renouvelé
                $headers["Authorization"] = "Bearer {$newToken}";

                $response = Http::withHeaders($headers)->post($url, $payload);
            }

           // 11. Vérification finale
            if (!$response->successful()) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Requête échouée',
                    'error' => $response->json()
                ], $response->status());
            }

            // DB::commit();

        return $this->successResponse("success",$response->json());
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la connexion à l’API',
                'exception' =>$e->getMessage()
            ], 500);
        }
    }

    public function depositbymobilemoneydraft(Request $request)
    {
        // 1. Authentification
        $user = Auth::user();
        if (!$user) {
            return $this->errorResponse("Vous n'êtes pas authentifié.", 400);
        }

        if ($user->status != "enabled") {
            return $this->errorResponse("Votre compte a été désactivé.", 400);
        }

        // 2. Validation
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'provider_id' => 'required|numeric|min:1',
            'phone_number' => 'nullable|string|max:50',
            'account_id' => 'required|numeric|min:1',
            'motif' => 'nullable|string|min:4',
            'currency' => 'required|string',
            'telecom' => 'required|string',
            'enterprise_id' => 'required|numeric'
        ]);

        // 3. Vérification affectation
        $affected = $this->userenterpriseaffectation($user->id, $this->getEse($user->id));
        if (!$affected) {
            return $this->errorResponse("Nous n'arrivons pas à vous identifier.");
        }

        // 4. Vérification du compte
        $account = wekamemberaccounts::find($request['account_id']);
        if (!$account || !$account->isAvailable()) {
            return $this->errorResponse("Compte WEKA AKIBA introuvable ou désactivé.", 400);
        }

        if (!$account->ismine($user->id)) {
            return $this->errorResponse("Le compte indiqué ne vous appartient pas.", 400);
        }

        // 5. Config Mobile Money
        $actualuser=User::find($user->id);
        $config = $actualuser->getMobileMoneyProviderConfigDetails($request->provider_id);
        if (!$config) {
            return $this->errorResponse("Configuration Mobile Money introuvable.", 400);
        }

        $clientPhone = $config->phone_number ?: $request->phone_number;
        if (!$clientPhone) {
            return $this->errorResponse("Aucun numéro de téléphone disponible.", 400);
        }

        // 6. Récupération config SerdiPay
        try {
            $serdiconfig = serdipays::configFor("test");
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        $requiredFields = [
            'merchant_payment_endpoint',
            'merchant_api_id',
            'password',
            'merchantCode',
            'merchant_pin',
            'token'
        ];

        $check = $serdiconfig->checkRequiredFields($requiredFields);
        if (!$check['ok']) {
            return $this->errorResponse("Le champ '{$check['field']}' n'est pas configuré.", 400);
        }

        // 7. Payload + Header
        $url = $serdiconfig->merchant_payment_endpoint;
        $token = $serdiconfig->token;

        $headers = [
            "Authorization" => "Bearer {$token}",
            "Accept" => "application/json"
        ];

        $payload = [
            "api_id"       => $serdiconfig->merchant_api_id,
            "api_password" => $serdiconfig->password,
            "merchantCode" => $serdiconfig->merchantCode,
            "merchant_pin" => $serdiconfig->merchant_pin,
            "clientPhone"  => $clientPhone,
            "amount"       => $request['amount'],
            "currency"     => $request['currency'],
            "telecom"      => $request['telecom'],
        ];

        DB::beginTransaction();

        try {
            // 8. Stockage du mouvement avant l'appel API
            $accountObj = $this->accountmembersold($request['account_id'], $user->id);
            if (!$accountObj || $accountObj->status == 'disabled') {
                DB::rollBack();
                return $this->errorResponse('Compte introuvable', 404);
            }

            $transactionController = new WekaAccountsTransactionsController();
            $fees = ($config->b2c_fees * $request['amount'] / 100) + ($config->additional_fees * $request['amount'] / 100);

            $transactionController->store(
                new StorewekaAccountsTransactionsRequest([
                    'member_account_id' => $request['account_id'],
                    'amount' => $request['amount'] + $fees,
                    'type' => 'withdraw',
                    'description' => 'withdraw by mobile money',
                    'user_id' => $user->id,
                    'operation_done_by' => $user->id,
                    'fees' => $fees,
                    'phone' => $clientPhone,
                    'enterprise_id' => $request['enterprise_id'],
                    'motif' => 'Retrait mobile money',
                ])
            );

            // 9. Première requête API
            $response = Http::withHeaders($headers)->post($url, $payload);

            // 10. Si token expiré → on le rafraîchit et on relance
            if ($response->status() === 401) {

                // Ré-émission du token
                $refresh = app(\App\Http\Controllers\SerdipaysController::class)->getToken();

                if ($refresh->getStatusCode() != 200) {
                    DB::rollBack();
                    return $this->errorResponse("Impossible de régénérer le token SerdiPay.");
                }

                $newToken = json_decode($refresh->getContent(), true)['data'];

                $serdiconfig->update(['token' => $newToken]);

                // Requête avec le token renouvelé
                $headers["Authorization"] = "Bearer {$newToken}";

                $response = Http::withHeaders($headers)->post($url, $payload);
            }

            // 11. Vérification finale
            if (!$response->successful()) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Requête échouée',
                    'error' => $response->json()
                ], $response->status());
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $response->json()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la connexion à l’API',
                'exception' => $e->getMessage()
            ], 500);
        }
    }
}
