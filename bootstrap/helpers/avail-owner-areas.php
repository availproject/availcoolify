<?php

/*
 * Avail: who may open which part of the dashboard (reviewed 2026-10-13).
 *
 *   owner   servers, terminals, sources, destinations, registries, S3 storage, notifications, private keys,
 *           cloud tokens, integration tokens, cloud-init scripts, and six Settings sections
 *   admin   shared variables, team audit log / admin view / danger zone, Settings > Access protection and
 *           Guest access (members view the Team overview and member list read-only)
 *   others  everyone in the team (dashboard, projects, apps, analytics, tags, API tokens, ...)
 *
 * "Owner" for team resources is the owner of the team being viewed; for the instance-wide Settings it is
 * the owner of Avail Team (team 0). AvailCoolify has only Avail Team, so both are the same person.
 *
 * One list drives everything: the page middleware (also re-run for Livewire calls made from those pages),
 * the API middleware, the sidebar, the page switcher, the Settings and Keys & Tokens menus and Ctrl+K search.
 */

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** @return array<string, list<string>> route-name patterns per level */
function availAreaRoutes(): array
{
    return [
        'platform' => [
            'settings.index', 'settings.advanced', 'settings.updates', 'settings.backup', 'settings.email',
            'settings.oauth', 'settings.oauth.*',
        ],
        'owner' => [
            'server.*', 'registries.index', 'terminal',
            'project.application.command', 'project.database.command', 'project.service.command',
            'source.*', 'destination.*', 'storage.*', 'notifications.*',
            'security.private-key.*', 'security.cloud-tokens', 'security.cloud-tokens.*',
            'security.integration-tokens', 'security.cloud-init-scripts', 'security.cloud-init-scripts.*',
        ],
        'admin' => [
            'shared-variables.*', 'settings.access-protection', 'settings.guest-access',
            'team.audit-log', 'team.admin-view', 'team.danger-zone',
        ],
    ];
}

/** @return array<string, list<string>> API path patterns (after /api/v1/) per level */
function availAreaApiPaths(): array
{
    return [
        'platform' => ['enable', 'disable', 'mcp/*', 'settings/*'],
        'owner' => [
            'servers', 'servers/*', 'destinations', 'destinations/*', 'security/keys', 'security/keys/*',
            'security/integration-tokens', 'security/integration-tokens/*', 'cloud-tokens', 'cloud-tokens/*',
            'cloud-init-scripts', 'cloud-init-scripts/*', 'hetzner/*', 'digitalocean/*', 'vultr/*',
            'github-apps', 'github-apps/*', 'gitlab-apps', 'gitlab-apps/*', 's3-storages', 's3-storages/*',
            'notifications/*',
        ],
        'admin' => ['team/envs', 'team/envs/*'],
    ];
}

/** The level a named route needs, or null when everyone in the team may open it. */
function availAreaForRoute(?string $routeName): ?string
{
    if (blank($routeName)) {
        return null;
    }
    foreach (availAreaRoutes() as $level => $patterns) {
        if (Str::is($patterns, $routeName)) {
            return $level;
        }
    }

    return null;
}

/** The level an API path (e.g. "api/v1/servers/abc") needs, or null. */
function availAreaForApiPath(string $path): ?string
{
    $path = (string) preg_replace('#^/?api/v\d+/#', '', trim($path, '/'));
    foreach (availAreaApiPaths() as $level => $patterns) {
        if (Str::is($patterns, $path)) {
            return $level;
        }
    }

    return null;
}

/**
 * Does the user meet the level? $teamId is the team being acted on (the current team in the browser,
 * the token's team in the API).
 */
function availMeetsArea(?User $user, ?string $level, ?int $teamId = null): bool
{
    if ($level === null) {
        return true;
    }
    if (! $user) {
        return false;
    }
    $teamId ??= currentTeam()?->id;
    $role = $teamId === null ? null : $user->roleInTeam($teamId);

    return match ($level) {
        'platform' => $user->roleInTeam(0) === 'owner',
        'owner' => $role === 'owner',
        'admin' => in_array($role, ['owner', 'admin'], true),
        default => false,
    };
}

/** May the signed-in user open this named route? Used to hide links. */
function availCanOpenRoute(string $routeName): bool
{
    return availMeetsArea(auth()->user(), availAreaForRoute($routeName));
}

/** May the signed-in user open this URL? Used for menu entries that only carry a link. */
function availCanOpenUrl(?string $url): bool
{
    if (blank($url)) {
        return true;
    }
    try {
        $route = Route::getRoutes()->match(request()->create($url));
    } catch (\Throwable) {
        return true;
    }

    return availCanOpenRoute((string) $route->getName());
}

/** Owner of the team being viewed (team resources: servers, keys, sources, ...). */
function availIsTeamOwner(?User $user = null): bool
{
    return availMeetsArea($user ?? auth()->user(), 'owner');
}

/*
 * Avail: where admins and members may put new resources. The Coolify host itself (server 0, "localhost":
 * Coolify, its database and Traefik) is for the owner only, as long as the team has another server that
 * can host resources. With a single server (e.g. a one-VM install) that server stays available to all.
 */

/** Servers offered when creating a resource: the Coolify host is left out for non-owners when another server exists. */
function availDeployableServers(\Illuminate\Support\Collection $servers, ?User $user = null): \Illuminate\Support\Collection
{
    $user ??= auth()->user();
    $host = $servers->first(fn ($server) => (int) $server->id === 0);
    if (! $host || availMeetsArea($user, 'owner', (int) $host->team_id)) {
        return $servers;
    }
    $others = $servers->reject(fn ($server) => (int) $server->id === 0)->values();

    return $others->isNotEmpty() ? $others : $servers;
}

/** May the user create a resource on this server? Checked again when the resource is created (UI and API). */
function availMayDeployToServer(?\App\Models\Server $server, ?User $user = null): bool
{
    if (! $server || (int) $server->id !== 0) {
        return true;
    }
    if (availMeetsArea($user ?? auth()->user(), 'owner', (int) $server->team_id)) {
        return true;
    }

    return ! availTeamHasAppServer((int) $server->team_id);
}

/** Does the team have a server other than the Coolify host that can take resources? */
function availTeamHasAppServer(int $teamId): bool
{
    return \App\Models\Server::query()
        ->where('team_id', $teamId)
        ->where('id', '!=', 0)
        ->whereRelation('settings', 'is_reachable', true)
        ->whereRelation('settings', 'is_usable', true)
        ->whereRelation('settings', 'force_disabled', false)
        ->whereRelation('settings', 'is_swarm_worker', false)
        ->get()
        ->contains(fn (\App\Models\Server $server) => $server->canHostResources());
}
