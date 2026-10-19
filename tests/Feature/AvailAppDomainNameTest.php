<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Avail: new applications get https://<repo>-<6 characters of the uuid>[-<environment>].<wildcard>
 * instead of https://<uuid>.<wildcard>; production adds nothing, staging is "stag".
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0, 'fqdn' => 'https://deploy.example.com']));
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update(['wildcard_domain' => 'https://apps.example.com']);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);

    $this->makeApp = function (array $attributes, string $environment = 'production') {
        $env = Environment::query()->firstOrCreate(
            ['project_id' => $this->project->id, 'name' => $environment],
        );

        return Application::factory()->create(array_merge([
            'environment_id' => $env->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'git_repository' => 'availproject/autobot',
            'build_pack' => 'nixpacks',
        ], $attributes))->fresh();
    };
    $this->url = fn (Application $app) => availAppUrl($this->server->fresh(), $app);
});

test('a production git app gets repo and six characters of its uuid, with no environment suffix', function () {
    $app = ($this->makeApp)(['uuid' => 'al2ft4wfqxyz123456789abc']);

    expect(($this->url)($app))->toBe('https://autobot-al2ft4.apps.example.com');
});

test('production adds no suffix, staging is stag, other environment names are kept', function () {
    $staging = ($this->makeApp)(['uuid' => 'aaaaaa111111222222333333'], 'staging');
    $preview = ($this->makeApp)(['uuid' => 'bbbbbb111111222222333333'], 'preview-test');
    $spaced = ($this->makeApp)(['uuid' => 'cccccc111111222222333333'], 'QA Env');

    expect(($this->url)($staging))->toBe('https://autobot-aaaaaa-stag.apps.example.com')
        ->and(($this->url)($preview))->toBe('https://autobot-bbbbbb-preview-test.apps.example.com')
        ->and(($this->url)($spaced))->toBe('https://autobot-cccccc-qa-env.apps.example.com');
});

test('repository URLs and odd characters become a DNS-safe name', function () {
    $https = ($this->makeApp)(['uuid' => 'dddddd111111222222333333', 'git_repository' => 'https://github.com/Owner/My_Repo.git']);
    $ssh = ($this->makeApp)(['uuid' => 'eeeeee111111222222333333', 'git_repository' => 'git@github.com:owner/api.server.git']);

    expect(($this->url)($https))->toBe('https://my-repo-dddddd.apps.example.com')
        ->and(($this->url)($ssh))->toBe('https://api-server-eeeeee.apps.example.com');
});

test('docker image apps use the image name and dockerfile apps drop the random part of their name', function () {
    $image = ($this->makeApp)([
        'uuid' => 'ffffff111111222222333333',
        'build_pack' => 'dockerimage',
        'git_repository' => 'coollabsio/coolify',
        'docker_registry_image_name' => 'ghcr.io/availproject/My-API',
        'docker_registry_image_tag' => '1.2',
    ]);
    $dockerfile = ($this->makeApp)([
        'uuid' => 'gggggg111111222222333333',
        'build_pack' => 'dockerfile',
        'git_repository' => 'coollabsio/coolify',
        'dockerfile' => "FROM nginx:alpine\n",
        'name' => 'dockerfile-k2j4h5g6f7d8s9a0q1w2e3r4',
    ]);

    expect(($this->url)($image))->toBe('https://my-api-ffffff.apps.example.com')
        ->and(($this->url)($dockerfile))->toBe('https://dockerfile-gggggg.apps.example.com');
});

test('a long production repository name is shortened without a suffix', function () {
    $app = ($this->makeApp)(['uuid' => 'nnnnnn111111222222333333', 'git_repository' => 'org/'.str_repeat('very-long-repository-name-', 4)]);

    $label = explode('.', parse_url(($this->url)($app), PHP_URL_HOST))[0];

    expect(strlen($label))->toBeLessThanOrEqual(63)
        ->and($label)->toEndWith('-nnnnnn');
});

test('a long repository name is shortened so the host label stays within 63 characters', function () {
    $app = ($this->makeApp)(['uuid' => 'hhhhhh111111222222333333', 'git_repository' => 'org/'.str_repeat('very-long-repository-name-', 4)], 'staging');

    $label = explode('.', parse_url(($this->url)($app), PHP_URL_HOST))[0];

    expect(strlen($label))->toBeLessThanOrEqual(63)
        ->and($label)->toEndWith('-hhhhhh-stag')
        ->and($label)->toStartWith('very-long-repository-name-');
});

test('a taken host falls back to the next six characters of the uuid', function () {
    ($this->makeApp)(['uuid' => 'zzzzzz999999888888777777', 'fqdn' => 'https://autobot-iiiiii.apps.example.com']);
    $app = ($this->makeApp)(['uuid' => 'iiiiiijjjjjjkkkkkkllllll']);

    expect(($this->url)($app))->toBe('https://autobot-jjjjjj.apps.example.com');
});

test('the app itself does not count as taking its own host', function () {
    $app = ($this->makeApp)(['uuid' => 'mmmmmm111111222222333333']);
    $app->update(['fqdn' => 'https://autobot-mmmmmm.apps.example.com']);

    expect(($this->url)($app->fresh()))->toBe('https://autobot-mmmmmm.apps.example.com');
});

test('the creation flows, the API and the generate buttons use the readable name', function () {
    $files = [
        'app/Livewire/Project/New/PublicGitRepository.php',
        'app/Livewire/Project/New/GithubPrivateRepository.php',
        'app/Livewire/Project/New/GithubPrivateRepositoryDeployKey.php',
        'app/Livewire/Project/New/GitlabPrivateRepository.php',
        'app/Livewire/Project/New/SimpleDockerfile.php',
        'app/Livewire/Project/New/DockerImage.php',
        'app/Http/Controllers/Api/ApplicationsController.php',
        'app/Livewire/Project/Application/General.php',
        'app/Livewire/Project/Application/Domains.php',
    ];

    foreach ($files as $file) {
        $code = file_get_contents(base_path($file));
        expect($code)->toContain('availAppUrl(')
            ->and($code)->not->toMatch('/generateUrl\([^)]*random:\s*\$(this->)?application->uuid/');
    }
});
