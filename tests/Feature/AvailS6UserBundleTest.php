<?php

// The production image lists its services in s6-overlay's user-bundles.d. With s6-overlay 3.2.3.2 (in the
// serversideup base image since 2026-10-08) an s6-rc.d/user directory makes /init rewrite its `type` file
// on every boot, which fails as www-data, so no service starts. Replaces the production half of the
// upstream ReverbAndTerminalPackagingTest check, which still looks in s6-rc.d/user.

$s6 = fn (string $path) => base_path('docker/production/etc/s6-overlay/'.$path);

it('lists every production service in the user bundle', function () use ($s6) {
    foreach (['db-migration', 'horizon', 'init-script', 'init-seeder', 'reverb', 'scheduler-worker', 'terminal-server'] as $service) {
        expect(file_exists($s6("user-bundles.d/user/contents.d/{$service}")))->toBeTrue("{$service} is not in the user bundle")
            ->and(is_dir($s6("s6-rc.d/{$service}")))->toBeTrue("{$service} has no service definition");
    }
});

it('does not bring back the deprecated s6-rc.d/user bundle', function () use ($s6) {
    expect(is_dir($s6('s6-rc.d/user')))->toBeFalse();
});

it('runs Reverb and the terminal server after the init script', function () use ($s6) {
    expect(file_get_contents($s6('s6-rc.d/reverb/run')))
        ->toContain('exec php artisan reverb:start --host=0.0.0.0 --port=${PUSHER_BACKEND_PORT:-6001}')
        ->and(file_get_contents($s6('s6-rc.d/terminal-server/run')))
        ->toContain('exec node /terminal/terminal-server.js')
        ->and(file_exists($s6('s6-rc.d/reverb/dependencies.d/init-script')))->toBeTrue()
        ->and(file_exists($s6('s6-rc.d/terminal-server/dependencies.d/init-script')))->toBeTrue();
});
