<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

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
        $this->configureAuditLog();
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
     * Record who did what: every log entry gets the IP address and browser,
     * and sign-ins, sign-outs and password changes are logged as well.
     */
    protected function configureAuditLog(): void
    {
        Activity::creating(function (Activity $activity) {
            $request = request();

            $activity->properties = collect($activity->properties)->merge([
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ]);
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
