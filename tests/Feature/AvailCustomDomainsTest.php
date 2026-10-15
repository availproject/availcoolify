<?php

use App\Actions\Shared\CheckDomainDns;
use App\Exceptions\AvailCustomDomainException;
use App\Livewire\Dashboard\AvailCustomDomains;
use App\Livewire\Project\Application\Domains;
use App\Livewire\Project\Application\PreviewDomains;
use App\Livewire\Project\EnvironmentEdit;
use App\Livewire\Project\Service\Domains as ServiceDomains;
use App\Livewire\Server\Show as ServerShow;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Avail\CustomDomainDnsChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PurplePixie\PhpDns\DNSQuery;
use PurplePixie\PhpDns\DNSResult;
use PurplePixie\PhpDns\DNSTypes;

/**
 * Avail: custom domains (outside the server's wildcard domain) only on Git-based apps in production,
 * records as on Vercel (CNAME to the app's address for subdomains, A for root domains) created through
 * the stored Cloudflare token or listed for the Owner, and members asking the Owner to remove them.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config(['avail.custom_domain_rules' => true]);
    Queue::fake();
    Notification::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], [
        'id' => 0, 'fqdn' => 'https://deploy.avail.test', 'is_api_enabled' => true,
        'is_dns_validation_enabled' => false, 'custom_dns_servers' => '192.0.2.53',
    ]));

    $this->owner = User::factory()->create(['id' => 0, 'email' => 'owner@availproject.org']);
    Team::find(0) ?? Team::unguarded(fn () => Team::create(['id' => 0, 'name' => 'Avail Team', 'personal_team' => true]));
    Team::whereKey(0)->update(['show_boarding' => false]);
    $this->team = Team::find(0);
    $this->owner->teams()->syncWithoutDetaching([0 => ['role' => 'owner']]);
    $this->team->slackNotificationSettings()->updateOrCreate(['team_id' => 0], ['slack_enabled' => true, 'slack_webhook_url' => 'https://hooks.slack.test/avail']);
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach(0, ['role' => 'admin']);

    // An app server reached over a private address, with the Owner-set public IP for DNS.
    $this->server = Server::factory()->create(['team_id' => 0, 'ip' => '10.0.0.5', 'name' => 'app-1']);
    $this->server->settings->forceFill([
        'wildcard_domain' => 'https://apps.avail.test', 'avail_dns_public_ip' => '203.0.113.10',
        'is_reachable' => true, 'is_usable' => true,
    ])->save();
    $this->destination = $this->server->standaloneDockers()->first() ?? StandaloneDocker::withoutEvents(fn () => StandaloneDocker::create([
        'uuid' => (string) Str::uuid(), 'name' => 'docker', 'network' => 'coolify', 'server_id' => $this->server->id,
    ]));
    $this->project = Project::factory()->create(['team_id' => 0, 'name' => 'Avail Project']);
    // A new project comes with its "production" environment.
    $this->production = Environment::where('project_id', $this->project->id)->where('name', 'production')->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id, 'name' => 'production']);
    $this->staging = Environment::where('project_id', $this->project->id)->where('name', 'staging')->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id, 'name' => 'staging']);

    $this->publicGithub = GithubApp::forceCreate([
        'uuid' => 'public-github', 'name' => 'Public GitHub', 'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com', 'is_public' => true, 'team_id' => 0,
    ]);

    // DNS: abc.xyz is a zone; nothing resolves unless a test says so.
    availFakeDns(['abc.xyz', 'example.co.uk', 'apps.avail.test']);
});

function availActAs(User $user): void
{
    test()->actingAs($user->fresh());
    session(['currentTeam' => Team::find(0)]);
}

/** @param  array<int, string>  $zones  @param  array<string, string>  $addresses */
function availFakeDns(array $zones, array $addresses = []): void
{
    app()->bind(DNSQuery::class, fn () => new class('192.0.2.53', $zones, $addresses) extends DNSQuery
    {
        public function __construct(string $server, private array $zones, private array $addresses)
        {
            parent::__construct($server);
        }

        public function query(string $question, string $typeName = DNSTypes::NAME_A)
        {
            if ($typeName === DNSTypes::NAME_SOA) {
                return in_array($question, $this->zones, true)
                    ? [new DNSResult(DNSTypes::NAME_SOA, 6, 'IN', 60, 'ns.example.', $question, '', [])]
                    : [];
            }
            if (isset($this->addresses[$question])) {
                return [new DNSResult($typeName, 1, 'IN', 60, $this->addresses[$question], $question, '', [])];
            }

            return [];
        }

        public function hasError(): bool
        {
            return false;
        }
    });
}

function availApp(Environment $environment, array $attributes = []): Application
{
    $test = test();
    $application = Application::create([
        'name' => 'web',
        'git_repository' => 'availproject/web',
        'git_branch' => 'main',
        'build_pack' => 'railpack',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
        'source_id' => $test->publicGithub->id,
        'source_type' => GithubApp::class,
        'fqdn' => 'https://web-abc123-prod.apps.avail.test',
        ...$attributes,
    ]);

    return $application->fresh();
}

