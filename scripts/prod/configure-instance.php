<?php

/*
 * Idempotent post-deploy configuration of a running AvailCoolify instance (go-live checklist:
 * "Settings", "Clerk", "Cloudflare / Traefik"). Runs inside the coolify container:
 *
 *   docker exec -i --env-file <file> coolify php < scripts/prod/configure-instance.php
 *
 * Environment (everything optional; unset values are left alone):
 *   AVAIL_DASHBOARD_URL      https://deploy.example.com
 *   AVAIL_APPS_WILDCARD      https://apps.example.com (set on servers that have none yet)
 *   AVAIL_SSH_PORT           SSH port Coolify uses to reach its own host (server id 0)
 *   AVAIL_TRUST_CLOUDFLARE   1 = add Cloudflare's ranges as Traefik forwardedHeaders.trustedIPs
 *   CLERK_CLIENT_ID, CLERK_CLIENT_SECRET, CLERK_BASE_URL
 *   CF_EMAIL_TOKEN           Cloudflare API token (Email Sending: Edit); turns on transactional email over
 *                            Cloudflare's SMTP, sent as AVAIL_SMTP_FROM (default noreply@notify.avail.so)
 *   DRY_RUN                  1 = print what would change, save nothing
 */

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

$env = static function (string $key): ?string {
    $v = getenv($key);

    return ($v === false || $v === '') ? null : $v;
};
$dry = $env('DRY_RUN') === '1';
$failed = false;

echo $dry ? "DRY RUN: nothing will be saved\n" : "Applying configuration\n";

$secret = ['client_secret', 'smtp_password'];

/** Set attributes on a model, print each change (secrets masked), save unless dry-run. */
$apply = static function ($model, array $attributes, string $label) use ($dry, $secret): bool {
    $model->fill($attributes);
    $dirty = $model->getDirty();
    if ($dirty === []) {
        echo "  = $label: already as wanted\n";

        return false;
    }
    foreach ($dirty as $key => $new) {
        // getDirty() holds the stored (for encrypted casts: ciphertext) value; print the readable one.
        $shown = in_array($key, $secret, true) ? '********' : json_encode($model->getAttribute($key));
        echo "  ~ $label.$key -> $shown\n";
    }
    if ($dry) {
        $model->syncOriginal();

        return true;
    }
    $model->save();

    return true;
};

// --- Instance settings --------------------------------------------------------------------
echo "Instance settings\n";
$settings = InstanceSettings::first();
$dashboard = $env('AVAIL_DASHBOARD_URL');
$apply($settings, array_filter([
    'fqdn' => $dashboard ? rtrim($dashboard, '/') : null,
    'is_auto_update_enabled' => false,          // upgrades come only from the release pipeline
    'is_sponsorship_popup_enabled' => false,
    'is_registration_enabled' => true,          // Clerk-gated: only company emails auto-join
    'is_dashboard_force_https_enabled' => true, // Cloudflare is Full (strict)
], static fn ($v) => $v !== null), 'instance');

// --- Servers ------------------------------------------------------------------------------
$trusted = null;
if ($env('AVAIL_TRUST_CLOUDFLARE') === '1') {
    $ranges = [];
    foreach (['https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6'] as $url) {
        $body = Http::timeout(15)->get($url);
        if (! $body->successful()) {
            echo "  ! could not fetch $url; skipping Cloudflare trusted IPs\n";
            $ranges = [];
            $failed = true;
            break;
        }
        $ranges = array_merge($ranges, preg_split('/\s+/', trim($body->body())));
    }
    $trusted = $ranges === [] ? null : implode(',', $ranges);
}

