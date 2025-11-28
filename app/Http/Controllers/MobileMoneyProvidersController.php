<?php

namespace App\Http\Controllers;

use App\Models\serdipays;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\MobileMoneyProviders;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\StorewekaAccountsTransactionsRequest;
use App\Models\User;
use App\Models\wekamemberaccounts;
use Illuminate\Support\Facades\Http;

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

        $actualuser=User::find($user->id);
        $config = $actualuser->getMobileMoneyProviderConfigDetails($request->provider_id);

        if ($config) {
            $clientPhone=$config->phone_number ?: $request->phone_number;
            if (!$clientPhone) {
                return $this->errorResponse('Aucun numero de téléphone trouvé.');
            }
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
}