function availGrandfather(Application $application, string $fqdn): Application
{
    // Custom domains set before the rules existed: written straight to the database.
    Application::whereKey($application->id)->update(['fqdn' => $fqdn]);

    return $application->fresh();
}

// --- What counts as a custom domain, and which apps may have one ---------------------------------

test('custom domains are hosts outside the wildcard domain and sslip.io', function () {
    expect(availIsCustomDomainHost('test.abc.xyz', 'apps.avail.test'))->toBeTrue()
        ->and(availIsCustomDomainHost('abc.xyz', 'apps.avail.test'))->toBeTrue()
        ->and(availIsCustomDomainHost('web.apps.avail.test', 'apps.avail.test'))->toBeFalse()
        ->and(availIsCustomDomainHost('7.web.apps.avail.test', 'apps.avail.test'))->toBeFalse()
        ->and(availIsCustomDomainHost('x.203.0.113.10.sslip.io', 'apps.avail.test'))->toBeFalse()
        ->and(availIsCustomDomainHost('apps.avail.test.evil.com', 'apps.avail.test'))->toBeTrue()
        ->and(availIsCustomDomainHost('test.abc.xyz', null))->toBeTrue();
});

test('only apps deployed from a public repository or the GitHub App are Git-based', function () {
    $githubApp = GithubApp::forceCreate([
        'uuid' => 'gh-app', 'name' => 'avail-coolify-bot', 'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com', 'is_public' => false, 'team_id' => 0, 'app_id' => 5022547, 'installation_id' => 1,
    ]);

    expect(availApplicationIsGitBased(availApp($this->production)))->toBeTrue()
        ->and(availApplicationIsGitBased(availApp($this->production, ['source_id' => $githubApp->id])))->toBeTrue()
        ->and(availApplicationIsGitBased(availApp($this->production, ['source_id' => null, 'source_type' => null, 'git_repository' => 'https://gitlab.example.com/a/b.git'])))->toBeTrue()
        ->and(availApplicationIsGitBased(availApp($this->production, ['build_pack' => 'dockerfile'])))->toBeTrue()
        ->and(availApplicationIsGitBased(availApp($this->production, ['build_pack' => 'dockerfile', 'dockerfile' => "FROM nginx\n", 'git_repository' => 'coollabsio/coolify'])))->toBeFalse()
        ->and(availApplicationIsGitBased(availApp($this->production, ['build_pack' => 'dockerimage', 'docker_registry_image_name' => 'nginx'])))->toBeFalse();
});

// --- The rule on every save -----------------------------------------------------------------------

test('a Git-based production app may get a custom domain', function () {
    $application = availApp($this->production);
    $application->fqdn = 'https://test.abc.xyz';
    $application->save();

    expect($application->fresh()->fqdn)->toBe('https://test.abc.xyz');
});

test('custom domains are refused outside production, on pasted Dockerfiles and on Docker images', function (array $attributes, string $environment, string $message) {
    $application = availApp($environment === 'production' ? $this->production : $this->staging, $attributes);

    expect(fn () => $application->update(['fqdn' => 'https://test.abc.xyz']))
        ->toThrow(AvailCustomDomainException::class, $message);
    expect($application->fresh()->fqdn)->toBe('https://web-abc123-prod.apps.avail.test');
})->with([
    'staging' => [[], 'staging', 'Custom domains are only for production. Use an address like https://my-app.apps.avail.test here.'],
    'pasted Dockerfile' => [['build_pack' => 'dockerfile', 'dockerfile' => "FROM nginx\n", 'git_repository' => 'coollabsio/coolify'], 'production', 'only for apps deployed from a Git repository'],
    'Docker image' => [['build_pack' => 'dockerimage', 'docker_registry_image_name' => 'nginx'], 'production', 'only for apps deployed from a Git repository'],
]);

test('wildcard addresses stay allowed everywhere', function () {
    $application = availApp($this->staging, ['build_pack' => 'dockerimage', 'docker_registry_image_name' => 'nginx']);
    $application->update(['fqdn' => 'https://my-app.apps.avail.test']);

    expect($application->fresh()->fqdn)->toBe('https://my-app.apps.avail.test');
});

test('custom domains set before the rules stay, but nothing can be added to them', function () {
    $application = availGrandfather(availApp($this->staging), 'https://old.abc.xyz');

    $application->update(['name' => 'renamed']);
    $application->update(['fqdn' => 'https://old.abc.xyz,https://my-app.apps.avail.test']);
    expect($application->fresh()->fqdn)->toBe('https://old.abc.xyz,https://my-app.apps.avail.test');

    expect(fn () => $application->fresh()->update(['fqdn' => 'https://old.abc.xyz,https://new.abc.xyz']))
        ->toThrow(AvailCustomDomainException::class);
});

test('an app with custom domains can not leave production or switch to a Docker image', function () {
    $application = availApp($this->production, ['fqdn' => 'https://test.abc.xyz']);

    expect(fn () => $application->fresh()->update(['environment_id' => $this->staging->id]))
        ->toThrow(AvailCustomDomainException::class, 'This app has custom domains (test.abc.xyz). Remove them first.');
    expect(fn () => $application->fresh()->update(['build_pack' => 'dockerimage', 'docker_registry_image_name' => 'nginx']))
        ->toThrow(AvailCustomDomainException::class);

    $plain = availApp($this->production);
    $plain->update(['environment_id' => $this->staging->id]);
    expect($plain->fresh()->environment_id)->toBe($this->staging->id);
});