foreach (Server::all() as $server) {
    echo "Server {$server->id} ({$server->name})\n";

    $serverChanges = [];
    if ($server->id === 0 && $env('AVAIL_SSH_PORT')) {
        $serverChanges['port'] = (int) $env('AVAIL_SSH_PORT');
    }
    if ($serverChanges !== []) {
        $apply($server, $serverChanges, 'server');
    }

    $ss = $server->settings;
    $ssChanges = [
        'force_docker_cleanup' => true,
        'docker_cleanup_frequency' => '0 0 * * *',
    ];
    if ($env('AVAIL_APPS_WILDCARD') && empty($ss->wildcard_domain)) {
        $ssChanges['wildcard_domain'] = rtrim($env('AVAIL_APPS_WILDCARD'), '/');
    }
    $apply($ss, $ssChanges, 'settings');

    if ($trusted !== null && $server->proxyType() === 'TRAEFIK') {
        try {
            $yaml = Yaml::parse(GetProxyConfiguration::run($server));
            $command = $yaml['services']['traefik']['command'] ?? null;
            if (! is_array($command)) {
                throw new RuntimeException('no traefik command list in the proxy configuration');
            }
            $before = $command;
            // Replace in place (so a re-run is a no-op), append when missing.
            foreach (['http', 'https'] as $entrypoint) {
                $line = "--entrypoints.$entrypoint.forwardedHeaders.trustedIPs=$trusted";
                $found = false;
                foreach ($command as $i => $existing) {
                    if (str_starts_with($existing, "--entrypoints.$entrypoint.forwardedHeaders.trustedIPs=")) {
                        $command[$i] = $line;
                        $found = true;
                    }
                }
                if (! $found) {
                    $command[] = $line;
                }
            }
            if ($command === $before) {
                echo "  = proxy: Cloudflare trusted IPs already set\n";
            } else {
                echo "  ~ proxy: Cloudflare trusted IPs on http+https (restart the proxy to apply on a live server)\n";
                if (! $dry) {
                    $yaml['services']['traefik']['command'] = $command;
                    SaveProxyConfiguration::run($server, Yaml::dump($yaml, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
                }
            }
        } catch (Throwable $e) {
            echo "  ! proxy configuration not updated: {$e->getMessage()}\n";
            $failed = true;
        }
    }

    if ($server->id === 0 && $dashboard) {
        try {
            if (! $dry) {
                $server->setupDynamicProxyConfiguration(); // dashboard router (coolify.yaml) for the new URL
            }
            echo "  ~ proxy: dashboard route for $dashboard\n";
        } catch (Throwable $e) {
            echo "  ! dashboard route not written yet (server not reachable?): {$e->getMessage()}\n";
            echo "    re-run this job once the server is validated\n";
        }
    }
}

// --- Clerk --------------------------------------------------------------------------------
echo "Clerk\n";
$clerk = OauthSetting::firstOrNew(['provider' => 'clerk']);
$clerkWanted = array_filter([
    'enabled' => true,
    'client_id' => $env('CLERK_CLIENT_ID'),
    'client_secret' => $env('CLERK_CLIENT_SECRET'),
    'base_url' => $env('CLERK_BASE_URL') ? rtrim($env('CLERK_BASE_URL'), '/') : null,
    'redirect_uri' => $dashboard ? rtrim($dashboard, '/').'/auth/clerk/callback' : null,
    'allow_registration' => true,
    'auto_join_root_team' => true,   // only AVAIL_AUTO_JOIN_DOMAINS emails actually auto-join
    'require_email_verified' => true,
    'use_pkce' => true,
], static fn ($v) => $v !== null);
if (empty($clerkWanted['client_id']) && empty($clerk->client_id)) {
    echo "  ! no Clerk client id/secret provided; Clerk stays disabled (nobody can log in!)\n";
    $failed = true;
    unset($clerkWanted['enabled']);
}
if ($clerk->exists === false && ! $dry) {
    $clerk->save();
}
$apply($clerk, $clerkWanted, 'clerk');
if (empty($clerk->base_url) || empty($clerk->client_id)) {
    echo "  ! Clerk base URL / client id still empty; 4.4.0 refuses logins without them\n";
    $failed = true;
}

// --- Transactional email ------------------------------------------------------------------
// Cloudflare Email Sending over SMTP: port 465 is implicit TLS, the user name is the literal "api_token" and
// the password is the token. The sending domain (notify.avail.so) is set up once in Cloudflare.
echo "Email\n";
if ($env('CF_EMAIL_TOKEN')) {
    $apply($settings, [
        'smtp_enabled' => true,
        'smtp_host' => 'smtp.mx.cloudflare.net',
        'smtp_port' => 465,
        'smtp_encryption' => 'tls',
        'smtp_username' => 'api_token',
        'smtp_password' => $env('CF_EMAIL_TOKEN'),
        'smtp_from_address' => $env('AVAIL_SMTP_FROM') ?? 'noreply@notify.avail.so',
        'smtp_from_name' => 'AvailCoolify',
        'resend_enabled' => false,
    ], 'email');
} else {
    echo "  = no CF_EMAIL_TOKEN; email settings left as they are\n";
}

echo $failed ? "Finished with warnings\n" : "Done\n";
exit($failed ? 1 : 0);
