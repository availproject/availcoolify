<?php

namespace App\Jobs;

use App\Models\Application;
use App\Notifications\Avail\CustomDomainDnsChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Avail: new custom domains of an app. Creates their DNS records in the connected Cloudflare zone
 * (CNAME to the app's address for a subdomain, A for a root domain), then tells the Owner what was
 * created and what still has to be added by hand.
 */
class AvailCustomDomainDnsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

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
        $records = availProvisionCustomDomainDns($application, $hosts);

        try {
            $notice = CustomDomainDnsChanged::for($application, $hosts, [], $records);
            $application->team()?->notify($notice);
            $notice->emailOwners();
        } catch (\Throwable $e) {
            Log::warning('Avail custom domain notice failed: '.$e->getMessage());
        }
    }
}