test('Docker Compose apps from Git follow the same rule per service', function () {
    $compose = availApp($this->production, ['build_pack' => 'dockercompose', 'fqdn' => null]);
    $compose->update(['docker_compose_domains' => json_encode(['web' => ['domain' => 'https://test.abc.xyz'], 'api' => ['domain' => 'https://api.abc.xyz']])]);
    expect(availApplicationCustomHosts($compose->fresh()))->toBe(['test.abc.xyz', 'api.abc.xyz']);

    $staging = availApp($this->staging, ['build_pack' => 'dockercompose', 'fqdn' => null]);
    expect(fn () => $staging->update(['docker_compose_domains' => json_encode(['web' => ['domain' => 'https://test.abc.xyz']])]))
        ->toThrow(AvailCustomDomainException::class);
});

test('the rules are off when the switch is off (upstream tests)', function () {
    config(['avail.custom_domain_rules' => false]);
    $application = availApp($this->staging);
    $application->update(['fqdn' => 'https://test.abc.xyz']);

    expect($application->fresh()->fqdn)->toBe('https://test.abc.xyz');
    Notification::assertNothingSent();
});

// --- PR previews, services, environments, clones ---------------------------------------------------

test('previews of a production app may use its custom domain, a staging app may not get custom preview domains', function () {
    $production = availApp($this->production, ['fqdn' => 'https://test.abc.xyz']);
    $preview = ApplicationPreview::create(['application_id' => $production->id, 'pull_request_id' => 7, 'pull_request_html_url' => 'https://github.com/a/b/pull/7']);
    $preview->generate_preview_fqdn();
    expect($preview->fresh()->fqdn)->toBe('https://7.test.abc.xyz');

    $staging = availApp($this->staging);
    $stagingPreview = ApplicationPreview::create(['application_id' => $staging->id, 'pull_request_id' => 8, 'pull_request_html_url' => 'https://github.com/a/b/pull/8']);
    $stagingPreview->generate_preview_fqdn();
    expect($stagingPreview->fresh()->fqdn)->toBe('https://8.web-abc123-prod.apps.avail.test');
    expect(fn () => $stagingPreview->fresh()->update(['fqdn' => 'https://pr.abc.xyz']))->toThrow(AvailCustomDomainException::class);

    // A preview of an app whose custom domain was set before the rules keeps working.
    $old = availGrandfather(availApp($this->staging), 'https://old.abc.xyz');
    $oldPreview = ApplicationPreview::create(['application_id' => $old->id, 'pull_request_id' => 9, 'pull_request_html_url' => 'https://github.com/a/b/pull/9']);
    $oldPreview->generate_preview_fqdn();
    expect($oldPreview->fresh()->fqdn)->toBe('https://9.old.abc.xyz');
});

test('the preview domains page refuses a custom domain on a staging app', function () {
    availActAs($this->owner);
    $staging = availApp($this->staging);
    $preview = ApplicationPreview::create(['application_id' => $staging->id, 'pull_request_id' => 8, 'pull_request_html_url' => 'https://github.com/a/b/pull/8']);

    Livewire::test(PreviewDomains::class, ['preview' => $preview])
        ->set('newDomainParts.host', 'pr.abc.xyz')
        ->call('addDomain')
        ->assertDispatched('error');

    expect($preview->fresh()->fqdn)->toBeNull();
});

test('one-click services never get custom domains', function () {
    $service = Service::factory()->create([
        'server_id' => $this->server->id, 'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(), 'environment_id' => $this->production->id,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n",
    ]);
    $web = ServiceApplication::create(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'web', 'image' => 'nginx:alpine']);

    $web->update(['fqdn' => 'https://grafana.apps.avail.test']);
    expect(fn () => $web->fresh()->update(['fqdn' => 'https://grafana.abc.xyz']))
        ->toThrow(AvailCustomDomainException::class, 'not for services');

    availActAs($this->owner);
    $component = Livewire::test(ServiceDomains::class, ['service' => $service->fresh(['applications', 'server'])]);
    $component->set('newServiceApplicationId', $web->id)
        ->set('newDomainParts.host', 'grafana.abc.xyz')
        ->call('addDomain');
    expect($web->fresh()->fqdn)->toBe('https://grafana.apps.avail.test');
});

