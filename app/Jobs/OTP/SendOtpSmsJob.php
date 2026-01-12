<?php

namespace App\Jobs\OTP;

use App\Models\User;
use Illuminate\Bus\Queueable;
use App\Services\TwilioService;
use App\Services\BulkSmsService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class SendOtpSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user_phone;
    protected $is_collector;
    protected $user_email;
    protected $user_id;
    protected string $otp;
    protected string $from;
    protected string $channel;

    public function __construct(String $user_phone, int $is_collector, int $user_id, String $user_email, string $otp, string $channel = 'sms')
    {
        $this->user_phone    = $user_phone;
        $this->is_collector = $is_collector;
        $this->user_email = $user_email;
        $this->user_id = $user_id;
        $this->otp     = $otp;
        $this->from = "WEKA AKIBA";
        $this->channel ='email';
        // $this->channel = $channel ?? 'sms';
    }

    public function handle(BulkSmsService $sms): void
    {
           $this->sendEmail();
        
        // if ((int) $this->is_collector === 1) {
        //     Log::error('Réponse inconnue 2', [
        //         'user_id' => $this->user_id
        //     ]);
        //     $this->sendEmail();
        //     return;
        // }

        // switch ($this->channel) {
        //     case 'sms':
        //         //  Pas de téléphone → fallback email
        //         if (!$this->user_phone) {

        //             Log::error('Réponse inconnue 2', [
        //                 'user_id' => $this->user_id
        //             ]);
        //             $this->sendEmail();
        //             return;
        //         }

        //         try {
        //             Log::error('Réponse inconnue 3', [
        //                 'user_id' => $this->user_id
        //             ]);
        //             $messages = [
        //                 [
        //                     'from' => $this->from,
        //                     'to'   => $this->user_phone,
        //                     'body' => "Votre OTP de confirmation est : {$this->otp}",
        //                 ]
        //             ];
        //             Log::error('Réponse inconnue 4', [
        //                 'user_id' => $this->user_id,
        //             ]);
        //             $response = $sms->send($messages);
        //             Log::error('Réponse inconnue 5', [
        //                 'user_id' => $this->user_id,
        //                 'response' => $response
        //             ]);
        //             /**
        //              * CAS 201 : tableau de messages ACCEPTED
        //              */
        //             if (is_array($response) && isset($response[0]['status']['type'])) {
        //                 Log::error('Réponse inconnue 6', [
        //                     'user_id' => $this->user_id,
        //                     'response' => $response
        //                 ]);
        //                 if ($response[0]['status']['type'] === 'ACCEPTED') {
        //                     Log::error('Réponse inconnue 7', [
        //                         'user_id' => $this->user_id,
        //                         'response' => $response
        //                     ]);
        //                     $this->sendEmail();
        //                     return;
        //                 }
        //                 Log::error('Réponse inconnue 8', [
        //                     'user_id' => $this->user_id,
        //                     'response' => $response
        //                 ]);
        //             }
        //             Log::error('Réponse inconnue 9', [
        //                 'user_id' => $this->user_id,
        //                 'response' => $response
        //             ]);
        //             if (is_array($response) && isset($response['status'])) {

        //                 Log::error('Échec SMS OTP BulkSMS', [
        //                     'user_id' => $this->user_id,
        //                     'status'  => $response['status'],
        //                     'title'   => $response['title'] ?? null,
        //                     'detail'  => $response['detail'] ?? null,
        //                 ]);

        //                 $this->sendEmail();
        //                 Log::error('Réponse inconnue 10', [
        //                     'user_id' => $this->user_id,
        //                     // 'response' => $response
        //                 ]);
        //                 Log::error('Réponse inconnue 11', [
        //                     'user_id' => $this->user_id,
        //                     // 'response' => $response
        //                 ]);
        //                 return;
        //             }
        //             Log::error('Réponse BulkSMS inconnue', [
        //                 'user_id' => $this->user_id,
        //                 'response' => $response
        //             ]);

        //             $this->sendEmail();
        //             Log::error('Réponse inconnue 12', [
        //                 'user_id' => $this->user_id,
        //                 // 'response' => $response
        //             ]);
        //         } catch (\Throwable $e) {

        //             Log::error('Exception SMS OTP', [
        //                 'user_id' => $this->user_id,
        //                 'error'   => $e->getMessage()
        //             ]);

        //             // 🔁 fallback ultime
        //             $this->sendEmail();
        //         }

        //         break;

        //     case 'email':
        //         $this->sendEmail();
        //         break;

        //     default:
        //         throw new \Exception("Canal OTP invalide.");
        // }
    }

    private function sendEmail(): void
    {
        if (!$this->user_email) {
            throw new \Exception("Utilisateur sans email.");
        }

        Mail::raw(
            "Votre OTP de confirmation est : {$this->otp}",
            function ($message) {
                $message->to($this->user_email)
                    ->subject('OTP de confirmation');
            }
        );
        Log::error('EMAIL OTP SENT TO', [
                        'user_id' => $this->user_id,
                        'email'   =>$this->user_email
                    ]);
    }
}
