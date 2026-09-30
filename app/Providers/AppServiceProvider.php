<?php

namespace App\Providers;

use App\Contracts\BelongsToApartment;
use App\Models\Activity;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureProxies();
        $this->configureAuditLog();

        // Our texts have Polish keys and the framework's English ones: never fall back to the other language.
        // Dates (month names) follow too – also when an e-mail is rendered in the tenant's language.
        Event::listen(LocaleUpdated::class, function (LocaleUpdated $event) {
            app('translator')->setFallback($event->locale);
            CarbonImmutable::setLocale($event->locale);
            // e.g. Polish has a standalone month form English lacks – don't borrow it from Polish.
            CarbonImmutable::setFallbackLocale($event->locale);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Take the visitor's IP address from the proxy's header, but only when the request really comes
     * from that proxy – otherwise anyone could fake their address and dodge the sign-in limits.
     */
    protected function configureProxies(): void
    {
        $proxies = trim((string) config('app.trusted_proxies'));

        if ($proxies === '') {
            return;
        }

        TrustProxies::at(match ($proxies) {
            '*' => '*',
            // https://www.cloudflare.com/ips/ – plus private addresses, for a local proxy in front of the app.
            'cloudflare' => [
                '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
                '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
                '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
                '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
                '2a06:98c0::/29', '2c0f:f248::/32',
                'PRIVATE_SUBNETS',
            ],
            default => array_map(trim(...), explode(',', $proxies)),
        });
    }

    /**
     * Record who did what: every log entry gets the IP address and browser,
     * and sign-ins, sign-outs and password changes are logged as well.
     */
    protected function configureAuditLog(): void
    {
        Activity::creating(function (Activity $activity) {
            $request = request();

            $context = [
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ];

            // Lease, bill, payment… changes also show up in the apartment's history.
            $subject = $activity->subject;
            if ($subject instanceof BelongsToApartment) {
                $activity->apartment_id = $subject->auditApartmentId();
                $context['apartment_id'] = $subject->auditApartmentId();
                $context['currency'] = $subject->auditCurrency();
            }

            $activity->properties = collect($activity->properties)->merge($context);
        });

        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                Audit::user($event->user, 'login');
            }
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user instanceof User) {
                Audit::user($event->user, 'logout');
            }
        });

        User::updated(function (User $user) {
            if ($user->wasChanged('password')) {
                Audit::user($user, 'password_changed');
            }
        });
    }
}
