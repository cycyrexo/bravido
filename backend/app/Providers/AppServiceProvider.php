<?php

namespace App\Providers;

use App\Services\Sms\HttpSmsSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsSender::class, function () {
            $cfg = config('services.sms');

            return $cfg['driver'] === 'http'
                ? new HttpSmsSender((string) $cfg['url'], (string) $cfg['token'], (string) $cfg['sender'])
                : new LogSmsSender;
        });
    }

    public function boot(): void
    {
        // Signale dans les logs les relations chargées paresseusement (N+1) hors production
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(
            fn ($model, string $relation) => Log::debug('Chargement paresseux : '.$model::class.'::'.$relation)
        );

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // OTP : protège la passerelle SMS (coût) en plus des limites par numéro
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(5)->by('otp-ip:'.$request->ip()),
            Limit::perHour(30)->by('otp-ip-h:'.$request->ip()),
        ]);

        RateLimiter::for('alerte', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('public-web', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
