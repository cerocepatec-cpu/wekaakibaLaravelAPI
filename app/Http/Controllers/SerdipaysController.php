<?php

namespace App\Http\Controllers;

use App\Models\serdipays;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreserdipaysRequest;
use App\Http\Requests\UpdateserdipaysRequest;
use Illuminate\Support\Facades\Http;

class SerdipaysController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * get token for SERDI PAIE integration
     */
    public function getToken(){
        $response = Http::post('https://api.serdipay.cloud/api/public-api/v1/merchant/get-token', [
            'email' => 'kilimbanyifabrice@gmail.com',
            'password' => 'Paradojacero2021??',
        ]);

        $data = $response->json();
        if ($response->successful()) {
            $lastconfig=serdipays::latest()->first();
            if($lastconfig){
                $lastconfig->update(['token' => $data['token'] ?? null,]);
            }
            return response()->json([
                'status' => 'success',
                'token' => $data,
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => $data['message'] ?? 'Failed to retrieve token',
            ], $response->status());
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
     * @param  \App\Http\Requests\StoreserdipaysRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreserdipaysRequest $request)
    {
        
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\serdipays  $serdipays
     * @return \Illuminate\Http\Response
     */
    public function show(serdipays $serdipays)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\serdipays  $serdipays
     * @return \Illuminate\Http\Response
     */
    public function edit(serdipays $serdipays)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateserdipaysRequest  $request
     * @param  \App\Models\serdipays  $serdipays
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateserdipaysRequest $request, serdipays $serdipays)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\serdipays  $serdipays
     * @return \Illuminate\Http\Response
     */
    public function destroy(serdipays $serdipays)
    {
        //
    }
}
