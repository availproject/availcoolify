<?php

/*
 * Avail: readable application URLs. A new application gets
 *
 *   https://<repo>-<6 characters of its uuid>[-<environment>].<server wildcard domain>
 *
 * e.g. https://autobot-al2ft4.apps.avail.so (production) or https://autobot-al2ft4-stag.apps.avail.so
 * instead of upstream's https://<uuid>.<wildcard>. "production" adds nothing and "staging" becomes
 * "stag"; other environment names are used as they are. Docker image apps use the image name, apps without a repository use their own name. If the host
 * is taken, the next 6 characters of the uuid are used. Compose apps and one-click services keep
 * upstream's naming.
 */

use App\Models\Application;
use App\Models\Server;

function availAppUrl(Server $server, Application $application): string
{
    foreach (availAppHostLabels($application) as $label) {
        $url = generateUrl(server: $server, random: $label);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== '' && ! availHostUsedElsewhere($host, $application)) {
            return $url;
        }
    }

    return generateUrl(server: $server, random: (string) $application->uuid);
}

/**
 * Host labels to try, in order: the first 6 characters of the uuid, then the next 6, and so on.
 *
 * @return list<string>
 */
function availAppHostLabels(Application $application): array
{
    $name = availAppUrlName($application);
    $environment = availAppUrlEnvironment($application);
    $uuid = availUrlSlug((string) $application->uuid);

    $labels = [];
    for ($offset = 0; $offset + 6 <= strlen($uuid); $offset += 6) {
        $labels[] = availAppHostLabel($name, substr($uuid, $offset, 6), $environment);
    }
    $labels[] = availAppHostLabel($name, new_public_id(6), $environment);

    return array_values(array_unique($labels));
}

/** One DNS label (at most 63 characters): the name is shortened first, the id and environment stay whole. */
function availAppHostLabel(string $name, string $id, string $environment): string
{
    $environment = rtrim(substr($environment, 0, 20), '-');
    $room = 63 - strlen($id) - 1 - ($environment === '' ? 0 : strlen($environment) + 1);
    $name = rtrim(substr($name, 0, max(1, $room)), '-');

    return implode('-', array_filter([$name !== '' ? $name : 'app', $id, $environment], fn (string $part) => $part !== ''));
}

function availAppUrlName(Application $application): string
{
    $repository = (string) $application->git_repository;
    // Dockerfile-only apps carry upstream's placeholder repository.
    $placeholderRepository = $repository === 'coollabsio/coolify' && $application->build_pack === 'dockerfile' && filled($application->dockerfile);

    if ($application->build_pack === 'dockerimage' && filled($application->docker_registry_image_name)) {
        $image = preg_replace('/@.*$/', '', (string) $application->docker_registry_image_name);
        $source = preg_replace('/:[^:]*$/', '', last(explode('/', $image)));
    } elseif ($repository !== '' && ! $placeholderRepository) {
        $parts = preg_split('#[/:]#', rtrim($repository, '/'));
        $source = preg_replace('/\.git$/i', '', (string) last($parts));
    } else {
        // Upstream names these "dockerfile-<random id>"; the random part says nothing.
        $source = preg_replace('/-[a-z0-9]{20,}$/i', '', (string) $application->name);
    }

    $slug = availUrlSlug((string) $source);

    return $slug !== '' ? $slug : 'app';
}

function availAppUrlEnvironment(Application $application): string
{
    $name = strtolower(trim((string) data_get($application, 'environment.name')));

    return match ($name) {
        'production' => '',
        'staging' => 'stag',
        default => availUrlSlug($name),
    };
}

function availUrlSlug(string $value): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
}