test('the services API refuses custom domains and changes nothing', function () {
    $service = Service::factory()->create([
        'server_id' => $this->server->id, 'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(), 'environment_id' => $this->production->id,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  api:\n    image: node:alpine\n",
    ]);
    $web = ServiceApplication::create(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'web', 'image' => 'nginx:alpine']);
    $api = ServiceApplication::create(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'api', 'image' => 'node:alpine']);
    session(['currentTeam' => Team::find(0)]);
    $token = $this->owner->createToken('test', ['write']);
    $token->accessToken->forceFill(['team_id' => 0])->save();

    $this->withToken($token->plainTextToken)->patchJson("/api/v1/services/{$service->uuid}", ['urls' => [
        ['name' => 'web', 'url' => 'https://web.apps.avail.test'],
        ['name' => 'api', 'url' => 'https://api.abc.xyz'],
    ]])->assertUnprocessable()->assertJsonFragment(['Custom domains are only for apps deployed from a Git repository, not for services. Use an address like https://my-app.apps.avail.test.']);

    expect($web->fresh()->fqdn)->toBeNull()->and($api->fresh()->fqdn)->toBeNull();
});

test('the applications API refuses a custom domain outside production before saving anything', function () {
    session(['currentTeam' => Team::find(0)]);
    $token = $this->owner->createToken('test', ['write']);
    $token->accessToken->forceFill(['team_id' => 0])->save();
    $staging = availApp($this->staging);
    $production = availApp($this->production, ['name' => 'prod-web']);

    $this->withToken($token->plainTextToken)->patchJson("/api/v1/applications/{$staging->uuid}", [
        'domains' => 'https://test.abc.xyz', 'name' => 'changed', 'use_build_server' => true,
    ])->assertUnprocessable()->assertJsonPath('errors.domains.0', 'Custom domains are only for production. Use an address like https://my-app.apps.avail.test here.');
    expect($staging->fresh()->name)->toBe('web')
        ->and($staging->fresh()->fqdn)->toBe('https://web-abc123-prod.apps.avail.test')
        ->and((bool) $staging->fresh()->settings->is_build_server_enabled)->toBeFalse();

    $this->withToken($token->plainTextToken)->patchJson("/api/v1/applications/{$production->uuid}", ['domains' => 'https://test.abc.xyz'])
        ->assertOk();
    expect($production->fresh()->fqdn)->toBe('https://test.abc.xyz');
});

test('creating a Docker image app with a custom domain through the API is refused and creates nothing', function () {
    session(['currentTeam' => Team::find(0)]);
    $token = $this->owner->createToken('test', ['write']);
    $token->accessToken->forceFill(['team_id' => 0])->save();
    $count = Application::count();

    $this->withToken($token->plainTextToken)->postJson('/api/v1/applications/dockerimage', [
        'project_uuid' => $this->project->uuid, 'environment_uuid' => $this->production->uuid,
        'server_uuid' => $this->server->uuid, 'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => 'latest', 'ports_exposes' => '80', 'instant_deploy' => false,
        'domains' => 'https://test.abc.xyz',
    ])->assertUnprocessable()->assertJsonPath('errors.domains.0', 'Custom domains are only for apps deployed from a Git repository (public repository or GitHub App). Use an address like https://my-app.apps.avail.test.');

    expect(Application::count())->toBe($count);
});

test('a production environment can not be renamed while its apps have custom domains', function () {
    availApp($this->production, ['fqdn' => 'https://test.abc.xyz']);
    availActAs($this->owner);

    Livewire::test(EnvironmentEdit::class, ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->production->uuid])
        ->set('name', 'prod-old')
        ->call('submit')
        ->assertDispatched('error');
    expect($this->production->fresh()->name)->toBe('production');

    $this->staging->update(['name' => 'stag']);
    expect($this->staging->fresh()->name)->toBe('stag');
});

test('a clone outside production drops custom domains and gets a generated address', function () {
    $source = availApp($this->production, ['fqdn' => 'https://test.abc.xyz,https://web.apps.avail.test']);
    $source->settings->forceFill(['is_container_label_readonly_enabled' => false])->save();

    $clone = clone_application($source->fresh(), $this->destination, ['environment_id' => $this->staging->id]);
    expect($clone->fresh()->fqdn)->toBe('https://web.apps.avail.test');

    $onlyCustom = availApp($this->production, ['name' => 'solo', 'fqdn' => 'https://solo.abc.xyz']);
    $onlyCustom->settings->forceFill(['is_container_label_readonly_enabled' => false])->save();
    $clone = clone_application($onlyCustom->fresh(), $this->destination, ['environment_id' => $this->staging->id]);
    expect($clone->fresh()->fqdn)->toEndWith('.apps.avail.test')
        ->and(availApplicationCustomHosts($clone->fresh()))->toBe([]);
});

// --- The Domains page and the Cloudflare record ----------------------------------------------------

test('adding a custom domain on the Domains page saves it and opens the Cloudflare record', function () {
    availActAs($this->owner);
    $application = availApp($this->production, ['fqdn' => null]);

    $component = Livewire::test(Domains::class, ['application' => $application])
        ->set('newDomainParts.host', 'test.abc.xyz')
        ->call('addDomain')
        ->assertDispatched('open-dns-records-modal');

    expect($application->fresh()->fqdn)->toBe('https://test.abc.xyz,https://www.test.abc.xyz');
    $component->assertSee('Owner action needed')
        ->assertSee('CNAME')
        ->assertSee('zone abc.xyz')
        ->assertSee('DNS only (grey cloud)')
        ->assertSee('Add in Cloudflare')
        ->assertSee('*.test');
});

