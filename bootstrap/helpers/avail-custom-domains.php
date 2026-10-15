<?php

/*
 * Avail: custom domains.
 *
 * A custom domain is any address outside the server's wildcard domain (e.g. test.abc.xyz when the
 * wildcard is apps.avail.so).
 *
 * Who may have one:
 *   - applications deployed from Git (a public repository or the GitHub App, any build pack except a
 *     pasted Dockerfile or a Docker image; Docker Compose from Git included)
 *   - only in an environment named "production"
 *   - admins and the Owner on any such app, members on the apps they created
 * Pasted-Dockerfile apps, Docker image apps and one-click services never get one. Custom domains that
 * already exist where they are no longer allowed stay and keep working, but nothing can be added.
 *
 * DNS, as Vercel does it: a subdomain gets a CNAME to the app's own address (<app>.apps.avail.so), a
 * root domain an A record to the server's public IP. When the zone is in a Cloudflare account the Owner
 * connected (Security -> Integration tokens), the record is created automatically; otherwise the Domains
 * page and the notice show it for the Owner to add by hand.
 *
 * Removing: admins and the Owner remove custom domains directly; members ask the Owner, who approves or
 * rejects the request on the dashboard. Approval removes the domain and the records AvailCoolify created.
 */

use App\Exceptions\AvailCustomDomainException;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Notifications\Avail\CustomDomainDnsChanged;
use App\Support\DnsRecordHints;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PurplePixie\PhpDns\DNSTypes;

/** The rules are on everywhere except upstream's own tests (phpunit.xml), which use custom domains freely. */
function availCustomDomainRulesEnabled(): bool
{
    return (bool) config('avail.custom_domain_rules', true);
}

/** The server's wildcard host (e.g. apps.avail.so), read fresh: settings may be cached for the request. */
function availWildcardHost(?Server $server): ?string
{
    if (! $server) {
        return null;
    }
    $wildcard = ServerSetting::where('server_id', $server->id)->value('wildcard_domain');
    $host = strtolower((string) parse_url((string) $wildcard, PHP_URL_HOST));

    return $host !== '' ? $host : null;
}

function availUrlHost(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        $url = 'http://'.$url;
    }
    $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));

    return $host !== '' ? $host : null;
}

/** Whether a host is a custom domain: not under the server's wildcard and not a generated sslip.io name. */
function availIsCustomDomainHost(string $host, ?string $wildcardHost): bool
{
    $host = strtolower(rtrim($host, '.'));
    if ($host === '' || $host === 'sslip.io' || str_ends_with($host, '.sslip.io')) {
        return false;
    }
    if ($wildcardHost !== null && ($host === $wildcardHost || str_ends_with($host, '.'.$wildcardHost))) {
        return false;
    }

    return true;
}

/**
 * Custom hosts in a comma-separated domain list.
 *
 * @return list<string>
 */
function availCustomHostsIn(?string $domains, ?string $wildcardHost): array
{
    return collect(explode(',', (string) $domains))
        ->map(fn ($url) => availUrlHost($url))
        ->filter(fn ($host) => $host !== null && availIsCustomDomainHost($host, $wildcardHost))
        ->unique()
        ->values()
        ->all();
}

/**
 * Custom hosts in a docker_compose_domains value, per Compose service.
 *
 * @return array<string, list<string>>
 */
function availCustomHostsInComposeDomains(mixed $json, ?string $wildcardHost): array
{
    $entries = is_string($json) ? json_decode($json, true) : $json;
    if (! is_array($entries)) {
        return [];
    }
    $hosts = [];
    foreach ($entries as $service => $entry) {
        $found = availCustomHostsIn(composeDomainEntryString($entry), $wildcardHost);
        if ($found !== []) {
            $hosts[(string) $service] = $found;
        }
    }

    return $hosts;
}

