<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * USR-01 : connexion par code OTP envoyé par SMS.
 * Limites : 1 code par minute, 5 par heure et par numéro, 5 essais par code.
 */
class OtpService
{
    public function __construct(private readonly SmsSender $sms) {}

    public function envoyer(string $telephone, ?string $ip): void
    {
        $cfg = config('bravido.otp');

        $dernier = OtpCode::where('telephone', $telephone)->latest('id')->first();
        if ($dernier && $dernier->created_at->gt(now()->subSeconds($cfg['intervalle_s']))) {
            throw ValidationException::withMessages([
                'telephone' => 'Veuillez patienter une minute avant de demander un nouveau code.',
            ]);
        }

        $dansLHeure = OtpCode::where('telephone', $telephone)
            ->where('created_at', '>=', now()->subHour())
            ->count();
        if ($dansLHeure >= $cfg['max_par_heure']) {
            throw ValidationException::withMessages([
                'telephone' => 'Trop de demandes pour ce numéro. Réessayez dans une heure.',
            ]);
        }

        $code = $this->genererCode($cfg['longueur']);

        OtpCode::create([
            'telephone' => $telephone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($cfg['validite_minutes']),
            'ip' => $ip,
        ]);

        $this->sms->envoyer(
            $telephone,
            "BRAVIDO : votre code est {$code}. Valable {$cfg['validite_minutes']} min. Ne le communiquez à personne."
        );
    }

    public function verifier(string $telephone, string $code): bool
    {
        return DB::transaction(function () use ($telephone, $code) {
            $otp = OtpCode::where('telephone', $telephone)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp || $otp->tentatives >= config('bravido.otp.tentatives_max')) {
                return false;
            }

            if (! Hash::check($code, $otp->code_hash)) {
                $otp->increment('tentatives');

                return false;
            }

            $otp->forceFill(['consumed_at' => now()])->save();

            return true;
        });
    }

    private function genererCode(int $longueur): string
    {
        $codeTest = config('bravido.otp.code_test');
        if ($codeTest && app()->environment('local')) {
            return (string) $codeTest;
        }

        return str_pad((string) random_int(0, 10 ** $longueur - 1), $longueur, '0', STR_PAD_LEFT);
    }
}