test('the Domains page refuses a custom domain in staging', function () {
    availActAs($this->owner);
    $application = availApp($this->staging);

    Livewire::test(Domains::class, ['application' => $application])
        ->set('newDomainParts.host', 'test.abc.xyz')
        ->call('addDomain')
        ->assertDispatched('error')
        ->assertNotDispatched('open-dns-records-modal');

    expect($application->fresh()->fqdn)->toBe('https://web-abc123-prod.apps.avail.test');
});

test('subdomains get a CNAME to the app address and root domains an A record, as on Vercel', function () {
    $application = availApp($this->production, ['fqdn' => 'https://test.abc.xyz,https://www.example.co.uk,https://abc.xyz,https://web-abc123-prod.apps.avail.test']);

    $records = collect(availApplicationCloudflareRecords($application))->keyBy('fqdn');
    expect($records->get('test.abc.xyz'))->toMatchArray(['type' => 'CNAME', 'name' => 'test', 'zone' => 'abc.xyz', 'value' => 'web-abc123-prod.apps.avail.test', 'proxied' => false, 'proxy' => 'DNS only (grey cloud)', 'automatic' => false, 'zone_guessed' => false])
        ->and($records->get('*.test.abc.xyz'))->toMatchArray(['type' => 'CNAME', 'name' => '*.test', 'zone' => 'abc.xyz', 'purpose' => 'PR previews'])
        ->and($records->get('www.example.co.uk'))->toMatchArray(['type' => 'CNAME', 'name' => 'www', 'zone' => 'example.co.uk'])
        ->and($records->get('abc.xyz'))->toMatchArray(['type' => 'A', 'name' => '@', 'value' => '203.0.113.10', 'proxied' => false])
        ->and($records->has('*.www.example.co.uk'))->toBeFalse();

    expect(availDnsZoneFor('a.b.unknown.dev'))->toMatchArray(['zone' => 'unknown.dev', 'name' => 'a.b', 'guessed' => true]);

    $application->settings->forceFill(['is_preview_deployments_enabled' => false])->save();
    expect(collect(availApplicationCloudflareRecords($application->fresh()))->pluck('fqdn')->all())->toBe(['test.abc.xyz', 'www.example.co.uk', 'abc.xyz']);
});

test('a proxied app address gives a proxied CNAME in a connected zone and falls back to an A record elsewhere', function () {
    availFakeDns(['abc.xyz', 'other.dev'], ['web-abc123-prod.apps.avail.test' => '104.16.0.1']);
    $application = availApp($this->production, ['fqdn' => 'https://web-abc123-prod.apps.avail.test,https://test.abc.xyz,https://x.other.dev']);
    $token = \App\Models\IntegrationToken::factory()->create(['team_id' => 0, 'provider' => 'cloudflare', 'name' => 'Avail Cloudflare']);
    \App\Models\DnsProviderZone::factory()->for($token)->create(['name' => 'abc.xyz', 'provider_zone_id' => 'zone-abc']);

    $records = collect(availApplicationCloudflareRecords($application))->keyBy('fqdn');
    expect($records->get('test.abc.xyz'))->toMatchArray(['type' => 'CNAME', 'value' => 'web-abc123-prod.apps.avail.test', 'proxied' => true, 'automatic' => true])
        ->and($records->get('x.other.dev'))->toMatchArray(['type' => 'A', 'value' => '203.0.113.10', 'proxied' => false, 'automatic' => false]);
});

test('without a public IP for DNS the card asks the Owner to set one for root domains', function () {
    $this->server->settings->forceFill(['avail_dns_public_ip' => null])->save();
    $application = availApp($this->production, ['fqdn' => 'https://abc.xyz']);

    expect(availApplicationCloudflareRecords($application))->toBe([]);

    availActAs($this->owner);
    Livewire::test(Domains::class, ['application' => $application])
        ->assertSee('No public IP for DNS')
        ->assertSee('Servers → app-1 → Public IP for DNS');
});

test('the public IP for DNS is set on the server page and must be public', function () {
    availActAs($this->owner);
    $this->server->settings->forceFill(['avail_dns_public_ip' => null])->save();

    Livewire::test(ServerShow::class, ['server_uuid' => $this->server->uuid])
        ->set('availDnsPublicIp', '10.0.0.9')
        ->call('submit')
        ->assertDispatched('error');
    expect($this->server->settings->fresh()->avail_dns_public_ip)->toBeNull();

    Livewire::test(ServerShow::class, ['server_uuid' => $this->server->uuid])
        ->set('availDnsPublicIp', '198.51.100.7')
        ->call('submit')
        ->assertDispatched('success');
    expect($this->server->settings->fresh()->avail_dns_public_ip)->toBe('198.51.100.7')
        ->and(serverDnsTargetIp($this->server->fresh()))->toBe('198.51.100.7');
});

// --- DNS check -------------------------------------------------------------------------------------

