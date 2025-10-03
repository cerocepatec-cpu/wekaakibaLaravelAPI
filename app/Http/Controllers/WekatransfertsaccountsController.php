<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UsersEnterprise;
use App\Models\wekatransfertsaccounts;
use Illuminate\Support\Facades\DB;

class WekatransfertsaccountsController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'source' => 'required|array',
            'destination' => 'required|array',
            'original_amount' => 'required|numeric|min:1',
            'done_by' => 'required|integer',
            'enterprise' => 'required|integer',
        ]);

        try {
            DB::beginTransaction();

            $sourceId = $request->source['id'] ?? null;
            $destinationId = $request->destination['id'] ?? null;

            if (!$sourceId || !$destinationId) {
                return $this->errorResponse('account_id manquant dans source ou destination',422);
            }

            $sourceAccount = \App\Models\wekamemberaccounts::find($sourceId);
            $destinationAccount = \App\Models\Wekamemberaccounts::find($destinationId);

            if (!$sourceAccount || !$destinationAccount) {
                return $this->errorResponse('Compte source ou destination introuvable',404);
            }

            // Vérifie que l'utilisateur est bien affecté à une entreprise
            $affectation = \App\Models\UsersEnterprise::where('user_id', $request->done_by)->first();
            if (!$affectation) {
                return $this->errorResponse('Affectation utilisateur-entreprise introuvable',403);
            }

            if ($affectation->enterprise_id != $request->enterprise) {
                return $this->errorResponse('Entreprise non autorisée pour cet utilisateur', 403);
            }

            // Vérifie les soldes
            if ($sourceAccount->sold < $request->original_amount) {
                return $this->errorResponse('Solde insuffisant sur le compte source', 422);
            }

            $sourceCurrencyId = $sourceAccount->currency_id;
            $destinationCurrencyId = $destinationAccount->currency_id;

            $conversionRate = 1;
            $convertedAmount = $request->original_amount;

            if ($sourceCurrencyId != $destinationCurrencyId) {
                $conversionRate = $this->getConversionRate($sourceCurrencyId, $destinationCurrencyId);

                if (!$conversionRate || $conversionRate <= 0) {
                    return $this->errorResponse('Taux de conversion invalide ou indisponible', 422);
                }

                $convertedAmount = round($request->original_amount * $conversionRate, 2);
            }

            // Création du transfert
            $transfer =wekatransfertsaccounts::create([
                'enterprise' => $request->enterprise,
                'done_by' => $request->done_by,
                'validated_by' => null,
                'source' => $sourceAccount->id,
                'destination' => $destinationAccount->id,
                'source_currency_id' => $sourceCurrencyId,
                'destination_currency_id' => $destinationCurrencyId,
                'original_amount' => $request->original_amount,
                'converted_amount' => $convertedAmount,
                'conversion_rate' => $conversionRate,
                'pin' => $request->pin ?? null,
                'transfert_status' => 'pending',
            ]);

            DB::commit();

            return response()->json(
                ["status"=>200,
                "message"=>"success",
                "error"=>null,
                "data"=>$this->show($transfer)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "status"=>500,
                "message"=>"error",
                "error"=>$e->getMessage(),
                "data"=>null
            ]);;
        }
    }

   public function getTransfersList(Request $request)
    {
        $from = $request->from ?? date('Y-m-d');
        $to = $request->to ?? date('Y-m-d');

        if (!$request->filled('enterprise')) {
            return response()->json([
                'status' => 422,
                'message' => 'Le champ enterprise est obligatoire.'
            ], 422);
        }

        try {
            $query = \App\Models\wekatransfertsaccounts::with([
                'sourceAccount',
                'destinationAccount',
                'sourceCurrency',
                'destinationCurrency',
                'doneBy'
                // 'enterprise'
            ]);

            // ✅ Clause obligatoire
            $query->where('enterprise', $request->enterprise);

            // ✅ Période
            $query->whereBetween('created_at', ["$from 00:00:00", "$to 23:59:59"]);

            if (!empty($request->source)) {
                $query->whereIn('source', is_array($request->source) ? $request->source : [$request->source]);
            }

            if (!empty($request->destination)) {
                $query->whereIn('destination', is_array($request->destination) ? $request->destination : [$request->destination]);
            }

            if (!empty($request->transfert_status)) {
                $query->whereIn('transfert_status', is_array($request->transfert_status) ? $request->transfert_status : [$request->transfert_status]);
            }

            if (!empty($request->source_currency_id)) {
                $query->whereIn('source_currency_id', is_array($request->source_currency_id) ? $request->source_currency_id : [$request->source_currency_id]);
            }

            // ✅ Tous les IDs
            $allIds = [];
            (clone $query)->select('id')->orderBy('id')->chunk(1000, function ($transfers) use (&$allIds) {
                foreach ($transfers as $t) {
                    $allIds[] = $t->id;
                }
            });

            // ✅ Pagination
            $limit = $request->get('limit', 50);
            $paginated = $query->orderBy('created_at', 'desc')->paginate($limit);

            $data = $paginated->getCollection();
            $paginated->setCollection($data);

            // ✅ Totaux par monnaie
            $totalsByMoney = $data->groupBy('source_currency_id')->map(function ($items, $money_id) {
                return [
                    'money_id'        => $money_id,
                    'abreviation'     => optional($items->first()->sourceCurrency)->abreviation ?? '',
                    'total_original'  => $items->sum('original_amount'),
                    'total_converted' => $items->sum('converted_amount'),
                ];
            })->values();

            // ✅ Totaux par statut
            $totalsByStatus = $data->groupBy('transfert_status')->map(function ($items, $status) {
                return [
                    'status' => $status,
                    'total' => $items->sum('converted_amount'),
                    'count' => $items->count(),
                ];
            })->values();

            return response()->json([
                'status' => 200,
                'from' => $from,
                'to' => $to,
                'message' => 'success',
                'data' => $paginated,
                'all_ids' => $allIds,
                'totals_by_money' => $totalsByMoney,
                'totals_by_status' => $totalsByStatus,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Erreur lors du chargement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(wekatransfertsaccounts $wekatransfertsaccounts)
    {
        $transfer = wekatransfertsaccounts::with([
            'sourceAccount',
            'destinationAccount',
            'sourceCurrency',
            'destinationCurrency',
            'doneBy',
            'validatedBy',
            'enterprise'
        ])->find($wekatransfertsaccounts->id);

        if (!$transfer) {
            return $this->errorResponse("Transfert introuvable", 404);
        }

        return $transfer;
    }

}