function availApplicationServer(Application $application): ?Server
{
    try {
        return $application->destination?->server;
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Custom hosts of an application (its own domains plus every Compose service's), from the current or
 * the original attributes.
 *
 * @return list<string>
 */
function availApplicationCustomHosts(Application $application, bool $original = false): array
{
    $wildcardHost = availWildcardHost(availApplicationServer($application));
    $fqdn = $original ? $application->getRawOriginal('fqdn') : $application->fqdn;
    $compose = $original ? $application->getRawOriginal('docker_compose_domains') : $application->docker_compose_domains;

    return collect(availCustomHostsIn($fqdn, $wildcardHost))
        ->merge(collect(availCustomHostsInComposeDomains($compose, $wildcardHost))->flatten())
        ->unique()
        ->values()
        ->all();
}

/** Deployed from Git: a public repository or the GitHub App (not a pasted Dockerfile, a Docker image or a deploy key). */
function availApplicationIsGitBased(Application $application): bool
{
    if ($application->build_pack === 'dockerimage' || filled($application->dockerfile) || blank($application->git_repository)) {
        return false;
    }
    if ((int) ($application->private_key_id ?? 0) > 0) {
        return false;
    }
    $sourceType = $application->source_type;

    return $sourceType === null || $sourceType === \App\Models\GithubApp::class || $sourceType === \App\Models\GitlabApp::class;
}

function availEnvironmentIsProduction(?Environment $environment): bool
{
    return $environment !== null && strtolower(trim((string) $environment->name)) === 'production';
}

function availCustomDomainExample(?string $wildcardHost): string
{
    return $wildcardHost ? "https://my-app.{$wildcardHost}" : 'the generated address';
}

/** Why this application may not have custom domains, or null when it may. */
function availApplicationCustomDomainRuleError(Application $application): ?string
{
    $example = availCustomDomainExample(availWildcardHost(availApplicationServer($application)));
    if (! availApplicationIsGitBased($application)) {
        return "Custom domains are only for apps deployed from a Git repository (public repository or GitHub App). Use an address like {$example}.";
    }
    $environment = $application->environment_id ? Environment::find($application->environment_id) : null;
    if (! availEnvironmentIsProduction($environment)) {
        return "Custom domains are only for production. Use an address like {$example} here.";
    }

    return null;
}

/**
 * Checked before an application is saved: new custom domains need an app that may have them, and an
 * app with custom domains may not stop qualifying (moved out of production, switched to a Docker
 * image, ...). Custom domains that were already there where they are not allowed may stay.
 */
function availApplicationCustomDomainError(Application $application): ?string
{
    if (! availCustomDomainRulesEnabled()) {
        return null;
    }
    $qualificationFields = ['environment_id', 'build_pack', 'dockerfile', 'git_repository', 'private_key_id', 'source_id', 'source_type', 'destination_id', 'destination_type'];
    // Most saves (status updates, settings) touch none of this: no queries for them.
    if ($application->exists && ! $application->isDirty(['fqdn', 'docker_compose_domains', ...$qualificationFields])) {
        return null;
    }
    $current = availApplicationCustomHosts($application);
    if ($current === []) {
        return null;
    }
    $previous = $application->exists ? availApplicationCustomHosts($application, original: true) : [];
    $added = array_values(array_diff($current, $previous));
    $qualificationChanged = $application->exists && $application->isDirty($qualificationFields);

    if ($added === [] && ! $qualificationChanged) {
        return null;
    }
    $error = availApplicationCustomDomainRuleError($application);
    if ($error === null) {
        return null;
    }
    if ($added === []) {
        // Only the qualification changed. Refuse when the app qualified before; an app that already
        // didn't qualify keeps its domains.
        $before = (new Application)->setRawAttributes($application->getRawOriginal(), true);
        if (availApplicationCustomDomainRuleError($before) !== null) {
            return null;
        }

        return 'This app has custom domains ('.implode(', ', $current).'). Remove them first. '.$error;
    }

    return $error;
}

/**
 * Members don't remove custom domains themselves: they ask the Owner (availRequestDomainRemoval()).
 * Admins, the Owner and background work (no signed-in user) may.
 */
function availCustomDomainRemovalError(Application $application): ?string
{
    if (! availCustomDomainRulesEnabled() || ! $application->exists || ! $application->isDirty(['fqdn', 'docker_compose_domains'])) {
        return null;
    }
    $user = auth()->user();
    if (! $user || availIsAdminOfResource($user, $application)) {
        return null;
    }
    $removed = array_values(array_diff(availApplicationCustomHosts($application, original: true), availApplicationCustomHosts($application)));
    if ($removed === []) {
        return null;
    }

    return 'Only the Owner removes custom domains ('.implode(', ', $removed).'). Use Request removal on the Domains page.';
}

/** New custom domains on a PR preview need a parent app that may have them, or must derive from the parent's own custom domains. */
function availPreviewCustomDomainError(ApplicationPreview $preview): ?string
{
    if (! availCustomDomainRulesEnabled()) {
        return null;
    }
    $application = $preview->application;
    if (! $application) {
        return null;
    }
    $wildcardHost = availWildcardHost(availApplicationServer($application));
    $hostsOf = fn (?string $fqdn, mixed $compose) => collect(availCustomHostsIn($fqdn, $wildcardHost))
        ->merge(collect(availCustomHostsInComposeDomains($compose, $wildcardHost))->flatten())
        ->unique()->values()->all();

    $current = $hostsOf($preview->fqdn, $preview->docker_compose_domains);
    $previous = $preview->exists ? $hostsOf($preview->getRawOriginal('fqdn'), $preview->getRawOriginal('docker_compose_domains')) : [];
    $added = array_values(array_diff($current, $previous));
    if ($added === [] || availApplicationCustomDomainRuleError($application) === null) {
        return null;
    }
    $parentHosts = availApplicationCustomHosts($application);
    foreach ($added as $host) {
        if (! collect($parentHosts)->contains(fn ($parent) => str_ends_with($host, '.'.$parent))) {
            return availApplicationCustomDomainRuleError($application);
        }
    }

    return null;
}

function availServiceApplicationServer(ServiceApplication $serviceApplication): ?Server
{
    try {
        return $serviceApplication->service?->server;
    } catch (\Throwable) {
        return null;
    }
}

/** One-click services never get new custom domains. */
function availServiceApplicationCustomDomainError(ServiceApplication $serviceApplication, ?string $fqdn = null): ?string
{
    if (! availCustomDomainRulesEnabled()) {
        return null;
    }
    $wildcardHost = availWildcardHost(availServiceApplicationServer($serviceApplication));
    $current = availCustomHostsIn($fqdn ?? $serviceApplication->fqdn, $wildcardHost);
    $previous = $serviceApplication->exists ? availCustomHostsIn($serviceApplication->getRawOriginal('fqdn'), $wildcardHost) : [];
    if (array_diff($current, $previous) === []) {
        return null;
    }
    $example = availCustomDomainExample($wildcardHost);

    return "Custom domains are only for apps deployed from a Git repository, not for services. Use an address like {$example}.";
}

/** A production environment can't be renamed while its apps have custom domains. */
function availEnvironmentRenameError(Environment $environment): ?string
{
    if (! availCustomDomainRulesEnabled()) {
        return null;
    }
    if (! $environment->exists || ! $environment->isDirty('name')) {
        return null;
    }
    $wasProduction = strtolower(trim((string) $environment->getRawOriginal('name'))) === 'production';
    if (! $wasProduction || availEnvironmentIsProduction($environment)) {
        return null;
    }
    $hosts = Application::where('environment_id', $environment->id)->get()
        ->flatMap(fn (Application $application) => availApplicationCustomHosts($application))
        ->unique()->values();
    if ($hosts->isEmpty()) {
        return null;
    }

    return 'Apps in this environment have custom domains ('.$hosts->implode(', ').'), which are only for production. Remove them before renaming the environment.';
}

/** Clone: drop the custom domains the new app may not have; generate an address when none is left. */
function availDropDisallowedCustomDomains(Application $application): void
{
    if (! availCustomDomainRulesEnabled()) {
        return;
    }
    if (availApplicationCustomHosts($application) === [] || availApplicationCustomDomainRuleError($application) === null) {
        return;
    }
    $server = availApplicationServer($application);
    $wildcardHost = availWildcardHost($server);
    $keep = fn (?string $domains) => collect(explode(',', (string) $domains))
        ->map(fn ($url) => trim($url))
        ->filter(fn ($url) => $url !== '' && ($host = availUrlHost($url)) !== null && ! availIsCustomDomainHost($host, $wildcardHost))
        ->implode(',');

    if (filled($application->fqdn)) {
        $application->fqdn = $keep($application->fqdn) ?: null;
        if ($application->fqdn === null && $application->build_pack !== 'dockercompose' && $server) {
            $application->fqdn = availAppUrl($server, $application);
        }
    }
    $compose = json_decode((string) $application->docker_compose_domains, true);
    if (is_array($compose)) {
        foreach ($compose as $service => $entry) {
            if (is_array($entry)) {
                $compose[$service]['domain'] = $keep(composeDomainEntryString($entry));
            }
        }
        $application->docker_compose_domains = json_encode($compose);
    }
}

function availThrowCustomDomainError(?string $error): void
{
    if ($error !== null) {
        throw new AvailCustomDomainException($error);
    }
}

/**
 * Whether a Cloudflare-proxied answer counts as correct DNS for this host. A custom root domain gets an
 * A record that must be DNS only (grey cloud); a custom subdomain is a CNAME to the app's address and
 * is proxied exactly when that address is.
 */
function availCloudflareProxyAllowed(string $host, ?Server $server): bool
{
    if (! availCustomDomainRulesEnabled() || ! availIsCustomDomainHost($host, availWildcardHost($server))) {
        return true;
    }

    return ! availIsRootDomain($host);
}

function availProxiedCustomDomainMessage(): string
{
    return 'This root domain is proxied by Cloudflare (orange cloud). Its A record must be DNS only: click the cloud next to the record in Cloudflare until it is grey, then check again.';
}

// --- Public IP for DNS ---------------------------------------------------------------------------

/** The Owner-set public IP that DNS records for this server should use (Servers -> General), or null. */
function availServerDnsPublicIp(?Server $server): ?string
{
    if (! $server) {
        return null;
    }
    $ip = trim((string) ServerSetting::where('server_id', $server->id)->value('avail_dns_public_ip'));

    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
}

/** The public IP for the records of a server: the Owner-set one, else the server's own address when public. */
function availDnsRecordIp(?Server $server): ?string
{
    if (! $server) {
        return null;
    }
    $ip = serverDnsTargetIp($server);

    return is_string($ip) && DnsRecordHints::isPublicAddress($ip) ? $ip : null;
}

// --- Cloudflare records ----------------------------------------------------------------------------

/**
 * The DNS zone of a host and its name inside that zone, e.g. test.abc.xyz -> abc.xyz + test. Found by
 * walking up the name until a level answers with its own SOA record; falls back to the last two
 * labels. Cached for a day (an hour when the lookup failed).
 *
 * @return array{zone: string, name: string, guessed: bool}
 */
function availDnsZoneFor(string $host): array
{
    $host = strtolower(rtrim($host, '.'));
    $labels = explode('.', $host);
    $fallbackZone = implode('.', array_slice($labels, -2));
    $fallback = ['zone' => $fallbackZone, 'name' => availRelativeDnsName($host, $fallbackZone), 'guessed' => true];
    if (count($labels) < 2) {
        return $fallback;
    }

    $cacheKey = 'avail:dns-zone:'.$host;
    $cached = Cache::get($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    $dnsServer = trim((string) str((string) data_get(instanceSettings(), 'custom_dns_servers'))->explode(',')->first()) ?: '1.1.1.1';
    for ($i = 0; $i < count($labels) - 1; $i++) {
        $candidate = implode('.', array_slice($labels, $i));
        try {
            $records = queryDnsServer($dnsServer, $candidate, DNSTypes::NAME_SOA, 3);
        } catch (\Throwable) {
            break;
        }
        if ($records === false) {
            continue;
        }
        foreach ($records as $record) {
            if ($record->getType() === DNSTypes::NAME_SOA && strtolower(rtrim($record->getDomain(), '.')) === $candidate) {
                $result = ['zone' => $candidate, 'name' => availRelativeDnsName($host, $candidate), 'guessed' => false];
                Cache::put($cacheKey, $result, now()->addDay());

                return $result;
            }
        }
    }
    Cache::put($cacheKey, $fallback, now()->addHour());

    return $fallback;
}

function availRelativeDnsName(string $host, string $zone): string
{
    if ($host === $zone) {
        return '@';
    }

    return str_ends_with($host, '.'.$zone) ? substr($host, 0, -strlen('.'.$zone)) : $host;
}

/**
 * The records to add in Cloudflare: one A (or AAAA) record per custom host, DNS only, plus a wildcard
 * below each host whose PR previews are named after it (previews get <pr>.<host>).
 *
 * @param  list<string>  $hosts
 * @param  list<string>  $previewParents
 * @return list<array{type: string, name: string, zone: string, fqdn: string, value: string, proxy: string, ttl: string, purpose: string, zone_guessed: bool}>
 */
function availCloudflareRecords(array $hosts, array $previewParents, ?string $ip): array
{
    if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return [];
    }
    $type = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'AAAA' : 'A';
    $records = [];
    $add = function (string $host, bool $previews) use (&$records, $type, $ip) {
        $zone = availDnsZoneFor($host);
        $name = $zone['name'];
        $fqdn = $host;
        if ($previews) {
            $name = $name === '@' ? '*' : '*.'.$name;
            $fqdn = '*.'.$host;
        }
        $records[$type.'|'.$fqdn] = [
            'type' => $type,
            'name' => $name,
            'zone' => $zone['zone'],
            'fqdn' => $fqdn,
            'value' => $ip,
            'proxy' => 'DNS only',
            'ttl' => 'Auto',
            'purpose' => $previews ? 'PR previews' : 'Domain',
            'zone_guessed' => $zone['guessed'],
        ];
    };
    foreach ($hosts as $host) {
        $add($host, false);
    }
    foreach ($previewParents as $host) {
        $add($host, true);
    }

    return array_values($records);
}

/**
 * Hosts whose PR previews are named after them: the first domain of the app (or of each Compose
 * service), when previews are on and that domain is custom.
 *
 * @return list<string>
 */
function availPreviewParentHosts(Application $application): array
{
    if (! $application->settings?->is_preview_deployments_enabled) {
        return [];
    }
    $wildcardHost = availWildcardHost(availApplicationServer($application));
    $firsts = [availUrlHost(explode(',', (string) $application->fqdn)[0] ?? null)];
    foreach ((array) json_decode((string) $application->docker_compose_domains, true) as $entry) {
        $firsts[] = availUrlHost(explode(',', (string) composeDomainEntryString($entry))[0] ?? null);
    }

    return collect($firsts)
        ->filter(fn ($host) => $host !== null && availIsCustomDomainHost($host, $wildcardHost))
        ->unique()->values()->all();
}

/** A root domain (abc.com), as opposed to a subdomain (test.abc.com), going by its DNS zone. */
function availIsRootDomain(string $host): bool
{
    return availDnsZoneFor($host)['name'] === '@';
}

/**
 * Where a custom subdomain's CNAME points: the app's own address under the server's wildcard domain
 * (its first one, else the address it would be given). Anything under the wildcard reaches the server.
 */
function availApplicationCnameTarget(Application $application): ?string
{
    $wildcardHost = availWildcardHost(availApplicationServer($application));
    if ($wildcardHost === null) {
        return null;
    }
    $domains = collect(explode(',', (string) $application->fqdn));
    foreach ((array) json_decode((string) $application->docker_compose_domains, true) as $entry) {
        $domains = $domains->merge(explode(',', (string) composeDomainEntryString($entry)));
    }
    $own = $domains->map(fn ($url) => availUrlHost($url))
        ->first(fn ($host) => $host !== null && str_ends_with($host, '.'.$wildcardHost) && substr_count(substr($host, 0, -strlen('.'.$wildcardHost)), '.') === 0);
    if ($own !== null) {
        return $own;
    }
    $label = availAppHostLabels($application)[0] ?? null;

    return $label ? "{$label}.{$wildcardHost}" : null;
}

/** Whether a host answers with Cloudflare addresses (orange cloud). Cached for 10 minutes. */
function availHostIsProxied(string $host): bool
{
    return (bool) Cache::remember('avail:dns-proxied:'.strtolower($host), now()->addMinutes(10), function () use ($host) {
        $dnsServer = trim((string) str((string) data_get(instanceSettings(), 'custom_dns_servers'))->explode(',')->first()) ?: '1.1.1.1';
        try {
            $records = queryDnsServer($dnsServer, $host, DNSTypes::NAME_A, 3);
        } catch (\Throwable) {
            return false;
        }
        foreach ($records === false ? [] : $records as $record) {
            if ($record->getType() === DNSTypes::NAME_A && isCloudflareIp($record->getData())) {
                return true;
            }
        }

        return false;
    });
}

/** The connected Cloudflare zone that creates this host's records automatically, if any. */
function availAutomaticDnsZone(?int $teamId, string $host): ?\App\Models\DnsProviderZone
{
    if ($teamId === null) {
        return null;
    }
    try {
        $zones = app(\App\Services\Dns\CloudflareDnsProvider::class)->findZones($teamId, ltrim($host, '*.'));
    } catch (\Throwable) {
        return null;
    }
    if ($zones->count() !== 1) {
        return null; // none, or the same zone through two tokens: left to the Owner
    }
    $zone = $zones->first();

    return $zone->integrationToken?->automaticDnsEnabled() ? $zone : null;
}

/**
 * The record a custom host needs, as Vercel does it:
 *   - root domain (abc.com): A to the server's public IP, DNS only
 *   - subdomain (test.abc.com): CNAME to the app's own address, proxied exactly when that address is.
 *     A proxied CNAME only works inside the same Cloudflare account, so when the zone isn't connected
 *     and the app's address is proxied, the subdomain falls back to an A record, DNS only.
 * $previews gives the wildcard below the host that its PR previews (<pr>.<host>) need.
 *
 * @return array{type: string, name: string, zone: string, fqdn: string, value: string, proxied: bool, proxy: string, ttl: string, purpose: string, zone_guessed: bool, zone_id: ?int, automatic: bool}|null
 */
function availDesiredRecord(Application $application, string $host, bool $previews = false): ?array
{
    $zone = availDnsZoneFor($host);
    $managedZone = availAutomaticDnsZone($application->team()?->id, $host);
    $root = $zone['name'] === '@';
    $target = $root ? null : availApplicationCnameTarget($application);
    $targetProxied = $target !== null && availHostIsProxied($target);
    $useCname = $target !== null && (! $targetProxied || $managedZone !== null);

    if ($useCname) {
        $type = 'CNAME';
        $value = $target;
        $proxied = $targetProxied;
    } else {
        $value = availDnsRecordIp(availApplicationServer($application));
        if ($value === null) {
            return null;
        }
        $type = filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'AAAA' : 'A';
        $proxied = false;
    }
    $name = $zone['name'];
    $fqdn = $host;
    if ($previews) {
        $name = $name === '@' ? '*' : '*.'.$name;
        $fqdn = '*.'.$host;
    }

    return [
        'type' => $type,
        'name' => $name,
        'zone' => $managedZone?->name ?? $zone['zone'],
        'fqdn' => $fqdn,
        'value' => $value,
        'proxied' => $proxied,
        'proxy' => $proxied ? 'Proxied (orange cloud)' : 'DNS only (grey cloud)',
        'ttl' => 'Auto',
        'purpose' => $previews ? 'PR previews' : 'Domain',
        'zone_guessed' => $managedZone === null && $zone['guessed'],
        'zone_id' => $managedZone?->id,
        'automatic' => $managedZone !== null,
    ];
}

/**
 * Records for an application's custom domains (plus the PR preview wildcards).
 *
 * @param  list<string>|null  $onlyHosts
 * @return list<array<string, mixed>>
 */
function availApplicationCloudflareRecords(Application $application, ?array $onlyHosts = null): array
{
    $hosts = availApplicationCustomHosts($application);
    $previewParents = availPreviewParentHosts($application);
    if ($onlyHosts !== null) {
        $hosts = array_values(array_intersect($hosts, $onlyHosts));
        $previewParents = array_values(array_intersect($previewParents, $onlyHosts));
    }
    $records = [];
    foreach ($hosts as $host) {
        $records[] = availDesiredRecord($application, $host);
    }
    foreach ($previewParents as $host) {
        $records[] = availDesiredRecord($application, $host, previews: true);
    }

    return array_values(array_filter($records));
}

/** Whether AvailCoolify created (and so manages) a DNS record with this name for the app. */
function availHasManagedRecord(Application $application, string $fqdn): bool
{
    return \App\Models\ManagedDnsRecord::query()
        ->where('name', strtolower($fqdn))
        ->whereHas('references', fn ($query) => $query->where('resource_type', $application->getMorphClass())->where('resource_id', $application->getKey()))
        ->exists();
}

/**
 * Create the records of new custom domains in their connected Cloudflare zone. Records whose zone
 * isn't connected are left for the Owner. Runs in AvailCustomDomainDnsJob.
 *
 * @param  list<string>  $hosts
 * @return list<array<string, mixed>>  each record with status: created, manual, conflict or failed, and a message
 */
function availProvisionCustomDomainDns(Application $application, array $hosts): array
{
    $results = [];
    foreach (availApplicationCloudflareRecords($application, $hosts) as $record) {
        if (! $record['automatic']) {
            $results[] = [...$record, 'status' => 'manual', 'message' => 'Add it in Cloudflare.'];

            continue;
        }
        try {
            $zone = \App\Models\DnsProviderZone::with('integrationToken')->findOrFail($record['zone_id']);
            app(\App\Services\Dns\CloudflareDnsProvider::class)->createRecord($zone, $record['fqdn'], $record['value'], $application, [], $record['proxied']);
            $results[] = [...$record, 'status' => 'created', 'message' => "Created in Cloudflare ({$zone->integrationToken->name})."];
        } catch (\App\Exceptions\DnsRecordConflictException $e) {
            $results[] = [...$record, 'status' => 'conflict', 'message' => "A record already points {$record['fqdn']} at {$e->currentValue}. Change or delete it in Cloudflare."];
        } catch (\Throwable $e) {
            $results[] = [...$record, 'status' => 'failed', 'message' => 'Could not be created: '.$e->getMessage()];
        }
    }

    return $results;
}

// --- Telling the Owner -----------------------------------------------------------------------------

/**
 * Tell the Owner that custom domains were added to or removed from a resource: the team's notification
 * channels (Slack, Discord, ...; the whole team never gets email) and an email to the Owner when
 * transactional email is set up.
 *
 * @param  list<string>  $added
 * @param  list<string>  $removed
 */
function availNotifyCustomDomainChange(Application|ServiceApplication $resource, array $added, array $removed): void
{
    if (! availCustomDomainRulesEnabled()) {
        return;
    }
    if ($added === [] && $removed === []) {
        return;
    }
    try {
        $team = $resource instanceof Application ? $resource->team() : $resource->service?->environment?->project?->team;
        if (! $team instanceof Team) {
            return;
        }
        if ($resource instanceof Application) {
            // New domains: their records are created (or listed for the Owner) first, then announced.
            if ($added !== []) {
                \App\Jobs\AvailCustomDomainDnsJob::dispatch($resource->id, $added)->afterCommit();
            }
            if ($removed !== []) {
                // The PR preview wildcards (*.<host>) AvailCoolify created go with their domain.
                app(\App\Services\Dns\ManagedDnsRecordCleanup::class)->queueReleaseOfRemovedHostnames(
                    $resource, array_map(fn ($host) => '*.'.$host, $removed), (int) $team->id,
                );
            }
            $added = [];
        }
        if ($added === [] && $removed === []) {
            return;
        }
        $notification = CustomDomainDnsChanged::for($resource, $added, $removed);
        $team->notify($notification);
        $notification->emailOwners();
    } catch (\Throwable $e) {
        Log::warning('Avail custom domain notice failed: '.$e->getMessage());
    }
}

// --- Removal requests (members ask, the Owner decides) -------------------------------------------

/** Ask the Owner to remove a custom domain from an app the member configures. */
function availRequestDomainRemoval(Application $application, string $host, \App\Models\User $user): \App\Models\AvailDomainRemovalRequest
{
    $host = strtolower($host);
    if (! in_array($host, availApplicationCustomHosts($application), true)) {
        throw new \RuntimeException("{$host} is not a custom domain of this app.");
    }
    $existing = \App\Models\AvailDomainRemovalRequest::pendingFor($application, $host);
    if ($existing) {
        return $existing;
    }
    $request = \App\Models\AvailDomainRemovalRequest::create([
        'team_id' => $application->team()?->id,
        'application_id' => $application->id,
        'host' => $host,
        'requested_by' => $user->id,
        'status' => 'pending',
    ]);
    auditLog('ui.application.custom_domain_removal_requested', ['application_uuid' => $application->uuid, 'host' => $host]);
    try {
        $notice = \App\Notifications\Avail\CustomDomainRemovalRequested::for($request);
        $application->team()?->notify($notice);
        $notice->emailOwners();
    } catch (\Throwable $e) {
        Log::warning('Avail removal request notice failed: '.$e->getMessage());
    }

    return $request;
}

/** The Owner approves: the domain (and the records AvailCoolify created for it) are removed. */
function availApproveDomainRemoval(\App\Models\AvailDomainRemovalRequest $request, \App\Models\User $owner): void
{
    if ($request->status !== 'pending') {
        throw new \RuntimeException('This request was already decided.');
    }
    $application = $request->application;
    if ($application) {
        $host = $request->host;
        $keep = fn (?string $domains) => collect(explode(',', (string) $domains))
            ->map(fn ($url) => trim($url))
            ->filter(fn ($url) => $url !== '' && availUrlHost($url) !== $host)
            ->implode(',');
        $application->fqdn = $keep($application->fqdn) ?: null;
        $compose = json_decode((string) $application->docker_compose_domains, true);
        if (is_array($compose)) {
            foreach ($compose as $service => $entry) {
                if (is_array($entry)) {
                    $compose[$service]['domain'] = $keep(composeDomainEntryString($entry));
                }
            }
            $application->docker_compose_domains = json_encode($compose);
        }
        if ($application->fqdn === null && $application->build_pack !== 'dockercompose' && ($server = availApplicationServer($application))) {
            $application->fqdn = availAppUrl($server, $application);
        }
        $application->save();
        if ($application->settings?->is_container_label_readonly_enabled) {
            $application->custom_labels = base64_encode(implode("\n", generateLabelsApplication($application)));
            $application->save();
        }
        app(\App\Services\Dns\ManagedDnsRecordCleanup::class)->releaseHostname($application, $host, (int) $request->team_id, true);
    }
    $request->update(['status' => 'approved', 'decided_by' => $owner->id, 'decided_at' => now()]);
    auditLog('ui.application.custom_domain_removal_approved', ['application_uuid' => $application?->uuid, 'host' => $request->host]);
    $request->emailRequester();
}

function availRejectDomainRemoval(\App\Models\AvailDomainRemovalRequest $request, \App\Models\User $owner): void
{
    if ($request->status !== 'pending') {
        throw new \RuntimeException('This request was already decided.');
    }
    $request->update(['status' => 'rejected', 'decided_by' => $owner->id, 'decided_at' => now()]);
    auditLog('ui.application.custom_domain_removal_rejected', ['application_uuid' => $request->application?->uuid, 'host' => $request->host]);
    $request->emailRequester();
}

/**
 * Custom domains the save in progress adds and removes (computed while saving, announced once saved),
 * or null when there are none.
 *
 * @return array{added: list<string>, removed: list<string>}|null
 */
function availCustomDomainChanges(Application|ServiceApplication $resource): ?array
{
    if (! availCustomDomainRulesEnabled()) {
        return null;
    }
    if ($resource instanceof Application) {
        if (! $resource->isDirty(['fqdn', 'docker_compose_domains']) && $resource->exists) {
            return null;
        }
        $after = availApplicationCustomHosts($resource);
        $before = $resource->exists ? availApplicationCustomHosts($resource, original: true) : [];
    } else {
        if (! $resource->isDirty('fqdn') && $resource->exists) {
            return null;
        }
        $wildcardHost = availWildcardHost(availServiceApplicationServer($resource));
        $after = availCustomHostsIn($resource->fqdn, $wildcardHost);
        $before = $resource->exists ? availCustomHostsIn($resource->getRawOriginal('fqdn'), $wildcardHost) : [];
    }
    $added = array_values(array_diff($after, $before));
    $removed = array_values(array_diff($before, $after));

    return $added === [] && $removed === [] ? null : ['added' => $added, 'removed' => $removed];
}