test('a proxied root domain fails the DNS check, proxied subdomains and wildcard addresses pass', function () {
    InstanceSettings::whereKey(0)->update(['is_dns_validation_enabled' => true]);
    \Illuminate\Support\Once::flush();
    availFakeDns(['abc.xyz'], ['abc.xyz' => '104.16.0.1', 'test.abc.xyz' => '104.16.0.1', 'web.apps.avail.test' => '104.16.0.1', 'good.abc.xyz' => '203.0.113.10']);
    $server = $this->server->fresh();

    $results = CheckDomainDns::run([
        'root' => 'https://abc.xyz', 'sub' => 'https://test.abc.xyz', 'wildcard' => 'https://web.apps.avail.test', 'good' => 'https://good.abc.xyz',
    ], $server, '203.0.113.10');

    expect($results['root'])->toMatchArray(['status' => 'failed', 'message' => availProxiedCustomDomainMessage()])
        ->and($results['sub']['status'])->toBe('ok')
        ->and($results['wildcard']['status'])->toBe('ok')
        ->and($results['good']['status'])->toBe('ok')
        ->and(validateDNSEntry('https://abc.xyz', $server))->toBeFalse()
        ->and(validateDNSEntry('https://test.abc.xyz', $server))->toBeTrue();
});

// --- Automatic records through the stored Cloudflare token ---------------------------------------

function availConnectZone(string $zone = 'abc.xyz'): \App\Models\DnsProviderZone
{
    $token = \App\Models\IntegrationToken::factory()->create(['team_id' => 0, 'provider' => 'cloudflare', 'name' => 'Avail Cloudflare', 'token' => 'cf-test-token']);

    return \App\Models\DnsProviderZone::factory()->for($token)->create(['name' => $zone, 'provider_zone_id' => 'zone-abc']);
}

function availFakeCloudflare(): \ArrayObject
{
    $created = new \ArrayObject;
    \Illuminate\Support\Facades\Http::fake(function (\Illuminate\Http\Client\Request $request) use ($created) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/dns_records?')) {
            return \Illuminate\Support\Facades\Http::response(['success' => true, 'result' => []]);
        }
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/dns_records')) {
            $created->append($request->data());

            return \Illuminate\Support\Facades\Http::response(['success' => true, 'result' => ['id' => 'rec-'.count($created), ...$request->data()]]);
        }
        if ($request->method() === 'GET' && preg_match('#/dns_records/(rec-\d+)$#', $request->url(), $m)) {
            $data = $created[(int) substr($m[1], 4) - 1];

            return \Illuminate\Support\Facades\Http::response(['success' => true, 'result' => ['id' => $m[1], ...$data]]);
        }
        if ($request->method() === 'DELETE') {
            return \Illuminate\Support\Facades\Http::response(['success' => true, 'result' => ['id' => 'x']]);
        }

        return \Illuminate\Support\Facades\Http::response(['success' => false], 400);
    });

    return $created;
}

test('a new custom domain gets its records created in the connected Cloudflare zone and the Owner is told', function () {
    availConnectZone();
    $created = availFakeCloudflare();
    $application = availApp($this->production, ['fqdn' => 'https://web-abc123-prod.apps.avail.test']);

    $application->update(['fqdn' => 'https://test.abc.xyz,https://web-abc123-prod.apps.avail.test']);
    Queue::assertPushed(\App\Jobs\AvailCustomDomainDnsJob::class, fn ($job) => $job->hosts === ['test.abc.xyz']);
    Notification::assertNothingSent();

    (new \App\Jobs\AvailCustomDomainDnsJob($application->id, ['test.abc.xyz']))->handle();

    expect(collect($created->getArrayCopy())->map(fn ($r) => [$r['type'], $r['name'], $r['content'], $r['proxied']])->all())
        ->toBe([['CNAME', 'test.abc.xyz', 'web-abc123-prod.apps.avail.test', false], ['CNAME', '*.test.abc.xyz', 'web-abc123-prod.apps.avail.test', false]])
        ->and(availHasManagedRecord($application, 'test.abc.xyz'))->toBeTrue()
        ->and(availHasManagedRecord($application, '*.test.abc.xyz'))->toBeTrue();
    Notification::assertSentTo($this->team, CustomDomainDnsChanged::class, function (CustomDomainDnsChanged $notice) {
        $text = implode("\n", $notice->lines());

        return $notice->title() === 'Custom domain added: DNS record created'
            && str_contains($text, 'Created automatically: CNAME  test  ->  web-abc123-prod.apps.avail.test  in zone abc.xyz, DNS only')
            && str_contains($text, 'Domains page: https://deploy.avail.test/project/');
    });

    // The Domains page shows them as created.
    availActAs($this->owner);
    $application->forceFill(['domain_dns_statuses' => null])->saveQuietly();
    Livewire::test(Domains::class, ['application' => $application->fresh()])
        ->assertSee('Created automatically')
        ->assertSee('web-abc123-prod.apps.avail.test');

    // When an admin or the Owner removes the domain, the PR preview wildcard AvailCoolify created goes too.
    $application->fresh()->update(['fqdn' => 'https://web-abc123-prod.apps.avail.test']);
    Queue::assertPushed(\App\Jobs\ReleaseRemovedDnsHostnamesJob::class);
});

