<?php

/*
 * Validates Coolify's own server (id 0, "localhost") and starts its proxy (Traefik) when it is not running.
 * Idempotent. Runs inside the coolify container:
 *
 *   docker exec -i coolify php < scripts/prod/localhost-proxy.php
 *
 * Coolify validates localhost once, on first boot. If that attempt fails (for example because SSH to the
 * host was not possible yet), nothing retries it, the proxy never starts and the dashboard is unreachable
 * (Cloudflare 521). This does what the UI's Validate button does.
 */

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Models\Server;

$server = Server::findOrFail(0);

['uptime' => $up, 'error' => $error] = $server->validateConnection();
if (! $up) {
    fwrite(STDERR, "Coolify cannot reach its own host over SSH: {$error}\n");
    exit(1);
}
if (! $server->validateDockerEngine() || ! $server->validateDockerEngineVersion()) {
    fwrite(STDERR, "Docker on the host is missing or too old\n");
    exit(1);
}
$server->update(['is_validating' => false]);

// CheckProxy says whether the proxy should be started, i.e. it is configured and not running.
if (CheckProxy::run($server, true)) {
    instant_remote_process(ensureProxyNetworksExist($server)->toArray(), $server, false);
    StartProxy::run($server, false);
    echo "Proxy started\n";
} else {
    echo "Proxy already running\n";
}

$server->refresh();
echo 'localhost: reachable='.var_export((bool) $server->settings->is_reachable, true)
    .' usable='.var_export((bool) $server->settings->is_usable, true)."\n";
