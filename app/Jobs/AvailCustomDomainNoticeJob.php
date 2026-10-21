<?php

namespace App\Jobs;

use App\Models\Application;
use App\Notifications\Avail\CustomDomainDnsChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Avail: new custom domains of an app. Works out their Cloudflare records (CNAME to the app's address
 * for a subdomain in one of Avail's Cloudflare zones, A otherwise) and tells the Owner to add them.
 * Queued because the records need DNS lookups.
 */
class AvailCustomDomainNoticeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /** @param  list<string>  $hosts */
    public function __construct(public int $applicationId, public array $hosts)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $application = Application::find($this->applicationId);
        if (! $application) {
            return;
        }
        $hosts = array_values(array_intersect($this->hosts, availApplicationCustomHosts($application)));
        if ($hosts === []) {
            return; // removed again before this ran
        }

        try {
            $notice = CustomDomainDnsChanged::for($application, $hosts, [], availApplicationCloudflareRecords($application, $hosts));
            $application->team()?->notify($notice);
            $notice->emailOwners();
        } catch (\Throwable $e) {
            Log::warning('Avail custom domain notice failed: '.$e->getMessage());
        }
    }
}
