<?php

namespace App\Livewire\Dashboard;

use App\Models\Application;
use App\Models\ServiceApplication;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Avail: the Owner's list of custom domains. AvailCoolify can't edit Cloudflare, so this shows every
 * custom domain, whether its DNS works yet, and the record to add. Domains kept where the rules no
 * longer allow them (outside production, not deployed from Git, services) are flagged.
 */
#[Lazy]
class AvailCustomDomains extends Component
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function mount(): void
    {
        $this->rows = availIsPlatformOwner() ? $this->buildRows() : [];
    }

    public function placeholder(): string
    {
        return '<div></div>';
    }

    /** @return list<array<string, mixed>> */
    private function buildRows(): array
    {
        $team = currentTeam();
        if (! $team || ! availCustomDomainRulesEnabled()) {
            return [];
        }
        $rows = [];

        $applications = Application::query()
            ->whereRelation('environment.project.team', 'id', $team->id)
            ->where(fn ($query) => $query->whereNotNull('fqdn')->orWhereNotNull('docker_compose_domains'))
            ->with(['environment.project', 'settings'])
            ->get();
        foreach ($applications as $application) {
            $hosts = availApplicationCustomHosts($application);
            if ($hosts === []) {
                continue;
            }
            $records = collect(availApplicationCloudflareRecords($application))->keyBy('fqdn');
            $kept = availApplicationCustomDomainRuleError($application) !== null;
            $environment = $application->environment;
            $project = $environment?->project;
            foreach ($hosts as $host) {
                $rows[] = [
                    'host' => $host,
                    'resource' => $application->name,
                    'location' => trim(($project?->name ?? '').' / '.($environment?->name ?? ''), ' /'),
                    'url' => ($project && $environment) ? route('project.application.domains', [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'application_uuid' => $application->uuid,
                    ]) : null,
                    'status' => $this->statusFor($application->domain_dns_statuses, $host),
                    'record' => $records->get($host),
                    'preview_record' => $records->get('*.'.$host),
                    'kept' => $kept,
                ];
            }
        }

        $serviceApplications = ServiceApplication::query()
            ->whereNotNull('fqdn')
            ->whereRelation('service.environment.project.team', 'id', $team->id)
            ->with('service.environment.project')
            ->get();
        foreach ($serviceApplications as $serviceApplication) {
            $server = availServiceApplicationServer($serviceApplication);
            $hosts = availCustomHostsIn($serviceApplication->fqdn, availWildcardHost($server));
            if ($hosts === []) {
                continue;
            }
            $service = $serviceApplication->service;
            $environment = $service?->environment;
            $project = $environment?->project;
            $records = collect(availCloudflareRecords($hosts, [], availDnsRecordIp($server)))->keyBy('fqdn');
            foreach ($hosts as $host) {
                $rows[] = [
                    'host' => $host,
                    'resource' => ($service?->name ?? 'Service').' / '.$serviceApplication->name,
                    'location' => trim(($project?->name ?? '').' / '.($environment?->name ?? ''), ' /'),
                    'url' => ($project && $environment && $service) ? route('project.service.domains', [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'service_uuid' => $service->uuid,
                    ]) : null,
                    'status' => $this->statusFor($serviceApplication->domain_dns_statuses, $host),
                    'record' => $records->get($host),
                    'preview_record' => null,
                    'kept' => true,
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['status']['order'], $a['host']] <=> [$b['status']['order'], $b['host']]);

        return $rows;
    }

    /**
     * The stored DNS check result for a host (keys are "<url>" or "<service>|<url>").
     *
     * @return array{label: string, type: string, message: string, order: int}
     */
    private function statusFor(mixed $statuses, string $host): array
    {
        foreach ((is_array($statuses) ? $statuses : []) as $key => $entry) {
            $url = str_contains((string) $key, '|') ? substr((string) $key, strpos((string) $key, '|') + 1) : (string) $key;
            if (availUrlHost($url) !== $host || ! is_array($entry)) {
                continue;
            }

            return match ($entry['status'] ?? null) {
                'ok' => ['label' => 'DNS works', 'type' => 'success', 'message' => (string) ($entry['message'] ?? ''), 'order' => 2],
                'checking' => ['label' => 'Checking', 'type' => 'neutral', 'message' => '', 'order' => 1],
                'failed' => ['label' => 'Waiting for DNS', 'type' => 'error', 'message' => (string) ($entry['message'] ?? ''), 'order' => 0],
                default => ['label' => 'Not checked', 'type' => 'warning', 'message' => (string) ($entry['message'] ?? ''), 'order' => 1],
            };
        }

        return ['label' => 'Not checked', 'type' => 'warning', 'message' => 'Open the Domains page and press Check DNS.', 'order' => 1];
    }

    public function render()
    {
        return view('livewire.dashboard.avail-custom-domains');
    }
}
