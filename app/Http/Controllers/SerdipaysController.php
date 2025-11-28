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
     public function getToken()
    {
        try {
            $lastconfig = serdipays::configFor("test");
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        if (!$lastconfig->token_endpoint) {
            return $this->errorResponse("Token-endpoint non configuré", 400);
        }

        if (!$lastconfig->email) {
            return $this->errorResponse("Email non configuré", 400);
        }

        if (!$lastconfig->password) {
            return $this->errorResponse("Password non configuré", 400);
        }

        try {
            $response = Http::post($lastconfig->token_endpoint, [
                'email'    => $lastconfig->email,
                'password' => $lastconfig->password,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse("Impossible d'appeler le token-endpoint: " . $e->getMessage(), 400);
        }

        $data = $response->json();

        if (!$response->successful()) {
            return $this->errorResponse("Impossible de récupérer le token", 400);
        }

        // Sécuriser l'accès au token
        $token = $data['token']['access_token'] ?? null;

        if (!$token) {
            return $this->errorResponse("Le token n'a pas été retourné par l'API", 400);
        }

        // Mise à jour
        $lastconfig->update([
            'token' => $token
        ]);

        return $this->successResponse("success", $token);
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
