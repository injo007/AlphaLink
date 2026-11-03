<?php

namespace App\Providers;

use App\Services\Infrastructure\AlphaLinkBulkInstallDomainDnsRecordWriter;
use App\Services\Infrastructure\AlphaLinkBulkInstallRowRunner;
use App\Services\Infrastructure\AlphaLinkCentos9BulkInstallRowRunner;
use App\Services\Infrastructure\AlphaLinkChangeDomainDnsRecordWriter;
use App\Services\Infrastructure\AlphaLinkDomainRecordsHostWriter;
use App\Services\Infrastructure\AlphaLinkInstallerSshConnector;
use App\Services\Infrastructure\AlphaLinkRegistrarChangeDomainDnsRecordWriter;
use App\Services\Infrastructure\PhpseclibAlphaLinkInstallerSshConnector;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AlphaLinkInstallerSshConnector::class, PhpseclibAlphaLinkInstallerSshConnector::class);
        $this->app->bind(AlphaLinkBulkInstallDomainDnsRecordWriter::class, AlphaLinkRegistrarChangeDomainDnsRecordWriter::class);
        $this->app->bind(AlphaLinkChangeDomainDnsRecordWriter::class, AlphaLinkRegistrarChangeDomainDnsRecordWriter::class);
        $this->app->bind(AlphaLinkDomainRecordsHostWriter::class, AlphaLinkRegistrarChangeDomainDnsRecordWriter::class);
        $this->app->bind(AlphaLinkBulkInstallRowRunner::class, AlphaLinkCentos9BulkInstallRowRunner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('campaign-send', static function (Request $request): Limit {
            $actor = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
            $campaign = (string) ($request->route('campaign') ?? 'unknown');

            return Limit::perMinute(10)->by('campaign-send:'.$actor.':'.$campaign);
        });

        // Laravel's development server filters environment variables before
        // starting its HTTP worker. Preserve AlphaLink's protected env path so
        // the worker loads the same APP_KEY and settings as Artisan.
        if (! in_array('ALPHALINK_ENV_DIR', ServeCommand::$passthroughVariables, true)) {
            ServeCommand::$passthroughVariables[] = 'ALPHALINK_ENV_DIR';
        }
    }
}
