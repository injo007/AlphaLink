<?php

namespace App\Providers;

use App\Services\Infrastructure\DmsBulkInstallDomainDnsRecordWriter;
use App\Services\Infrastructure\DmsBulkInstallRowRunner;
use App\Services\Infrastructure\DmsCentos9BulkInstallRowRunner;
use App\Services\Infrastructure\DmsChangeDomainDnsRecordWriter;
use App\Services\Infrastructure\DmsDomainRecordsHostWriter;
use App\Services\Infrastructure\DmsInstallerSshConnector;
use App\Services\Infrastructure\DmsRegistrarChangeDomainDnsRecordWriter;
use App\Services\Infrastructure\PhpseclibDmsInstallerSshConnector;
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
        $this->app->bind(DmsInstallerSshConnector::class, PhpseclibDmsInstallerSshConnector::class);
        $this->app->bind(DmsBulkInstallDomainDnsRecordWriter::class, DmsRegistrarChangeDomainDnsRecordWriter::class);
        $this->app->bind(DmsChangeDomainDnsRecordWriter::class, DmsRegistrarChangeDomainDnsRecordWriter::class);
        $this->app->bind(DmsDomainRecordsHostWriter::class, DmsRegistrarChangeDomainDnsRecordWriter::class);
        $this->app->bind(DmsBulkInstallRowRunner::class, DmsCentos9BulkInstallRowRunner::class);
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
