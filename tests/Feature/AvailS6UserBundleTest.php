<?php

// The base image and the place s6-overlay reads the service list from must change together, or the
// container starts nginx and PHP-FPM only (no migrations, Horizon, scheduler, Reverb or terminal):
// - serversideup PHP v4 (pinned upstream to v4.5.1 in f55efc859) starts what s6-rc.d/user/contents.d lists;
// - v5 (s6-overlay 3.2.3.2) reads user-bundles.d/user/contents.d and rewrites s6-rc.d/user/type on every
//   boot, which fails as www-data (3fe93dd13), so s6-rc.d/user must not exist there.

$s6 = fn (string $path) => base_path('docker/production/etc/s6-overlay/'.$path);
$services = ['db-migration', 'horizon', 'init-script', 'init-seeder', 'reverb', 'scheduler-worker', 'terminal-server'];

it('lists every production service where the pinned base image looks for it', function () use ($s6, $services) {
    // Upstream names the base image by tag (SERVERSIDEUP_PHP_VERSION) or, for its patched rebuilds such as
    // ghcr.io/coollabsio/serversideup-php:8.4-fpm-nginx-alpine-v4.5.1-hotfix, by full image (SERVERSIDEUP_PHP_IMAGE).
    preg_match('/^ARG SERVERSIDEUP_PHP_(?:VERSION|IMAGE)=(\S+)$/m', file_get_contents(base_path('docker/production/Dockerfile')), $match);
    $tag = $match[1] ?? '';
    $isV4 = (bool) preg_match('/-v4\.\d+\.\d+(-[a-z0-9.]+)?$/', $tag);
    $bundle = $isV4 ? 's6-rc.d/user/contents.d' : 'user-bundles.d/user/contents.d';

    expect($tag)->not->toBe('');
    foreach ($services as $service) {
        expect(file_exists($s6("{$bundle}/{$service}")))->toBeTrue("{$service} is not listed in {$bundle} for base image {$tag}")
            ->and(is_dir($s6("s6-rc.d/{$service}")))->toBeTrue("{$service} has no service definition");
    }

    if ($isV4) {
        expect(is_dir($s6('user-bundles.d')))->toBeFalse('v4 ignores user-bundles.d; keep one list only');
    } else {
        expect(is_dir($s6('s6-rc.d/user')))->toBeFalse('s6-rc.d/user breaks startup on v5');
    }
});

it('runs Reverb and the terminal server after the init script', function () use ($s6) {
    expect(file_get_contents($s6('s6-rc.d/reverb/run')))
        ->toContain('exec php artisan reverb:start --host=0.0.0.0 --port=${PUSHER_BACKEND_PORT:-6001}')
        ->and(file_get_contents($s6('s6-rc.d/terminal-server/run')))
        ->toContain('exec node /terminal/terminal-server.js')
        ->and(file_exists($s6('s6-rc.d/reverb/dependencies.d/init-script')))->toBeTrue()
        ->and(file_exists($s6('s6-rc.d/terminal-server/dependencies.d/init-script')))->toBeTrue();
});