test('records in zones that are not connected are listed for the Owner, existing records are not overwritten', function () {
    availConnectZone();
    \Illuminate\Support\Facades\Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-abc/dns_records?*' => \Illuminate\Support\Facades\Http::response(['success' => true, 'result' => [
            ['id' => 'old', 'type' => 'CNAME', 'name' => 'test.abc.xyz', 'content' => 'somewhere.else.com'],
        ]]),
    ]);
    $application = availApp($this->production, ['fqdn' => 'https://test.abc.xyz,https://www.example.co.uk']);
    $application->settings->forceFill(['is_preview_deployments_enabled' => false])->save();

    $results = collect(availProvisionCustomDomainDns($application->fresh(), ['test.abc.xyz', 'www.example.co.uk']))->keyBy('fqdn');
    expect($results->get('test.abc.xyz'))->toMatchArray(['status' => 'conflict'])
        ->and($results->get('test.abc.xyz')['message'])->toContain('somewhere.else.com')
        ->and($results->get('www.example.co.uk'))->toMatchArray(['status' => 'manual', 'type' => 'CNAME']);
    \Illuminate\Support\Facades\Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

// --- Members: add on their own apps, ask the Owner to remove -----------------------------------------

test('members put custom domains on their own production Git apps, not on others or in staging', function () {
    $member = User::factory()->create();
    $member->teams()->attach(0, ['role' => 'member']);
    $own = availApp($this->production, ['name' => 'mine', 'fqdn' => 'https://mine.apps.avail.test']);
    $own->forceFill(['avail_created_by_user_id' => $member->id])->save();
    $ownStaging = availApp($this->staging, ['name' => 'mine-stag', 'fqdn' => 'https://mine-stag.apps.avail.test']);
    $ownStaging->forceFill(['avail_created_by_user_id' => $member->id])->save();
    $someoneElses = availApp($this->production, ['name' => 'theirs', 'fqdn' => 'https://theirs.apps.avail.test']);
    availActAs($member);

    Livewire::test(Domains::class, ['application' => $own->fresh()])
        ->set('newDomainParts.host', 'mine.abc.xyz')
        ->call('addDomain')
        ->assertDispatched('open-dns-records-modal');
    expect($own->fresh()->fqdn)->toContain('https://mine.abc.xyz');

    Livewire::test(Domains::class, ['application' => $ownStaging->fresh()])
        ->set('newDomainParts.host', 'stag.abc.xyz')
        ->call('addDomain')
        ->assertDispatched('error');
    expect($ownStaging->fresh()->fqdn)->not->toContain('stag.abc.xyz');

    expect(fn () => Livewire::test(Domains::class, ['application' => $someoneElses->fresh()])
        ->set('newDomainParts.host', 'theirs.abc.xyz')
        ->call('addDomain'))->not->toThrow(\Throwable::class);
    expect($someoneElses->fresh()->fqdn)->not->toContain('theirs.abc.xyz');
});

test('members cannot remove custom domains or delete an app that has them, they ask the Owner', function () {
    $member = User::factory()->create(['name' => 'Fagun']);
    $member->teams()->attach(0, ['role' => 'member']);
    $application = availApp($this->production, ['fqdn' => 'https://web-abc123-prod.apps.avail.test,https://mine.abc.xyz']);
    $application->forceFill(['avail_created_by_user_id' => $member->id])->save();
    availActAs($member);

    expect(fn () => $application->fresh()->update(['fqdn' => 'https://web-abc123-prod.apps.avail.test']))
        ->toThrow(AvailCustomDomainException::class, 'Only the Owner removes custom domains (mine.abc.xyz).');

    Livewire::test(\App\Livewire\Project\Shared\Danger::class, ['resource' => $application->fresh()])
        ->call('delete', 'password')
        ->assertDispatched('error');
    Queue::assertNotPushed(\App\Jobs\DeleteResourceJob::class);

    $component = Livewire::test(Domains::class, ['application' => $application->fresh()])
        ->assertSee('Request removal')
        ->call('availRequestRemoval', 'https://mine.abc.xyz')
        ->assertDispatched('success');
    $component->call('availRequestRemoval', 'https://mine.abc.xyz');
    expect(\App\Models\AvailDomainRemovalRequest::where('status', 'pending')->count())->toBe(1);
    Notification::assertSentTo($this->team, \App\Notifications\Avail\CustomDomainRemovalRequested::class,
        fn ($notice) => str_contains($notice->text(), 'Fagun asked to remove mine.abc.xyz'));
    Livewire::test(Domains::class, ['application' => $application->fresh()])->assertSee('Removal requested');
});

