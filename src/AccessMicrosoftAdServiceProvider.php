<?php

namespace TornadoEngine\AccessMicrosoftAd;

use App\Models\User;
use App\Support\PasswordSignIn;
use Illuminate\Support\ServiceProvider;
use Throwable;
use TornadoEngine\Access\Access;
use TornadoEngine\Cron\Cron;
use TornadoEngine\Docs\Docs;

class AccessMicrosoftAdServiceProvider extends ServiceProvider
{
    public const JOB = 'access-microsoft-ad.sync';

    public function register(): void
    {
        $this->app->bind(Directory::class, LdapDirectory::class);
    }

    public function boot(Access $access, PasswordSignIn $passwords): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'access-microsoft-ad');
        $this->loadRoutesFrom(__DIR__.'/../routes/access-microsoft-ad.php');

        $access->tab('access.ad.index', __('Active Directory'), active: 'access.ad.*');

        // Signing in with an Active Directory password, when it's turned on. The person needs
        // a Tornado Engine account already (from a rule, or added by hand).
        $passwords->add('microsoft-ad', function (string $login, string $password): ?User {
            try {
                $email = app(Directory::class)->authenticate($login, $password);
            } catch (Throwable $e) {
                report($e);

                return null;
            }

            return $email ? User::where('email', $email)->first() : null;
        }, enabled: fn () => Connection::signInEnabled());

        $this->callAfterResolving(Cron::class, function (Cron $cron) {
            $cron->job(self::JOB, __('Sync roles from Active Directory'), fn () => app(Sync::class)->run(),
                schedule: '45 2 * * *',
                description: __('Gives roles to the people in your Active Directory rules (Users and roles → Active Directory), and takes them back from people who left.'));
        });

        $this->callAfterResolving(Docs::class, function (Docs $docs) {
            $docs->add('active-directory', __('Set up Active Directory'), 'access-microsoft-ad::docs.setup', order: 40);
        });
    }
}
