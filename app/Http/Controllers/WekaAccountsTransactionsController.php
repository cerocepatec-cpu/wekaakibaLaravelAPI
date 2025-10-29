<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use App\Models\User;
use App\Models\funds;
use App\Models\moneys;
use App\Models\Invoices;
use App\Models\serdipays;
use Illuminate\Http\Request;
use App\Models\requestHistory;
use App\Models\transactionfee;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\wekafirstentries;
use App\Models\wekamemberaccounts;
use Illuminate\Support\Facades\DB;
use App\Exports\TransactionsExport;
use App\Http\Controllers\Controller;
use App\Models\MobileMoneyProviders;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\wekaAccountsTransactions;
use App\Http\Requests\StorerequestHistoryRequest;
use App\Http\Requests\StorewekaAccountsTransactionsRequest;
use App\Http\Requests\UpdatewekaAccountsTransactionsRequest;
use App\Helpers\PhoneHelper;

class WekaAccountsTransactionsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // return $request;
        $list=[];
        if(isset($request->from)==false && empty($request->from) && isset($request->to)==false && empty($request->to)){
            $request['from']= date('Y-m-d');
            $request['to']=date('Y-m-d');
        }

        if (isset($request->user_id)) {
            $actualuser=$this->getinfosuser($request->user_id);
            if ($actualuser) {
                $ese=$this->getEse($actualuser->id);
                if ($ese) {
                    if ($actualuser['user_type']=='super_admin') {
                        //report for super admin users
                        if(isset($request['criteria']) && !empty($request['criteria'])){
                            return $this->reportTransactionsgroupebBy($request);
                        }else{
                            try {

                                if (isset($request['members']) && count($request['members'])>0) {
    
                                    $list1=collect(wekaAccountsTransactions::whereIn('member_id',$request['members'])
                                    ->whereBetween('done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                                    ->get());
                                    $list=$list1->transform(function($item){
                                        return $this->show($item);
                                    });
                        
                                    return response()->json([
                                        "status"=>200,
                                        "message"=>"success",
                                        "error"=>null,
                                        "data"=>$list
                                    ]);
                                }  
                                
                                if (isset($request['cashiers']) && count($request['cashiers'])>0) {
    
                                    $list1=collect(wekaAccountsTransactions::whereIn('user_id',$request['cashiers'])
                                    ->whereBetween('done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                                    ->get());
                                    $list=$list1->transform(function($item){
                                        return $this->show($item);
                                    });
                        
                                    return response()->json([
                                        "status"=>200,
                                        "message"=>"success",
                                        "error"=>null,
                                        "data"=>$list
                                    ]);
                                } 
                                
                                if (isset($request['moneys']) && count($request['moneys'])>0) {
    
                                    $list1=collect(wekaAccountsTransactions::join('wekamemberaccounts','weka_accounts_transactions.member_account_id','=','wekamemberaccounts.id')
                                    ->whereIn('wekamemberaccounts.money_id',$request['moneys'])
                                    ->whereBetween('done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                                    ->get());
                                    $list=$list1->transform(function($item){
                                        return $this->show($item);
                                    });
                        
                                    return response()->json([
                                        "status"=>200,
                                        "message"=>"success",
                                        "error"=>null,
                                        "data"=>$list
                                    ]);
                                }
    
                                $list1=collect(wekaAccountsTransactions::where('enterprise_id',$request['enterprise_id'])
                                ->whereBetween('done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                                ->get());
                                $list=$list1->transform(function($item){
                                    return $this->show($item);
                                });
                    
                                return response()->json([
                                    "status"=>200,
                                    "message"=>"success",
                                    "error"=>null,
                                    "data"=>$list
                                ]);
                            } catch (Exception $th) {
                                return response()->json([
                                    "status"=>500,
                                    "message"=>"error",
                                    "error"=>$th->getMessage(),
                                    "data"=>null
                                ]);
                            }
                        }
                    }else{
                        //report for no super admin users
                        try {

                            if (isset($request['members']) && count($request['members'])>0) {

                                $list1=collect(wekaAccountsTransactions::whereIn('member_id',$request['members'])
                                ->where('user_id',$actualuser->id)
                                ->whereBetween('done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                                ->get());
                                $list=$list1->transform(function($item){
                                    return $this->show($item);
                                });
                    
                                return response()->json([
                                    "status"=>200,
                                    "message"=>"success",
                                    "error"=>null,
                                    "data"=>$list
                                ]);
                            }  
                            
                            if (isset($request['cashiers']) && count($request['cashiers'])>0) {

                                $list1=collect(wekaAccountsTransactions::whereIn('user_id',$request['cashiers'])
                                ->where('user_id',$actualuser->id)
                                ->whereBetween('done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                                ->get());
                                $list=$list1->transform(function($item){
                                    return $this->show($item);
                                });
                    
                                return response()->json([
                                    "status"=>200,
                                    "message"=>"success",
                                    "error"=>null,
                                    "data"=>$list
                                ]);
                            } 
                            
                            if (isset($request['moneys']) && count($request['moneys'])>0) {

                                $list1=collect(wekaAccountsTransactions::join('wekamemberaccounts','weka_accounts_transactions.member_account_id','=','wekamemberaccounts.id')
                                ->where('weka_accounts_transactions.user_id',$actualuser->id)
                                ->whereIn('wekamemberaccounts.money_id',$request['moneys'])
                                ->whereBetween('done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                                ->get());
                                $list=$list1->transform(function($item){
                                    return $this->show($item);
                                });
                    
                                return response()->json([
                                    "status"=>200,
                                    "message"=>"success",
                                    "error"=>null,
                                    "data"=>$list   
                                ]);
                            }

                            $list1=collect(wekaAccountsTransactions::where('enterprise_id',$request['enterprise_id'])
                            ->where('user_id',$actualuser->id)
                            ->whereBetween('done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                            ->get());
                            $list=$list1->transform(function($item){
                                return $this->show($item);
                            });
                
                            return response()->json([
                                "status"=>200,
                                "message"=>"success",
                                "error"=>null,
                                "data"=>$list
                            ]);
                        } catch (Exception $th) {
                            return response()->json([
                                "status"=>500,
                                "message"=>"error",
                                "error"=>$th->getMessage(),
                                "data"=>null
                            ]);
                        }
                    }
                }else{
                    return response()->json([
                        "status"=>400,
                        "message"=>"error",
                        "error"=>"unknown enterprise",
                        "data"=>null
                    ]);
                }

            }else{
                return response()->json([
                    "status"=>400,
                    "message"=>"error",
                    "error"=>"unknown user",
                    "data"=>null
                ]);
            }
        }
        else{
            return response()->json([
                "status"=>400,
                "message"=>"error",
                "error"=>"user not sent",
                "data"=>null
            ]);
        }
    }


    /**
     * transactions list paginated by user
     */
    // public function getTransactionslistByUser(Request $request)
    // {
    //     // if (empty($request->from) || empty($request->to)) {
    //     //    $request['from'] = date('Y-m-01');
    //     //    $request['to'] = date('Y-m-t');
    //     // }

    //     if (!isset($request->user_id) || empty($request->user_id)) {
    //         return $this->errorResponse('user not sent');
    //     }

    //     $actualuser = $this->getinfosuser($request->user_id);
    //     if (!$actualuser) {
    //         return $this->errorResponse('unknown user');
    //     }

    //     $enterprise = $this->getEse($actualuser->id);
    //     if (!$enterprise) {
    //         return $this->errorResponse('unknown enterprise');
    //     }

    //     try {
    //         $query = wekaAccountsTransactions::query();
    //         $query->where('member_id',$request->user_id);
    //         $query->where('transaction_status', '!=', 'cancelled');
    //         $query->whereBetween('done_at', [
    //             $request['from'] . ' 00:00:00',
    //             $request['to'] . ' 23:59:59'
    //         ]);

           
    //         $allIds = [];
    //         (clone $query)->select('weka_accounts_transactions.id')
    //             ->orderBy('weka_accounts_transactions.id')    
    //             ->chunk(1000, function ($transactions) use (&$allIds) {
    //                 foreach ($transactions as $t) {
    //                     $allIds[] = $t->id;
    //                 }
    //             });

            
    //         $limit = $request->get('limit', 50);
    //         $paginated = $query->orderBy('done_at', 'desc')->paginate($limit);
    //         $data = $paginated->getCollection()->transform(fn($item) => $this->show($item));
    //         $paginated->setCollection($data);

            
    //        $totalsByMoney = [];
    //        $totalsByMoney = $data->groupBy('money_id')->map(function ($items, $money_id) 
    //        {
    //             return [
    //                 'money_id'     => $money_id,
    //                 'abreviation'  => $items->first()['abreviation'] ?? '',
    //                 'total'      => $items->sum('amount'),
    //                 'total_in'     => $items->where('type', 'deposit')->sum('amount'),
    //                 'total_out'    => $items->where('type', 'withdraw')->sum('amount'),
    //                 'total_net'    => $items->where('type', 'deposit')->sum('amount') - $items->where('type', 'withdraw')->sum('amount'),
    //             ];
    //         })->values();

    //         return response()->json([
    //             "status" => 200,
    //             "from"=> $request['from'],
    //             "to"=> $request['to'], 
    //             "message" => "success",
    //             "error" => null,
    //             "data" => $paginated,
    //             "all_ids" => $allIds,
    //             "totals_by_money" => $totalsByMoney
    //         ]);
    //     } catch (Exception $ex) {
    //         return $this->errorResponse($ex->getMessage(), 500);
    //     }
    // }
    public function getTransactionslistByUser(Request $request)
    {
        if (!isset($request->user_id) || empty($request->user_id)) {
            return $this->errorResponse('user not sent');
        }

        $actualuser = $this->getinfosuser($request->user_id);
        if (!$actualuser) {
            return $this->errorResponse('unknown user');
        }

        $enterprise = $this->getEse($actualuser->id);
        if (!$enterprise) {
            return $this->errorResponse('unknown enterprise');
        }

        try {
            // 📌 Définir la période par défaut = aujourd'hui
            $from = $request->input('from') ?? date('Y-m-d');
            $to   = $request->input('to')   ?? date('Y-m-d');

            $query = wekaAccountsTransactions::query();
            $query->where('transaction_status', '!=', 'cancelled');

            // 📌 Si ce n’est pas un super_admin, filtrer par user_id
            if ($actualuser->user_type !== "super_admin") {
                $query->where('user_id', $request->user_id);
            }

            // 📌 Filtrer par période
            $query->whereBetween('done_at', [
                $from . ' 00:00:00',
                $to . ' 23:59:59'
            ]);

            // 📌 Filtrer par liste de cashiers (colonne user_id)
            if ($request->has('cashiers') && is_array($request->cashiers) && count($request->cashiers) > 0) {
                $query->whereIn('user_id', $request->cashiers);
            }

            // 📌 Filtrer par liste de members (colonne member_id)
            if ($request->has('members') && is_array($request->members) && count($request->members) > 0) {
                $query->whereIn('member_id', $request->members);
            }

            // 📌 Récupération des IDs pour export
            $allIds = [];
            (clone $query)->select('weka_accounts_transactions.id')
                ->orderBy('weka_accounts_transactions.id')
                ->chunk(1000, function ($transactions) use (&$allIds) {
                    foreach ($transactions as $t) {
                        $allIds[] = $t->id;
                    }
                });

            // 📌 Pagination
            $limit = $request->get('limit', 50);
            $paginated = $query->orderBy('done_at', 'desc')->paginate($limit);

            $data = $paginated->getCollection()->transform(fn($item) => $this->show($item));
            $paginated->setCollection($data);

            // 📌 Totaux par devise
            $totalsByMoney = $data->groupBy('money_id')->map(function ($items, $money_id) {
                return [
                    'money_id'    => $money_id,
                    'abreviation' => $items->first()['abreviation'] ?? '',
                    'total'       => $items->sum('amount'),
                    'total_in'    => $items->where('type', 'deposit')->sum('amount'),
                    'total_out'   => $items->where('type', 'withdraw')->sum('amount'),
                    'total_net'   => $items->where('type', 'deposit')->sum('amount') - $items->where('type', 'withdraw')->sum('amount'),
                ];
            })->values();

            return response()->json([
                "status" => 200,
                "from" => $from,
                "to" => $to,
                "message" => "success",
                "error" => null,
                "data" => $paginated,
                "all_ids" => $allIds,
                "totals_by_money" => $totalsByMoney
            ]);
        } catch (\Exception $ex) {
            return $this->errorResponse($ex->getMessage(), 500);
        }
    }


    /**
     * Transactions by account
     */
    public function getTransactionsByAccount(Request $request)
    {
        // ✅ Vérification des paramètres obligatoires
        if (empty($request->user_id)) {
            return $this->errorResponse('user_id is required');
        }

        if (empty($request->account)) {
            return $this->errorResponse('account is required');
        }

        if (empty($request->from) || empty($request->to)) {
            return $this->errorResponse('from and to dates are required');
        }

        // ✅ Récupération de l'utilisateur
        $actualuser = $this->getinfosuser($request->user_id);
        if (!$actualuser) {
            return $this->errorResponse('unknown user');
        }

        // ✅ Vérification de l'entreprise
        $enterprise = $this->getEse($actualuser->id);
        if (!$enterprise) {
            return $this->errorResponse('unknown enterprise');
        }

        try {
            // ✅ Construction de la requête
            $query = wekaAccountsTransactions::query();

            // ✅ Filtrage par ID du compte (obligatoire)
            $query->where('member_account_id', $request->account);

            // ✅ Filtrage optionnel par transaction_status
            if (!empty($request['transaction_status'])) {
                $query->where('transaction_status', $request['transaction_status']);
            } elseif ($request->get('exclude_cancelled', true)) {
                $query->where('transaction_status', '!=', 'cancelled');
            }

            // ✅ Filtrage par plage de dates
            $query->whereBetween('done_at', [
                $request['from'] . ' 00:00:00',
                $request['to'] . ' 23:59:59'
            ]);

            // ✅ Récupération des IDs de toutes les transactions
            $allIds = [];
            (clone $query)->select('id')
                ->orderBy('id')
                ->chunk(1000, function ($transactions) use (&$allIds) {
                    foreach ($transactions as $t) {
                        $allIds[] = $t->id;
                    }
                });

            // ✅ Pagination
            $limit = $request->get('per_page', 50);
            $paginated = $query->orderBy('done_at', 'desc')->paginate($limit);

            // ✅ Transformation des résultats
            $data = $paginated->getCollection()->transform(fn($item) => $this->show($item));
            $paginated->setCollection($data);

            // ✅ Totaux par devise (money_id)
            $totalsByMoney = $data->groupBy('money_id')->map(function ($items, $money_id) {
                return [
                    'money_id'    => $money_id,
                    'abreviation' => $items->first()['abreviation'] ?? '',
                    'total'       => $items->sum('amount'),
                    'total_in'    => $items->where('type', 'deposit')->sum('amount'),
                    'total_out'   => $items->where('type', 'withdraw')->sum('amount'),
                    'total_net'   => $items->where('type', 'deposit')->sum('amount') - $items->where('type', 'withdraw')->sum('amount'),
                ];
            })->values();

            // ✅ Réponse JSON
            return response()->json([
                "status"           => 200,
                "from"             => $request['from'],
                "to"               => $request['to'],
                "message"          => "success",
                "error"            => null,
                "data"             => $paginated,
                "all_ids"          => $allIds,
                "totals_by_money"  => $totalsByMoney
            ]);

        } catch (Exception $ex) {
            return $this->errorResponse($ex->getMessage(), 500);
        }
    }

    /**
     * transactions list paginated by user
     */
    public function transactionsHistoryforSpecificMember(Request $request)
    {
        // 1. Dates par défaut si absentes
        if (empty($request->from) || empty($request->to)) {
            $request['from'] = date('Y-m-d');
            $request['to'] = date('Y-m-d');
        }

        // 2. Vérification du user_id
        if (empty($request->user_id)) {
            return $this->errorResponse('user not sent');
        }

        // 3. Vérification utilisateur et entreprise
        $actualuser = $this->getinfosuser($request->query('user_id'));
        if (!$actualuser) {
            return $this->errorResponse('unknown user');
        }

        $enterprise = $this->getEse($actualuser->id);
        if (!$enterprise) {
            return $this->errorResponse('unknown enterprise');
        }

        try {
            $query = wekaAccountsTransactions::query();

            // 4. Jointure si filtre sur monnaies
            if (!empty($request['moneys'])) {
                $query->join('wekamemberaccounts', 'weka_accounts_transactions.member_account_id', '=', 'wekamemberaccounts.id')
                    ->whereIn('wekamemberaccounts.money_id', $request['moneys']);
            }

            // 5. Filtrer par membre (user_id unique)
            $query->where('member_id', $request->user_id);

            // 6. Filtrer par période
            $query->whereBetween('done_at', [
                $request['from'] . ' 00:00:00',
                $request['to'] . ' 23:59:59'
            ]);

            // 7. Collecte de tous les IDs (clonage)
            $allIds = [];
            (clone $query)->select('weka_accounts_transactions.id')
                ->orderBy('weka_accounts_transactions.id')
                ->chunk(1000, function ($transactions) use (&$allIds) {
                    foreach ($transactions as $t) {
                        $allIds[] = $t->id;
                    }
                });

            // 8. Pagination
            $limit = $request->get('limit', 50);
            $paginated = $query->orderBy('done_at', 'desc')->paginate($limit);

            // 9. Transformation des résultats
            $data = $paginated->getCollection()->transform(fn($item) => $this->show($item));
            $paginated->setCollection($data);

            // 10. Totaux par monnaie
            $totalsByMoney = $data->groupBy('money_id')->map(function ($items, $money_id) {
                return [
                    'money_id'     => $money_id,
                    'abreviation'  => $items->first()['abreviation'] ?? '',
                    'total'        => $items->sum('amount'),
                    'total_in'     => $items->where('type', 'deposit')->sum('amount'),
                    'total_out'    => $items->where('type', 'withdraw')->sum('amount'),
                    'total_net'    => $items->where('type', 'deposit')->sum('amount') - $items->where('type', 'withdraw')->sum('amount'),
                ];
            })->values();

            // 11. Réponse
            return response()->json([
                "status" => 200,
                "message" => "success",
                "error" => null,
                "data" => $paginated,
                "all_ids" => $allIds,
                "totals_by_money" => $totalsByMoney
            ]);
            } catch (\Exception $ex) {
                return $this->errorResponse($ex->getMessage(), 500);
            }
    }

    /**
     * Dashboard mobile AT WEKA AKIBA
     */
    public function dashboardmobileatwekaakiba(Request $request){
         // return $request;
         $list=[];
         if(isset($request->from)==false && empty($request->from) && isset($request->to)==false && empty($request->to)){
             $request['from']= date('Y-m-d');
             $request['to']=date('Y-m-d');
         }
 
         if (isset($request->user_id)) {
             $actualuser=$this->getinfosuser($request->user_id);
             if ($actualuser) {
                 $ese=$this->getEse($actualuser->id);
                 if ($ese) {
                    $moneys=collect(moneys::where("enterprise_id",$ese['id'])->get());
                    //report for no super admin users
                    try {
                        $list1=collect(wekaAccountsTransactions::where('enterprise_id',$ese['id'])
                        ->where('user_id',$actualuser->id)
                        ->whereBetween('done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                        ->get());
                        $list=$list1->transform(function($item){
                            return $this->show($item);
                        });
            
                        
                        $sells=$moneys->transform(function ($money) use($request){
                            $invoices=Invoices::whereBetween('date_operation',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                            ->where('money_id','=',$money['id'])
                            ->where('type_facture','<>','proforma')
                            ->where('edited_by_id',$request->user_id)
                            ->get();
                            $money['totalsells']=$invoices->sum('netToPay');
                            return $money;
                        });
                
                        $moneysmises=collect(moneys::where("enterprise_id",$ese['id'])->get());
                        $mises=$moneysmises->transform(function ($money) use($request){
                            $transactions=wekaAccountsTransactions::join('wekamemberaccounts as WA','weka_accounts_transactions.member_account_id','WA.id')
                            ->join('moneys as M','WA.money_id','M.id')
                            ->whereBetween('weka_accounts_transactions.done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                            ->where('WA.money_id','=',$money['id'])
                            ->where('weka_accounts_transactions.user_id',$request->user_id)
                            ->get();
                
                            $money['totaltransactions']=$transactions->sum('amount');
                
                            return $money;
                        });

                        Carbon::setLocale('fr');
                        $actualmonth=Carbon::now()->translatedFormat('F Y');
                        $startOfMonth = Carbon::now()->startOfMonth(); // Début du mois
                        $endOfMonth = Carbon::now()->endOfMonth();     // Fin du mois

                        $moneysfirstentries=collect(moneys::where("enterprise_id",$ese['id'])->get());
                        $monthlyfirstentries=$moneysfirstentries->transform(function ($money) use($request,$startOfMonth,$endOfMonth){
                            $mouvements=wekafirstentries::whereBetween('done_at',[$startOfMonth.' 00:00:00', $endOfMonth.' 23:59:59'])
                            ->where('money_id','=',$money['id'])
                            ->where('collector_id',$request->user_id)
                            ->get();
                
                            $money['totalfirstentries']=$mouvements->sum('amount');
                
                            return $money;
                        });

                        $moneysmonthlysells=collect(moneys::where("enterprise_id",$ese['id'])->get());
                        $monthlysells=$moneysmonthlysells->transform(function ($money) use($request,$startOfMonth,$endOfMonth){
                            $invoices=Invoices::whereBetween('date_operation',[$startOfMonth.' 00:00:00', $endOfMonth.' 23:59:59'])
                            ->where('money_id','=',$money['id'])
                            ->where('type_facture','<>','proforma')
                            ->where('edited_by_id',$request->user_id)
                            ->get();
                            $money['totalsells']=$invoices->sum('netToPay');
                            return $money;
                        });
                    
                
                        return response()->json([
                            "status"=>200,
                            "message"=>"success",
                            "error"=>null,
                            "dailytransactions"=>$list,
                            "dailysells"=>$sells,
                            "dailymises"=>$mises,
                            "monthlyfirstentries"=>$monthlyfirstentries,
                            "monthlysells"=>$monthlysells,
                            "actualmonth"=>$actualmonth,
                            "startofthemonth" =>$startOfMonth->translatedFormat('d F Y'),
                            "endofthemonth" =>$endOfMonth->translatedFormat('d F Y'),
                        ]);
                    } catch (Exception $th) {
                        return response()->json([
                            "status"=>500,
                            "message"=>"error",
                            "error"=>$th->getMessage(),
                            "data"=>null
                        ]);
                    }
                 }else{
                     return response()->json([
                         "status"=>400,
                         "message"=>"error",
                         "error"=>"unknown enterprise",
                         "data"=>null
                     ]);
                 }
 
             }else{
                 return response()->json([
                     "status"=>400,
                     "message"=>"error",
                     "error"=>"unknown user",
                     "data"=>null
                 ]);
             }
         }
         else{
             return response()->json([
                 "status"=>400,
                 "message"=>"error",
                 "error"=>"user not sent",
                 "data"=>null
             ]);
         }
    }
    /**
     *Report transactions grouped by  
     */
    public function reportTransactionsgroupebBy(Request $request){
        try {
            switch ($request['criteria']) {
                case 'cashiers':
                    $list1=collect(wekaAccountsTransactions::where('enterprise_id',$request['enterprise_id'])
                    ->whereBetween('done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                    ->select('user_id')
                    ->groupBy('user_id')
                    ->get());
                    $list=$list1->transform(function($item) use($request){
                       $cashier=User::find($item['user_id']);

                      $transactions=collect(wekaAccountsTransactions::where('enterprise_id',$request['enterprise_id'])
                      ->where('user_id',$cashier->id)
                      ->whereBetween('done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                      ->get());
                      $transactions=$transactions->transform(function($transaction){
                          return $this->show($transaction);
                      });
                        $grouped =$transactions->groupBy('abreviation');
                        $grouped->all();
                      $cashier['transactions']=$transactions;
                      return $cashier;
                        // return $this->show($item);
                    });
        
                    return response()->json([
                        "status"=>200,
                        "message"=>"success",
                        "from"=>$request['from'],
                        "to"=>$request['to'],
                        "error"=>null,
                        "data"=>$list
                    ]);
                    break;
                    
                    case 'moneys':
                    $list1=collect(wekaAccountsTransactions::where('enterprise_id',$request['enterprise_id'])
                    ->whereBetween('done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                    ->select('user_id')
                    ->groupBy('user_id')
                    ->get());
                    $list=$list1->transform(function($item) use($request){
                       $cashier=User::find($item['user_id']);

                      $transactions=collect(wekaAccountsTransactions::where('enterprise_id',$request['enterprise_id'])
                      ->where('user_id',$cashier->id)
                      ->whereBetween('done_at',[$request['from'].' 00:00:00',$request['to'].' 23:59:59'])
                      ->get());
                      $transactions=$transactions->transform(function($transaction){
                          return $this->show($transaction);
                      });
                        $grouped =$transactions->groupBy('abreviation');
                        $grouped->all();
                      $cashier['transactions']=$transactions;
                      return $cashier;
                        // return $this->show($item);
                    });
        
                    return response()->json([
                        "status"=>200,
                        "message"=>"success",
                        "from"=>$request['from'],
                        "to"=>$request['to'],
                        "error"=>null,
                        "data"=>$list
                    ]);
                    break;
                
                default:
                    # code...
                    break;
            }
        } catch (Exception $th) {
            return response()->json([
                "status"=>500,
                "message"=>"error",
                "error"=>$th->getMessage(),
                "data"=>null
            ]);
        }
    }

    /**
     * Multiple Transactions update
     */
    public function updatetransactions(Request $request){
        $savedtransactions=[];
        if ($request['user']) {
            $actualuser=user::find($request['user']['id']); 
            if ($actualuser && $actualuser['user_type']=="super_admin") {
                if ($request['statusSent'] && filled($request['statusSent'])) {
                     try {
                            DB::beginTransaction();
                            foreach ($request['data'] as $transaction) {
                                $transactionupdated=wekaAccountsTransactions::find($transaction['id']);
                                $memberaccount=wekamemberaccounts::find($transactionupdated['member_account_id']);
                                if ($transactionupdated && $transactionupdated['transaction_status']="pending") {
                                    if ($memberaccount) {
                                        //if the account is enabled
                                        if($memberaccount->account_status=="enabled"){
                                            if ($transactionupdated['type']=="deposit") {
                                                $memberaccountupdated=$memberaccount;
                                                $memberaccountupdated->sold=$memberaccount->sold+$transactionupdated['amount'];
                                                $memberaccountupdated->save();
                                                //update transaction
                                                $transactionupdated['transaction_status']=$request['statusSent'];
                                                $transactionupdated->save();   
                                            }
                                        }else{
                                            $transactionupdated['message']="error";
                                            $transactionupdated['error']="account disabled";
                                        }
                                    }else{
                                        $transactionupdated['message']="error";
                                        $transactionupdated['error']="no account find";
                                    }
                                }else{
                                    $transactionupdated['message']="error";
                                    $transactionupdated['error']="transaction already validated";
                                }
                                
                                array_push($savedtransactions,$this->show($transactionupdated));
                            }
                            DB::commit();
                            return response()->json([
                                "status"=>200,
                                "message"=>"success",
                                "error"=>null,
                                "data"=>$savedtransactions
                            ]);
                        } catch (Exception $th) {
                            DB::rollBack();
                            //throw $th;
                            return response()->json([
                                "status"=>500,
                                "message"=>"error",
                                "error"=>$th->getMessage(),
                                "data"=>null
                            ]);
                        }
                }else{
                     return response()->json([
                        "status"=>402,
                        "message"=>"error",
                        "error"=>"no status sent",
                        "data"=>null
                  ]);  
                }
            }else{
                return response()->json([
                    "status"=>402,
                    "message"=>"error",
                    "error"=>"unauthorized user",
                    "data"=>null
                ]);   
            }
        }else{
            return response()->json([
                "status"=>402,
                "message"=>"error",
                "error"=>"unknown user",
                "data"=>null
            ]);   
        }
    }

     /**
     * Offline data gotten
     */
    public function syncing(Request $request){
        $datatoreturn = [];
        try {
            foreach ($request['offlinetransactions'] as  $value) {
               $newsync = $this->syncingstore(new Request($value));
                array_push($datatoreturn,$newsync);
            }

            return response()->json([
                "status"=>200,
                "message"=>"success",
                "error"=>null,
                "data"=>$datatoreturn
            ]); 
        } catch (Exception $th) {
            return response()->json([
                "status"=>500,
                "message"=>"error",
                "error"=>$th->getMessage(),
                "data"=>null
            ]);
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StorewekaAccountsTransactionsRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StorewekaAccountsTransactionsRequest $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return $this->errorResponse("Utilisateur non authentifié", 401);
            } 
            
            if ($user->status!=="enabled") {
                return $this->errorResponse("Vous êtes bloqué(e). Veuillez contacter votre administrateur système.", 401);
            }

            $accountId =$request['member_account_id'] ?? null;
            $type = $request['type'] ?? null;
            $amount = $request['amount'] ?? 0;
            $motif = $request['motif'] ?? '';
            $fundId = $request['fund_id'] ?? 0;
            $nature = $request['nature'] ?? null;

            if (!$type) {
                return $this->errorResponse("Vous devez envoyer un type.");
            } 
            
            if (!$nature) {
                return $this->errorResponse("Vous devez envoyer une nature.");
            }
            
            if (!$accountId || $accountId <= 0) {
                return $this->errorResponse("Vous devez sélectionner un compte à " . ($type === 'deposit' ? 'débiter' : 'créditer'));
            }

            if ($amount <= 0) {
                return $this->errorResponse("Le montant de l'opération doit être supérieur à 0");
            }

            if (strlen($motif) <= 2) {
                return $this->errorResponse("Veuillez fournir le motif svp!");
            }

            if ($type === 'deposit' && $fundId <= 0 && $nature==='cash_virtual') {
                return $this->errorResponse("Vous devez sélectionner une caisse");
            }

            $memberAccount = wekamemberaccounts::find($accountId);
            if (!$memberAccount) {
                return $this->errorResponse("Aucun compte trouvé");
            }

            if (!$memberAccount->isavailable()) {
                return $this->errorResponse("Le compte du membre est désactivé. Action non autorisée");
            }

               switch ($nature) {
                case 'cash_virtual':
                    return $this->cashToVirtual($request);
                    break;
                case 'account_account':
                    return $this->accountToAccount($request);
                    break;
                case 'tub_account':
                    $this->tubToAccount($request);
                    break; 
                case 'finance_withdrawal':
                    $this->financeWithdraw($request);
                    break; 
                // case 'mobile_money_account':
                //     $this->handleMobileMoneyAccount($request,$user);
                //     break;
                default:
                return $this->errorResponse("Type d'opération invalide");
            }

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function handleMobileMoneyAccount(Request $request){
        $user=Auth::user();
        $amount=$request['amount'];
        $motif=$request['motif'];
        $currency=$request['currency'] ?? null;
        $provider=$request['mobile_money_provider'] ?? null;
        $phone=$request['phone_number'] ?? null;
       
        if(!$user){
            return $this->errorResponse("Utilisateur non authentifié");
        }

        if(!$phone){
            return $this->errorResponse("Vous devez fournir une numero de telephone");
        } 
        
        if (!PhoneHelper::isValidPhone($phone)) {
            return $this->errorResponse("Numéro invalide.");
        }
        
        $country=PhoneHelper::getCountry($phone);
        if(!$currency){
            return $this->errorResponse("Vous devez fournir une devise");
        } 
        
        if(!$amount || $amount<=0){
            return $this->errorResponse("Vous devez fournir un montant svp");
        } 
        
        if(!$provider){
            return $this->errorResponse("Vous devez fournir un fournisseur");
        }

        $providerFind=MobileMoneyProviders::find($provider);
        
        if(!$providerFind){
            return $this->errorResponse("Fournisseur introuvable");
        }

        if(!$providerFind->isavailable()){
            return $this->errorResponse("Fournisseur désactivé");
        }

        if(!$country!==$providerFind->country){
            return $this->errorResponse("Le numero n'est pas du pays configuré");
        }

        $memberAccount=wekamemberaccounts::findByMemberAndCurrency($user,$currency);
        if(!$memberAccount){
            return $this->errorResponse("Compte du membre introuvable");
        }

        if(!$memberAccount->isavailable()){
            return $this->errorResponse("Compte du membre désactivé");
        }

        try{
            DB::beginTransaction();
            $memberAccountSoldBefore=$memberAccount->sold;
            $memberAccount->sold=$memberAccount->sold+$amount;
            $memberAccountSoldAfter=$memberAccount->sold;
            $memberAccount->save();
            $transaction=$this->createTransaction(
                $amount,
                $memberAccountSoldBefore,
                $memberAccountSoldAfter,
                "entry",
                $motif,
                $user->id,
                $memberAccount->id,
                $memberAccount->user_id,
                null,
                $user->full_name?$user->full_name:$user->user_name,
                0,
                $phone?? null,
                $user->adress?? null
            );
            DB::commit();
            return $this->successResponse('success',$this->show($transaction));
        }catch (\Exception $e){
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    private function handleDeposit($request, $memberAccount, $user)
    {
        $soldBefore = $memberAccount->sold;
        $uuid = $request['uuid'] ?? $this->getUuId('WEKA', 'OP');

        DB::beginTransaction();
        try {
            // Vérifie doublon UUID
            if (wekaAccountsTransactions::where('uuid', $uuid)->exists()) {
                DB::rollBack();
                return $this->errorResponse("UUID déjà utilisé");
            }


            $nature = $request->input('nature');
            if (empty($nature) || $nature === 'null') {
                $nature = 'cash_virtual';
            }

            switch ($nature) {
                case 'cash_virtual':
                    return $this->cashToVirtual($request);
                    break;
                case 'account_account':
                    return $this->accountToAccount($request);
                    break;
                case 'tub_account':
                    $this->tubToAccount($request);
                    break; 
                case 'mobile_money_account':
                    break;
                default:
                return $this->errorResponse("Type d'opération invalide");
            }
            // Création de la transaction
            $transaction = wekaAccountsTransactions::create([
                'amount' => $request['amount'],
                'sold_before' => $soldBefore,
                'sold_after' => $memberAccount->sold,
                'type' => 'deposit',
                'motif' => $request['motif'],
                'user_id' => $user->id,
                'member_account_id' => $memberAccount->id,
                'member_id' => $memberAccount->user_id,
                'enterprise_id' => $memberAccount->enterprise_id,
                'done_at' => $request['done_at'] ?? date('Y-m-d'),
                'account_id' => $request['account_id'],
                'operation_done_by' => $user->user_name ?? $user->full_name,
                'uuid' => $uuid,
                'fees' => $request['fees'] ?? 0,
                'transaction_status' => $request['transaction_status'] ?? 'pending',
                'phone' => $request['phone'] ?? null,
                'adresse' => $request['adresse'] ?? null,
            ]);

            DB::commit();
            return $this->successResponse("success", $this->show($transaction));

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 500);
        }
    }


private function financeWithdraw($request)
{
    $user=Auth::user();
    $accountId =$request['member_account_id'] ?? null;
    $amount=$request['amount'];
    $motif=$request['motif'];

    if(!$accountId){
        return $this->errorResponse("Identifiant du Compte introuvable");
    }

    $memberAccount=wekamemberaccounts::find($accountId);

    if(!$memberAccount){
        return $this->errorResponse("Compte du membre introuvable");
    }

    if(!$memberAccount->isavailable()){
        return $this->errorResponse("Compte du membre désactivé");
    }

    if (!$request->filled('fund_id')) {
        return $this->errorResponse("Identifiant de la caisse requis");
    }

    $fund =funds::find($request['fund_id']);
    if (!$fund) {
        return $this->errorResponse("caisse introuvable");
    } 
        
    if (!$fund->isavailable()) {
        return $this->errorResponse("caisse désactivée!");
    }

    if(!$fund->canMakeOperation($user)){
        return $this->errorResponse("Vous n'êtes pas autorisé à effectuer des opérations sur cette caisse.");
    }

    if(!$fund->haveTheSameMoneyWith($memberAccount->money_id)){
        return $this->errorResponse("la caisse et le compte n'ont pas la même monnaie.");
    }
   
    DB::beginTransaction();
    try {
        // Mise à jour du solde
        $fees=transactionfee::calculateFee($amount,$memberAccount->money_id,'withdraw');
        if(!$fees){
          return $this->errorResponse("Aucun frais de retrait configuré. Veuillez contacter l'admin Système."); 
        }

        if(($amount+$fees['fee']) > $memberAccount->sold){
            return $this->errorResponse("Solde du compte membre insuffisant pour effectuer cette opération.");  
        }

        $memberSoldBefore=$memberAccount->sold;
        $memberAccount->sold = $memberAccount->sold - ($amount+$fees['fee']);
        $memberSoldAfter=$memberAccount->sold;
        $memberAccount->save();
        $transactionMemberAccount =$this->createTransaction(
            ($amount+$fees['fee']),
            $memberSoldBefore,
            $memberSoldAfter,
            "withdraw",
            $motif,
            $user->id,
            $memberAccount->id,
            $memberAccount->user_id,
            null,
            $request['operation_done_by'] ?? null,
            $fees['percent'] ?? 0,
            $request['phone'] ?? null,
            $request['adresse'] ?? null);

        if($fund->sold<$amount){
            return $this->errorResponse("Solde de la caisse insuffisant pour effectuer cette opération."); 
        }

        $fund->sold=$fund->sold-$amount;
        $fundSoldBefore=$fund->sold;
        $fund->save();
         // Création historique transaction pour la caisse
        $fundRequestHistory=$this->createLocalRequestHistory(
            $user->id,
            $fund->id,
            $fees['fee'],
            $motif,
            'withdraw',
            null,
            null,
            null,
            $fund->sold,
            null,
            $fund->description,
            $memberAccount->description,
            null,
            null,
            $memberAccount->id,
            'withdrawal'
        );

        $automatiFund =funds::getAutomaticFund($memberAccount->money_id);
        if (!$automatiFund) {
            DB::rollBack();
            return $this->errorResponse("Impossible de terminer l'action. Aucune caisse configurée pour les commissions!");
        }

         $automatiFund->sold = $automatiFund->sold+$fees['fee'];
         $automatiFundSold= $automatiFund->sold;
         $automatiFund->save();
        
         $AutomaticFundRequest=$this->createLocalRequestHistory(
            $user->id,
            $automatiFund->id,
            $fees['fee'],
            "Frais de retrait. ".$request['motif'],
            'withdraw',
            null,
            null,
            null,
            $automatiFund->sold,
            null,
            "WEKA AKIBA SYSTEM",
            $memberAccount->description,
            null,
            null,
            $memberAccount->id,
            'approvment'
        );

        DB::commit();
        return $this->successResponse("success", $this->show($transactionMemberAccount));

    } catch (\Exception $e) {
        DB::rollBack();
        return $this->errorResponse($e->getMessage(), 500);
    }
}

private function accountToTub(Request $request){
    $user=Auth::user();
    $accountId =$request['member_account_id'] ?? null;
    $amount=$request['amount'];
    $motif=$request['motif'];

    if(!$accountId){
        return $this->errorResponse("Identifiant du Compte introuvable");
    }

    $memberAccount=wekamemberaccounts::find($accountId);

    if(!$memberAccount){
        return $this->errorResponse("Compte du membre introuvable");
    }

    if(!$memberAccount->isavailable()){
        return $this->errorResponse("Compte du membre désactivé");
    }

    if (!$request->filled('fund_id')) {
        return $this->errorResponse("Identifiant de la caisse requis");
    }

    $fund =funds::find($request['fund_id']);
    if (!$fund) {
        return $this->errorResponse("caisse introuvable");
    } 
        
    if (!$fund->isavailable()) {
        return $this->errorResponse("caisse désactivée!");
    }

    if(!$fund->canMakeOperation($user)){
        return $this->errorResponse("Vous n'êtes pas autorisé à effectuer des opérations sur cette caisse.");
    }

    if(!$fund->haveTheSameMoneyWith($memberAccount->money_id)){
        return $this->errorResponse("la caisse et le compte n'ont pas la même monnaie.");
    }

    try{
        DB::beginTransaction();
        $fund->sold = $fund->sold + $amount;
        $fund->save();
        $makeHistory=$this->createLocalRequestHistory(
            $user->id,
            $fund->id,
            $amount,
            $motif,
            'entry',
            null,
            null,
            null,
            $fund->sold,
            null,
            $user->full_name??$user->user_name,
            $fund->description,
            null,
            null,
            $memberAccount->id,
            'approvment'
        );
        $memberAccountSoldBefore=$memberAccount->sold;
        $memberAccount->sold=$memberAccount->sold+$amount;
        $memberAccountSoldAfter=$memberAccount->sold;
        $memberAccount->save();
        $transaction=$this->createTransaction(
            $amount,
            $memberAccountSoldBefore,
            $memberAccountSoldAfter,
            "entry",
            $motif,
            $user->id,
            $memberAccount->id,
            $memberAccount->user_id,
            null,
            $user->full_name?$user->full_name:$user->user_name,
            0,
            $user->user_phone?? null,
            $user->adress?? null
        );
        DB::commit();
        return $this->successResponse('success',$this->show($transaction));
    }catch (\Exception $e){
        DB::rollBack();
        return $this->errorResponse($e->getMessage(), 500);
    } 
}

private function cashToVirtual(Request $request){
    $user=Auth::user();
    $accountId =$request['member_account_id'] ?? null;
    $amount=$request['amount'];
    $motif=$request['motif'];

    if(!$accountId){
        return $this->errorResponse("Identifiant du Compte introuvable");
    }

    $memberAccount=wekamemberaccounts::find($accountId);

    if(!$memberAccount){
        return $this->errorResponse("Compte du membre introuvable");
    }

    if(!$memberAccount->isavailable()){
        return $this->errorResponse("Compte du membre désactivé");
    }

    if (!$request->filled('fund_id')) {
        return $this->errorResponse("Identifiant de la caisse requis");
    }

    $fund =funds::find($request['fund_id']);
    if (!$fund) {
        return $this->errorResponse("caisse introuvable");
    } 
        
    if (!$fund->isavailable()) {
        return $this->errorResponse("caisse désactivée!");
    }

    if(!$fund->canMakeOperation($user)){
        return $this->errorResponse("Vous n'êtes pas autorisé à effectuer des opérations sur cette caisse.");
    }

    if(!$fund->haveTheSameMoneyWith($memberAccount->money_id)){
        return $this->errorResponse("la caisse et le compte n'ont pas la même monnaie.");
    }

    try{
        DB::beginTransaction();
        $fund->sold = $fund->sold + $amount;
        $fund->save();
        $makeHistory=$this->createLocalRequestHistory(
            $user->id,
            $fund->id,
            $amount,
            $motif,
            'entry',
            null,
            null,
            null,
            $fund->sold,
            null,
            $user->full_name??$user->user_name,
            $fund->description,
            null,
            null,
            $memberAccount->id,
            'approvment'
        );
        $memberAccountSoldBefore=$memberAccount->sold;
        $memberAccount->sold=$memberAccount->sold+$amount;
        $memberAccountSoldAfter=$memberAccount->sold;
        $memberAccount->save();
        $transaction=$this->createTransaction(
            $amount,
            $memberAccountSoldBefore,
            $memberAccountSoldAfter,
            "entry",
            $motif,
            $user->id,
            $memberAccount->id,
            $memberAccount->user_id,
            null,
            $user->full_name?$user->full_name:$user->user_name,
            0,
            $user->user_phone?? null,
            $user->adress?? null
        );
        DB::commit();
        return $this->successResponse('success',$this->show($transaction));
    }catch (\Exception $e){
        DB::rollBack();
        return $this->errorResponse($e->getMessage(), 500);
    } 
}

private function tubToAccount(Request $request){
    $user=Auth::user();
    $amount = $request['amount'] ?? 0;
    $accountId =$request['member_account_id'] ?? null;
     $motif = $request['motif'] ?? '';
    
    if(!$accountId){
        return $this->errorResponse("Identifiant du compte membre requis");
    }
    $memberAccount=wekamemberaccounts::find($accountId);

    if (!$memberAccount) {
        return $this->errorResponse("compte du membre introuvable");   # code...
    }

    if (!$request->filled('fund_id')) {
        return $this->errorResponse("Identifiant de la caisse requis");
    }

    $fund =funds::find($request['fund_id']);

    if (!$fund) {
        return $this->errorResponse("caisse introuvable");
    } 
        
    if (!$fund->isavailable()) {
        return $this->errorResponse("caisse désactivée!");
    }

    if(!$fund->canMakeOperation($user)){
        return $this->errorResponse("Vous n'êtes pas autorisé à effectuer des opérations sur cette caisse.");
    }

    if(!$fund->haveTheSameMoneyWith($memberAccount->money_id)){
        return $this->errorResponse("la caisse et le compte n'ont pas la même monnaie.");
    } 
    
    if ($amount <= 0) {
        return $this->errorResponse("Le montant de l'opération doit être supérieur à 0.");
    }
     
    if (strlen($motif) <= 2) {
        return $this->errorResponse("Veuillez fournir le motif svp!");
    }

    $fund->sold= $fund->sold - $amount;
    $fund->save();
    $makeHistory=$this->createLocalRequestHistory(
        $user->id,
        $fund->id,
        $request['amount'],
        $request['motif'],
        'entry',
        null,
        null,
        null,
        $fund->enterprise_id,
        $fund->sold,
        date('Y-m-d'),
        null,
        'validated',
        $user->full_name??$user->user_name,
        $user->full_name??$user->user_name,
        $this->getUuId('RH','FH'),
        null,
        null,
        $memberAccount->id,
        'approvment');

    $memberAccountsoldbefore=$memberAccount->sold;
    $memberAccount->sold=$memberAccount->sold+$amount;
    $memberAccount->save();
    $memberAccountsoldafter=$memberAccount->sold;   
        
    $this->createTransaction(
        $amount,
        $memberAccountsoldbefore,
        $memberAccountsoldafter,
        'withdraw',
        $motif,
        $user->id,
        $memberAccount->id,
        $memberAccount->user_id,
        null,
        $user->full_name?$user->full_name:$user->user_name,
        0,
        $user->user_phone?? null,
        $user->adress?? null
    );
   return $this->successResponse('success',$this->show($makeHistory));
}

private function accountToAccount(Request $request){
    $user=Auth::user();
    $accountSource=$request['member_account_id'] ?? null;
    $accountBeneficiary=$request['beneficiary_account_id'] ?? null;
    $amount = $request['amount'] ?? 0;
    $totalAmount=$amount;
    $motif = $request['motif'] ?? '';
    $payment=0;

    if (!$accountSource || $accountSource <= 0) {
        return $this->errorResponse("Vous devez sélectionner un compte source.");
    } 
    
    if (!$accountBeneficiary || $accountBeneficiary <= 0) {
        return $this->errorResponse("Vous devez sélectionner un compte bénéficiaire.");
    }

    if ($amount <= 0) {
        return $this->errorResponse("Le montant de l'opération doit être supérieur à 0.");
    }

    if (strlen($motif) <= 2) {
        return $this->errorResponse("Veuillez fournir le motif svp!");
    }

    $sourceMemberAccount = wekamemberaccounts::find($accountSource);
    if (!$sourceMemberAccount) {
        return $this->errorResponse("Aucun compte source trouvé");
    }

    if (!$sourceMemberAccount->isavailable()) {
        return $this->errorResponse("Action sur le compte source non autorisée.");
    }

    $beneficiaryMemberAccount = wekamemberaccounts::find($accountBeneficiary);
    if (!$beneficiaryMemberAccount) {
        return $this->errorResponse("Aucun compte bénéficiaire trouvé.");
    }

    if (!$beneficiaryMemberAccount->isavailable()) {
        return $this->errorResponse("Action sur le compte bénéficiaire non autorisée.");
    }

    if($beneficiaryMemberAccount->money_id!==$sourceMemberAccount->money_id){
        return $this->errorResponse("Les deux comptes n'utilisent pas la même monnaie.");
    }

    if($sourceMemberAccount->sold<$totalAmount){
        return $this->errorResponse("Votre solde est insuffisant pour effectuer cette opération.");
    }

    // if ($user->collector) {
    //     if(!$user->collection_percentage || $user->collection_percentage<=0){
    //         return $this->errorResponse("Aucun pourcentage de commission configuré pour le collecteur."); 
    //     }
    //     $fees=transactionfee::calculateFee($amount,$sourceMemberAccount->money_id,'withdraw');
    //     $payment=($fees['fee']*$user->collection_percentage)/100;
    //     //introduce sauvegarde des commissions recues ici...
    // }
   $sourceSoldBefore=$sourceMemberAccount->sold;
   $sourceMemberAccount->sold = $sourceMemberAccount->sold - $totalAmount;
   $sourceSoldAfter= $sourceMemberAccount->sold;
   $sourceMemberAccount->save();
   $sourceTransaction=$this->createTransaction(
    $totalAmount,
    $sourceSoldBefore,
    $sourceSoldAfter,
    "withdraw",
    $motif,
    $user->id,
    $sourceMemberAccount->id,
    $sourceMemberAccount->user_id,
    null,
    $user->full_name?$user->full_name:$user->user_name,
    $payment,
    $user->user_phone?? null,
    $user->adress?? null
   );

   $beneficiarySoldBefore=$beneficiaryMemberAccount->sold;
   $beneficiaryMemberAccount->sold = $beneficiaryMemberAccount->sold + $totalAmount;
   $beneficiarySoldAfter=$beneficiaryMemberAccount->sold;
   $beneficiaryMemberAccount->save();
    $this->createTransaction(
    $totalAmount,
    $beneficiarySoldBefore,
    $beneficiarySoldAfter,
    "entry",
    $motif,
    $user->id,
    $beneficiaryMemberAccount->id,
    $beneficiaryMemberAccount->user_id,
    null,
    $user->full_name?$user->full_name:$user->user_name,
    $payment,
    $user->user_phone?? null,
    $user->adress?? null
   );
   return $this->successResponse('success',$this->show($sourceTransaction));
}

private function createTransaction($amount,$soldBefore,$soldAfter,$type,$motif,$userId,$memberAccountId,$memberId,$accountId,$operationDoneBy,$fees,$phone,$adresse){
    return wekaAccountsTransactions::create([
        'amount' => $amount,
        'sold_before' => $soldBefore,
        'sold_after' => $soldAfter,
        'type' => $type,
        'motif' => $motif,
        'user_id' => $userId,
        'member_account_id' => $memberAccountId,
        'member_id' => $memberId,
        'enterprise_id' =>$this->getEse($userId)['id'],
        'done_at' =>date('Y-m-d'),
        'account_id' => $accountId,
        'operation_done_by' =>$operationDoneBy,
        'uuid' => $this->getUuId('WT','WK'),
        'fees' =>$fees,
        'transaction_status' =>'validated',
        'phone' => $phone,
        'adresse' => $adresse,
    ]); 
}

    private function createLocalRequestHistory($userId,$fundId,$amount,$motif,$type,$requestId,$fenceId,$invoiceId,$sold,$accountId,$beneficiary,$provenance,$fendReceiverId,$expenditureId,$memberAccountId,$nature){
        return  requestHistory::create([
            'user_id'=>$userId,
            'fund_id'=>$fundId,
            'amount'=>$amount,
            'motif'=>$motif,
            'type'=>$type,
            'request_id'=>$requestId,
            'fence_id'=>$fenceId,
            'invoice_id'=>$invoiceId,
            'enterprise_id'=>$this->getEse($userId)['id'],
            'sold'=>$sold,
            'done_at'=>date('Y-m-d'),
            'account_id'=>$accountId,
            'status'=>'validated',
            'beneficiary'=>$beneficiary,
            'provenance'=>$provenance,
            'uuid'=>$this->getUuId('RH','FH'),
            'fund_receiver_id'=>$fendReceiverId,
            'expenditure_id'=>$expenditureId,
            'member_account_id'=>$memberAccountId,
            'nature'=>$nature,
        ]);
    }
    /**
     * transaction resume before validate
     */
    public function transactionResumeBeforeValidate(Request $request){
        try {
            //member_id, member_account_id, amount,withdraw_mode,agent_id,mobile_provider_id
            if ($request['withdraw_mode']=='agent') {
                return $this->transactionResumeByAgentMode($request);
            }

            if ($request['withdraw_mode']=='mobile_money') {
                return $this->transactionResumeByMobileMoneyMode($request);
            }
            
            if ($request['withdraw_mode']!=='mobile_money' && $request['withdraw_mode']!=='agent') {
                return $this->errorResponse('withdraw mode not supported');
            }
        } catch (\Exception $th) {
           return $this->errorResponse($th->getMessage(),500);
        }
    }

    private function transactionResumeByMobileMoneyMode(Request $request){
          try {
            $mobilemoneyprovider=MobileMoneyProviders::find($request['mobile_provider_id']);
                if (!$mobilemoneyprovider) {
                    return $this->errorResponse('mobile provider not found');
                }

                if(!$mobilemoneyprovider->status || $mobilemoneyprovider->status!=='enabled'){
                    return $this->errorResponse('mobile provider not enabled');
                }
                $memberaccount=wekamemberaccounts::find($request['member_account_id']);
                $enterprise=$this->getEse($request['member_id']);
                if (!$enterprise) {
                    return $this->errorResponse('enterprise not found');
                }

                $member=$this->getinfosuser($request['member_id']);
                if (!$member) {
                return $this->errorResponse('member not found');
                }

                if ($member['status']!=='enabled') {
                    return $this->errorResponse('member disabled');
                }
                 if (!$member->can_withdraw_on_mobile) {
                    return $this->errorResponse('the member cannot withdraw via mobile money');
                }

                $memberaccount=wekamemberaccounts::find($request['member_account_id']);
                if (!$memberaccount) {
                    return $this->errorResponse('member account not found');
                }  
                
                $memberaccountavailable=$memberaccount->isavailable($request['member_account_id']);
                if (!$memberaccountavailable) {
                    return $this->errorResponse('account not available for transaction');
                }
                //withdrawal fees
                $fees=0;
                //withdraw_mode
                $feesConfigurations=serdipays::where('enterprise_id',$enterprise->id)->first();
                if (!$feesConfigurations) {
                    return $this->errorResponse('fees setting not set');
                }
                
                $fees +=$feesConfigurations->b2c_fees+$feesConfigurations->additional_fees;
            
                //amount comparison
                $coast=(($request['amount']*$fees)/100);
                $amountwithdraw=$request['amount']+$coast;
                if ($amountwithdraw > $memberaccount->sold) {
                    return $this->errorResponse('amount exceeds member account sold');
                }
                if ($request['test']==='test') {
                        $providerFields = $mobilemoneyprovider->getSelectedFields(['path']);
                        $accountFields = $memberaccount->getSelectedFields();
                        return response()->json([
                            "status" => 200,
                            "message" => "success",
                            "error" => null,
                            "data" => [
                                'mobile_provider_name' => $providerFields->get('name'),
                                'mobile_provider_provider' => $providerFields->get('provider'),
                                'mobile_provider_logo_path' => $providerFields->get('path'),

                                'amountwithdraw' => $amountwithdraw,
                                'coast' => $coast,
                                'account_sold_after_operation' => $memberaccount->sold - $amountwithdraw,
                                'fees' => $fees,

                                'member_id' => $request->member_id,
                                'member_account_id' => $request->member_account_id,
                                'account_description' => $accountFields->get('description'),
                                'account_number' => $accountFields->get('account_number'),
                                'account_status' => $accountFields->get('account_status'),
                                'amount' => $request->amount,
                                'withdraw_mode' => $request->withdraw_mode,
                                'mobile_provider_id' => $request->mobile_provider_id,
                                'test' => $request->test,

                                'money' => $memberaccount->money->abreviation ?? null,
                            ]
                        ]);

                }else if($request['test']==='validation'){
                  
                  return  $this->store(new StorewekaAccountsTransactionsRequest([
                            'amount'=>$amountwithdraw,
                            'sold_before'=>0,
                            'sold_after'=>0,
                            'type'=>'withdraw',
                            'motif'=>"Retrait via ".$mobilemoneyprovider->name,
                            'user_id'=>$memberaccount->user_id,
                            'member_account_id'=>$memberaccount->id,
                            'member_id'=>$memberaccount->user_id,
                            'enterprise_id'=>$enterprise->id,
                            'done_at'=>date('Y-m-d'),
                            'operation_done_by'=>$member->full_name?$member->full_name:$member->name,
                            'uuid'=>$this->getUuId('WEKA','OP'),
                            'fees'=>$coast,
                            'transaction_status'=>'validated',
                            'phone'=>$member->user_phone?$member->user_phone:null,
                            'adresse'=>$member->adress?$member->adress:null
                    ]));
                   
                }else{
                    return $request->all();
                }
        } catch (\Exception $th) {
           return $this->errorResponse($th->getMessage(),500);
        }
    }

    private function transactionResumeByAgentMode(Request $request){
        try {
            //member_id, member_account_id,amount,withdraw_mode,agent_id
            if (isset($request['member_id']) && isset($request['agent_id']) && isset($request['member_account_id']) && isset($request['amount']) && isset($request['withdraw_mode'])) { 
                $sameEnterprise=$this->usersInSameEnterprise($request['member_id'],$request['agent_id']);
                if (!$sameEnterprise) {
                    return $this->errorResponse(`member and agent don't have the same enterprise`);
                }

                $agent=$this->getinfosuser($request['agent_id']);
                if (!$agent) {
                    return $this->errorResponse('agent not found');
                }

                if ($agent['status']!=='enabled') {
                    return $this->errorResponse('agent disabled');
                }
                
                if (!$agent['collector']){
                    return $this->errorResponse('agent not a collector');
                }

                $memberaccount=wekamemberaccounts::find($request['member_account_id']);

                $agentaccount=wekamemberaccounts::with('money')->where('user_id',$agent->id)
                ->where('money_id',$memberaccount->money_id)
                ->first();

                if (!$agentaccount) {
                    return $this->errorResponse('agent account not found');
                }
                
                if ($request['withdraw_mode']!=='agent') {
                    return $this->errorResponse('withdraw mode not supported');
                }

                $enterprise=$this->getEse($request['member_id']);
                if (!$enterprise) {
                    return $this->errorResponse('enterprise not found');
                }

                $member=$this->getinfosuser($request['member_id']);
                if (!$member) {
                    return $this->errorResponse('member not found');
                }

                if ($member['status']!=='enabled') {
                    return $this->errorResponse('member disabled');
                } 
                
                if (!$member['can_withdraw_by_agent']) {
                    return $this->errorResponse('the member cannot withdraw via agent');
                }

                $memberaccount=wekamemberaccounts::find($request['member_account_id']);
                if (!$memberaccount) {
                    return $this->errorResponse('member account not found');
                }

                $memberaccountavailable=$memberaccount->isavailable($request['member_account_id']);
                if (!$memberaccountavailable) {
                    return $this->errorResponse('account not available for transaction');
                }

                //withdrawal fees
                $fees=0;
                //withdraw_mode
                 $feesConfigurations=serdipays::where('enterprise_id',$enterprise->id)->first();
                if (!$feesConfigurations) {
                    return $this->errorResponse('mobile provider not set');
                }
                
                $fees +=$feesConfigurations->withdraw_by_agent_fees+$feesConfigurations->additional_fees;
                //amount comparison
                $coast=(($request['amount']*$fees)/100);
                $amountwithdraw=$request['amount']+$coast;
                if ($amountwithdraw > $memberaccount->sold) {
                    return $this->errorResponse('amount exceeds member account sold');
                }

                if ($request['test']==='test') {
                     return response()->json([
                        "status"=>200,
                        "message"=>"success",
                        "error"=>null,
                        "data"=>[
                            'agent'=>$agent?$agent->getSelectedFields([]):null,
                            'fees'=>$fees,
                             'account_description' => $memberaccount->getSelectedFields([])->get('description'),
                            'account_number' => $memberaccount->getSelectedFields([])->get('account_number'),
                            'account_status' => $memberaccount->getSelectedFields([])->get('account_status'),
                            'coast'=>$coast,
                            'member_id'=>$request->member_id,
                            'member_account_id'=>$request->member_account_id,
                            'amount'=>$request->amount,
                            'withdraw_mode'=>$request->withdraw_mode,
                            'agent_id'=>$request->agent_id,
                            'test'=>$request->test,
                            'amountwithdraw'=>$amountwithdraw,
                            'account_sold_after_operation'=>$memberaccount->sold-$amountwithdraw,
                            'money'=>$memberaccount->money->abreviation,
                        ]
                    ]);
                }else if($request['test'==='validation']){
                     
                    try {
                        DB::beginTransaction();

                        $withdrawmember = $this->store(new StorewekaAccountsTransactionsRequest([
                            'amount' => $amountwithdraw,
                            'sold_before' => 0,
                            'sold_after' => 0,
                            'type' => 'withdraw',
                            'motif' => "Retrait via agent " . ($agent->full_name ?? $agent->name),
                            'user_id' => $memberaccount->user_id,
                            'member_account_id' => $memberaccount->id,
                            'member_id' => $memberaccount->user_id,
                            'enterprise_id' => $enterprise->id,
                            'done_at' => date('Y-m-d'),
                            'operation_done_by' => $member->full_name ?? $member->name,
                            'uuid' => $this->getUuId('WEKA', 'OP'),
                            'fees' => $coast,
                            'transaction_status' => 'validated',
                            'phone' => $member->user_phone ?? null,
                            'adresse' => $member->adress ?? null
                        ]));

                        if ($withdrawmember['status'] === 200) {
                            $agentdeposit = $this->store(new StorewekaAccountsTransactionsRequest([
                                'amount' => $amountwithdraw - $coast,
                                'sold_before' => 0,
                                'sold_after' => 0,
                                'type' => 'deposit',
                                'motif' => "Retrait du membre " . ($member->full_name ?? $member->name),
                                'user_id' => $memberaccount->user_id,
                                'member_account_id' => $agentaccount->id,
                                'member_id' => $agentaccount->user_id,
                                'enterprise_id' => $enterprise->id,
                                'done_at' => date('Y-m-d'),
                                'operation_done_by' => $member->full_name ?? $member->name,
                                'uuid' => $this->getUuId('WEKA', 'OP'),
                                'fees' => $coast,
                                'transaction_status' => 'validated',
                                'phone' => $member->user_phone ?? null,
                                'adresse' => $member->adress ?? null
                            ]));

                            DB::commit();
                            return $this->successResponse("success",$withdrawmember);
                        } else {
                            DB::rollBack();
                            return $this->errorResponse("withdraw fails. transaction cancelled.", 500);
                        }
                    } catch (Exception $th) {
                        DB::rollBack();
                        return $this->errorResponse($th->getMessage(), 500);
                    }

                }else{
                    return $this->errorResponse('missing required fields');
                }
            }else{
                return $this->errorResponse('missing required fields');
            }
                
        } catch (\Exception $th) {
           return $this->errorResponse($th->getMessage(),500);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StorewekaAccountsTransactionsRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function syncingstore(Request $request)
    {
        $soldbefore=0;
        $soldafter=0;
        // dump($request);
        //if exist the account and not suspended
        if ($request['member_account_id']) {
            //looking for the account
            $memberaccount=wekamemberaccounts::find($request['member_account_id']);
            if ($memberaccount) {
                //if the account is enabled
                if($memberaccount->account_status=="enabled"){
                    //if withdraw test the sold before making request
                    $soldbefore=$memberaccount->sold;
                    if ($request['type']=='withdraw') {
                        //verify the sold vis the amount sent
                        if ($memberaccount->sold>=$request['amount']) {
                            //begin transaction
                            DB::beginTransaction();
                            try {
                                $memberaccountupdated=$memberaccount;
                                $memberaccountupdated->sold=$memberaccount->sold-$request['amount'];
                                if ($request['transaction_status']=='validated') {
                                    $memberaccountupdated->save(); # code...
                                }
                                $ifexistsuuid=wekaAccountsTransactions::where('uuid',$request['uuid'])->get();
                                if (($ifexistsuuid->count())>0) {
                                    $request['error']="uuid duplicated";
                                    $request['message']="error";
                                    // $ifexistsuuid[]
                                    return $request->all();
                                }

                                $savewithdrawtransaction=wekaAccountsTransactions::create([
                                    'amount'=>$request['amount'],
                                    'sold_before'=>$soldbefore,
                                    'sold_after'=> $memberaccountupdated->sold,
                                    'type'=>$request['type'],
                                    'motif'=>$request['motif'],
                                    'user_id'=>$request['user_id'],
                                    'member_account_id'=>$memberaccount->id,
                                    'member_id'=>$memberaccount->user_id,
                                    'enterprise_id'=>$memberaccount->enterprise_id,
                                    'done_at'=>$request['done_at']?$request['done_at']:date('Y-m-d'),
                                    'account_id'=>$request['account_id'],
                                    'operation_done_by'=>$request['operation_done_by'],
                                    'uuid'=>$request['uuid']?$request['uuid']:$this->getUuId('WEKA','OP'),
                                    'fees'=>$request['fees']
                                ]);
                                DB::commit();
                                $original=$this->show($savewithdrawtransaction);
                                $original['error']=null;
                                $original['message']="success";
                                return $original;
                            } catch (Exception $th) {
                                DB::rollBack();
                                //throw $th;
                                $request['error']=$th->getMessage();
                                $request['message']="error";
                                return $request->all();
                            }
                        }else {
                            $request['error']="sold not enough";
                            $request['message']="error";
                            return $request->all();
                        }
                    }

                    //if is entry
                    if ($request['type']=='deposit') {
                            //begin transaction
                            DB::beginTransaction();
                            try {
                                $memberaccountupdated=$memberaccount;
                                $memberaccountupdated->sold=$memberaccount->sold+$request['amount'];

                                if ($request['transaction_status']=='validated') {
                                    $memberaccountupdated->save(); 
                                }

                                $ifexistsuuid=wekaAccountsTransactions::where('uuid',$request['uuid'])->get();
                                if (($ifexistsuuid->count())>0) {
                                    $request['error']="uuid duplicated";
                                    $request['message']="error";
                                    return $request->all();
                                }
                               
                                $savewithdrawtransaction=wekaAccountsTransactions::create([
                                    'amount'=>$request['amount'],
                                    'sold_before'=>$soldbefore,
                                    'sold_after'=> $memberaccountupdated->sold,
                                    'type'=>$request['type'],
                                    'motif'=>$request['motif'],
                                    'user_id'=>$request['user_id'],
                                    'member_account_id'=>$memberaccount->id,
                                    'member_id'=>$memberaccount->user_id,
                                    'enterprise_id'=>$memberaccount->enterprise_id,
                                    'done_at'=>$request['done_at']?$request['done_at']:date('Y-m-d'),
                                    'account_id'=>$request['account_id'],
                                    'operation_done_by'=>$request['operation_done_by'],
                                    'uuid'=>$request['uuid']?$request['uuid']:$this->getUuId('WEKA','OP'),
                                    'fees'=>$request['fees'],
                                ]);
                                DB::commit();
                                $original=$this->show($savewithdrawtransaction);
                                $original['error']=null;
                                $original['message']="success";
                                return $original;
                            } catch (Exception $th) {
                                DB::rollBack();
                                $request['error']=$th->getMessage();
                                $request['message']="error";
                                return $request->all(); 
                            }
                    }
                }else{
                    $request['error']="account disabled";
                    $request['message']="error";
                    return $request->all(); 
                }
            }else{
                $request['error']="no account sent";
                $request['message']="error";
                return $request->all(); 
            }
        }else{
            $request['error']="no account find";
            $request['message']="error";
            return $request->all();  
        }
    }

    /**
     * saving withdraw on mobile device pending 
     */
    public function pendingWithdrawalAccountTransaction(Request $request){
        if (isset($request['user_id']) && !is_null($request['user_id']) && filter_var($request['user_id'], FILTER_VALIDATE_INT) && (int)$request['user_id'] > 0) {
                $soldbefore=0;
                $soldafter=0;
            //if exist the account and not suspended
            if ($request['member_account_id']) {
                //looking for the account
                $memberaccount=wekamemberaccounts::find($request['member_account_id']);
                if ($memberaccount) {
                    //if the account is enabled
                    if($memberaccount->account_status=="enabled"){
                        //if withdraw test the sold before making request
                        $soldbefore=$memberaccount->sold;
                        if ($request['type']=='withdraw') {
                            //verify the sold vis the amount sent
                            if ($memberaccount->sold>=$request['amount']) {
                                //begin transaction
                                DB::beginTransaction();
                                try {
                                    $memberaccountupdated=$memberaccount;
                                    $memberaccountupdated->sold=$memberaccount->sold-$request['amount'];
                                    $request['transaction_status']=='pending';

                                    $ifexistsuuid=wekaAccountsTransactions::where('uuid',$request['uuid'])->get()->first();

                                    if ($ifexistsuuid) {
                                        return $this->errorResponse('uuid operation duplicated');
                                    }

                                    $savewithdrawtransaction=wekaAccountsTransactions::create([
                                        'amount'=>$request['amount'],
                                        'sold_before'=>$soldbefore,
                                        'sold_after'=> $memberaccountupdated->sold,
                                        'type'=>$request['type'],
                                        'motif'=>$request['motif'],
                                        'user_id'=>$request['user_id'],
                                        'member_account_id'=>$memberaccount->id,
                                        'member_id'=>$memberaccount->user_id,
                                        'enterprise_id'=>$memberaccount->enterprise_id,
                                        'done_at'=>$request['done_at']?$request['done_at']:date('Y-m-d'),
                                        'account_id'=>$request['account_id'],
                                        'operation_done_by'=>$request['operation_done_by'],
                                        'uuid'=>$request['uuid']?$request['uuid']:$this->getUuId('WEKA','OP'),
                                        'fees'=>$request['fees']
                                    ]);
                                    DB::commit();
                                    $original=$this->show($savewithdrawtransaction);
                                    return response()->json([
                                        'error' => null,
                                        'status' => 200,
                                        'message' =>'success',
                                        'data' =>$original 
                                    ]);
                                } catch (Exception $th) {
                                    DB::rollBack();
                                    return $this->errorResponse($th->getMessage(),500);
                                }
                            }else {
                            return $this->errorResponse('sold not enough',200);
                            }
                        }
                    }else{
                        return $this->errorResponse('account disabled',400);   
                    }
                }else{
                    return $this->errorResponse('no account sent',400);  ; 
                }
            }else{
                return $this->errorResponse('no account sent',400);  
            } 
        }else{
            return $this->errorResponse('no member sent');
        }
       
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\wekaAccountsTransactions  $wekaAccountsTransactions
     * @return \Illuminate\Http\Response
     */
    public function show(wekaAccountsTransactions $wekaAccountsTransactions)
    {
        return wekaAccountsTransactions::join('users','weka_accounts_transactions.user_id','=','users.id')
        ->join('wekamemberaccounts as WA','weka_accounts_transactions.member_account_id','WA.id')
        ->join('moneys as M','WA.money_id','M.id')
        ->join('users as AU','WA.user_id','AU.id')
        ->leftjoin('accounts as A','weka_accounts_transactions.account_id','A.id')
        ->where('weka_accounts_transactions.id','=',$wekaAccountsTransactions->id)
        ->get(['AU.user_name as member_user_name','AU.full_name as member_fullname','AU.uuid as member_uuid','weka_accounts_transactions.*','A.name as account_name','WA.description as memberaccount_name','M.abreviation','M.id as money_id','users.user_name as done_by_name','users.full_name as done_by_fullname','users.uuid as done_by_uuid'])->first();
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\wekaAccountsTransactions  $wekaAccountsTransactions
     * @return \Illuminate\Http\Response
     */
    public function edit(wekaAccountsTransactions $wekaAccountsTransactions)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdatewekaAccountsTransactionsRequest  $request
     * @param  \App\Models\wekaAccountsTransactions  $wekaAccountsTransactions
     * @return \Illuminate\Http\Response
     */
    public function update(UpdatewekaAccountsTransactionsRequest $request, wekaAccountsTransactions $wekaAccountsTransactions)
    {
        $find = wekaAccountsTransactions::find($wekaAccountsTransactions->id);

        if ($find) {
            if ($find->transaction_status !== 'pending') {
                return response()->json([
                    'status'  => 403,
                    'message' => 'error',
                    'error'   => 'can not be updated',
                    'data'    => null
                ]);
            }
             DB::beginTransaction();
            try {
                $fieldsToUpdate = $request->only([
                    'amount',
                    'motif',
                    'phone',
                    'adresse',
                    'done_at',
                    'member_account_id',
                    'operation_done_by'
                ]);

                $find->update($fieldsToUpdate);
                 DB::commit();
                return response()->json([
                    'status'  => 200,
                    'message' => 'success',
                    'error'   => null,
                    'data'    => $this->show($find)
                ]);
            } catch (\Exception $e) {
                 DB::rollBack();

                return response()->json([
                    'status'  => 500,
                    'message' => 'error',
                    'error'   => $e->getMessage(),
                    'data'    => null
                ]);
            }
        } else {
            return response()->json([
                'status'  => 404,
                'message' => 'error',
                'error'   => 'not find',
                'data'    => null
            ]);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\wekaAccountsTransactions  $wekaAccountsTransactions
     * @return \Illuminate\Http\Response
     */
    public function destroy(wekaAccountsTransactions $wekaAccountsTransactions)
    {
        //
    }

   public function exportTransactionsExcel(Request $request)
    {
        if (!isset($request->user_id)) {
            return $this->errorResponse('user not sent');
        }

        $actualuser = $this->getinfosuser($request->user_id);
        if (!$actualuser) {
            return $this->errorResponse('unknown user');
        }

        $enterprise = $this->getEse($actualuser->id);
        if (!$enterprise) {
            return $this->errorResponse('unknown enterprise');
        }

        try {

            $format = strtolower($request->get('format', 'csv'));

            $type = match ($format) {
                'xlsx' => \Maatwebsite\Excel\Excel::XLSX,
                'csv'  => \Maatwebsite\Excel\Excel::CSV,
                default => \Maatwebsite\Excel\Excel::CSV,
            };

            $export = new TransactionsExport($request->all(), $actualuser);

            $filename = 'transactions_' . now()->format('Ymd_His') . '.' . $format;

            return Excel::download($export, $filename, $type);

        } catch (\Exception $ex) {
            return $this->errorResponse($ex->getMessage(), 500);
        }
    }

   
    public function exportTransactionsPdf(Request $request)
    {
        if (!isset($request->user_id)) {
            return $this->errorResponse('user not sent');
        }

        $actualuser = $this->getinfosuser($request->user_id);
        if (!$actualuser) {
            return $this->errorResponse('unknown user');
        }

        $enterprise = $this->getEse($actualuser->id);
        if (!$enterprise) {
            return $this->errorResponse('unknown enterprise');
        }

        try {
            $export = new \App\Exports\TransactionsExport($request->all(), $actualuser);

            $transactions = $export->query()->get();

            $grouped = $transactions->groupBy('currency');

            $subtotals = $grouped->map(function ($items) {
                return $items->sum('amount');
            });

            $from = $request->input('from', date('Y-m-d'));
            $to   = $request->input('to', date('Y-m-d'));

            $pdf = PDF::loadView('pdf.transactions', [
                'transactions' => $transactions,
                'enterprise'   => $enterprise,
                'from'         => $from,
                'to'           => $to,
                'grouped'      => $grouped,
                'subtotals'    => $subtotals,
                'actualuser'   => $actualuser,
            ])->setPaper('a4', 'landscape');

            $canvas = $pdf->getDomPDF()->getCanvas();
            $font = $pdf->getDomPDF()->getFontMetrics()->getFont('Helvetica', 'normal');

            $canvas->page_script(function ($pageNumber, $pageCount) use ($canvas, $font) {
                $text = "Page $pageNumber / $pageCount";
                $canvas->text(770, 570, $text, $font, 10); 
            });

            return $pdf->stream('transactions.pdf');

        } catch (\Exception $ex) {
            return $this->errorResponse($ex->getMessage(), 500);
        }
    }


   public function validateimputation(Request $request)
    {
        $request->validate([
            'data' => 'required|array|min:1',
            'user_id' => 'required|integer|exists:users,id',
            'tub' => 'required|array',
            'tub.id' => 'required|integer|exists:funds,id',
        ]);

        $transactions = $request->input('data');
        $userId = $request->input('user_id');
        $tubId = $request->input('tub.id');

        $errors = [];
        $updated = [];

        DB::beginTransaction();

        try {
            foreach ($transactions as $item) {
                if (!isset($item['id'])) {
                    throw new \Exception("Une transaction ne contient pas d'identifiant.");
                }

                $transaction = wekaAccountsTransactions::find($item['id']);

                if (!$transaction) {
                    throw new \Exception("Transaction ID {$item['id']} introuvable.");
                }

                if ($transaction->imputed_at !== null || $transaction->imputed_by !== null || $transaction->imputed_to !== null) {
                    throw new \Exception("Transaction ID {$item['id']} est déjà imputée.");
                }

                if ($transaction->transaction_status !== 'validated') {
                    throw new \Exception("Transaction ID {$item['id']} n'est pas validée et ne peut pas être imputée.");
                }

                // Mise à jour de l'imputation
                $transaction->imputed_by = $userId;
                $transaction->imputed_to = $tubId;
                $transaction->imputed_at = now();
                $transaction->save();

                $member = User::find($transaction->member_id);
                $memberName = $member ? ($member->full_name ?: $member->user_name) : null;
                $beneficiary = $transaction->type !== 'deposit' ? $memberName : null;
                $provenance = $transaction->type === 'deposit' ? $memberName : null;

                //Enregistrement dans l’historique
                app(RequestHistoryController::class)->store(new StorerequestHistoryRequest([
                    'user_id'       => $userId,
                    'fund_id'       => $tubId,
                    'amount'        => $transaction->amount ?? 0,
                    'motif'         => $transaction->motif ?? null,
                    'type'          => $transaction->type === 'deposit' ? 'entry' : 'withdraw',
                    'request_id'    => null,
                    'fence_id'      => null,
                    'invoice_id'    => null,
                    'enterprise_id' => $transaction->enterprise_id,
                    'sold'          => 0,
                    'done_at'       => date('Y-m-d'),
                    'account_id'    => $transaction->account_id ?? null,
                    'status'        => 'validated',
                    'beneficiary'   => $beneficiary,
                    'provenance'    => $provenance,
                    'uuid'          => $this->getUuId('RH', 'C'),
                ]));

                $updated[] = $transaction->id;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Toutes les transactions ont été imputées avec succès.',
                'updated' => $updated,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Échec de l\'imputation des transactions.',
                'error' => $e->getMessage(),
                'updated' => $updated,
            ], 500);
        }
    }
}