test('the Owner approves or rejects removal requests on the dashboard', function () {
    availConnectZone();
    availFakeCloudflare();
    $member = User::factory()->create();
    $member->teams()->attach(0, ['role' => 'member']);
    $application = availApp($this->production, ['fqdn' => 'https://web-abc123-prod.apps.avail.test,https://mine.abc.xyz,https://keep.abc.xyz']);
    $application->forceFill(['avail_created_by_user_id' => $member->id])->save();
    (new \App\Jobs\AvailCustomDomainDnsJob($application->id, ['mine.abc.xyz', 'keep.abc.xyz']))->handle();
    expect(availHasManagedRecord($application, 'mine.abc.xyz'))->toBeTrue();

    availActAs($member);
    $remove = availRequestDomainRemoval($application->fresh(), 'mine.abc.xyz', $member);
    $keep = availRequestDomainRemoval($application->fresh(), 'keep.abc.xyz', $member);

    availActAs($this->admin);
    Livewire::withoutLazyLoading()->test(AvailCustomDomains::class)->assertDontSee('Approve removal');

    availActAs($this->owner);
    $dashboard = Livewire::withoutLazyLoading()->test(AvailCustomDomains::class)
        ->assertSee('Custom domain removal requests')
        ->assertSee('mine.abc.xyz')
        ->call('approveRemoval', $remove->id)
        ->assertDispatched('success')
        ->call('rejectRemoval', $keep->id);

    expect($application->fresh()->fqdn)->toBe('https://web-abc123-prod.apps.avail.test,https://keep.abc.xyz')
        ->and($remove->fresh()->status)->toBe('approved')
        ->and($remove->fresh()->decided_by)->toBe($this->owner->id)
        ->and($keep->fresh()->status)->toBe('rejected')
        ->and(availHasManagedRecord($application, 'mine.abc.xyz'))->toBeFalse()
        ->and(availHasManagedRecord($application, 'keep.abc.xyz'))->toBeTrue();
    \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    $dashboard->assertDontSee('Approve removal');
});

// --- Telling the Owner ---------------------------------------------------------------------------

test('removing and deleting custom domains tell the team channels', function () {
    $application = availApp($this->production, ['fqdn' => 'https://test.abc.xyz']);
    Queue::assertPushed(\App\Jobs\AvailCustomDomainDnsJob::class);
    Notification::assertNothingSent();

    $application->fresh()->update(['name' => 'renamed']);
    Notification::assertNothingSent();

    $application->fresh()->update(['fqdn' => 'https://web.apps.avail.test']);
    Notification::assertSentTo($this->team, CustomDomainDnsChanged::class, fn ($notice) => $notice->removed === ['test.abc.xyz']
        && str_contains(implode("\n", $notice->lines()), 'Records AvailCoolify created for it are deleted automatically'));

    $other = availApp($this->production, ['name' => 'other', 'fqdn' => 'https://other.abc.xyz']);
    $other->delete();
    Notification::assertSentTo($this->team, CustomDomainDnsChanged::class, fn ($notice) => $notice->removed === ['other.abc.xyz']);
    $other->forceDelete();
    Notification::assertSentToTimes($this->team, CustomDomainDnsChanged::class, 2);
});

test('a domain added without a connected zone is listed for the Owner in the notice', function () {
    $application = availApp($this->production, ['fqdn' => 'https://web-abc123-prod.apps.avail.test,https://test.abc.xyz']);
    (new \App\Jobs\AvailCustomDomainDnsJob($application->id, ['test.abc.xyz']))->handle();

    Notification::assertSentTo($this->team, CustomDomainDnsChanged::class, function (CustomDomainDnsChanged $notice) {
        $text = implode("\n", $notice->lines());

        return $notice->title() === 'Custom domain added: add the Cloudflare record'
            && str_contains($text, 'Owner, add in Cloudflare (TTL Auto): CNAME  test  ->  web-abc123-prod.apps.avail.test  in zone abc.xyz, DNS only');
    });
});

test('the Owner gets the notice by email when transactional email is set up', function () {
    $notice = CustomDomainDnsChanged::for(availApp($this->production), ['test.abc.xyz'], []);
    expect($notice->toMail()->subject)->toBe('AvailCoolify: Custom domain added: add the Cloudflare record');

    Queue::fake();
    $notice->emailOwners();
    Queue::assertNothingPushed();

    InstanceSettings::whereKey(0)->update(['smtp_enabled' => true]);
    \Illuminate\Support\Once::flush();
    $notice->emailOwners();
    Queue::assertPushed(\Illuminate\Queue\CallQueuedClosure::class);
});

// --- The Owner's dashboard list -----------------------------------------------------------------

test('the Owner sees every custom domain with its record on the dashboard, admins do not', function () {
    $application = availApp($this->production, ['fqdn' => 'https://web-abc123-prod.apps.avail.test,https://test.abc.xyz']);
    availGrandfather(availApp($this->staging, ['name' => 'legacy']), 'https://old.abc.xyz');
    $application->forceFill(['domain_dns_statuses' => ['https://test.abc.xyz' => ['status' => 'failed', 'message' => 'not yet', 'expected_ip' => '203.0.113.10', 'checked_at' => now()->toIso8601String()]]])->saveQuietly();

    availActAs($this->owner);
    Livewire::withoutLazyLoading()->test(AvailCustomDomains::class)
        ->assertSee('test.abc.xyz')
        ->assertSee('Waiting for DNS')
        ->assertSee('CNAME test → web-abc123-prod.apps.avail.test')
        ->assertSee('add in Cloudflare')
        ->assertSee('old.abc.xyz')
        ->assertSee('Kept from before the rules');

    availActAs($this->admin);
    Livewire::withoutLazyLoading()->test(AvailCustomDomains::class)->assertDontSee('test.abc.xyz');
});
