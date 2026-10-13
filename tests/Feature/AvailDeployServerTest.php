<?php

use App\Livewire\Project\New\Select;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Avail: admins and members never put new resources on the Coolify host (server 0) while the team has
 * an app server, so with one app server the "Select a server" step is skipped. The owner keeps both.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'is_api_enabled' => true]));
    Team::find(0) ?? Team::unguarded(fn () => Team::create(['id' => 0, 'name' => 'Avail Team', 'personal_team' => true]));
    Team::whereKey(0)->update(['show_boarding' => false]);
    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();
    foreach ([[$this->owner, 'owner'], [$this->admin, 'admin'], [$this->member, 'member']] as [$user, $role]) {
        $user->teams()->attach(0, ['role' => $role]);
    }
    $usable = fn (Server $server) => $server->settings->forceFill([
        'is_reachable' => true, 'is_usable' => true, 'force_disabled' => false, 'is_swarm_worker' => false,
    ])->save();
    $this->host = Server::factory()->create(['id' => 0, 'team_id' => 0, 'name' => 'localhost']);
    $usable($this->host);
    $this->appServer = Server::factory()->create(['team_id' => 0, 'name' => 'availcoolify-app-01-fra1']);
    $usable($this->appServer);
    $this->usable = $usable;
    $this->as = function (User $user) {
        $this->actingAs($user->fresh());
        session(['currentTeam' => Team::find(0)]);
    };
});

test('admins and members are offered the app server only; the owner gets both', function () {
    $servers = Server::query()->whereIn('id', [$this->host->id, $this->appServer->id])->get();

    foreach ([$this->admin, $this->member] as $user) {
        ($this->as)($user);
        expect(availDeployableServers($servers)->pluck('id')->all())->toBe([$this->appServer->id]);
    }
    ($this->as)($this->owner);
    expect(availDeployableServers($servers)->pluck('id')->sort()->values()->all())->toBe([$this->host->id, $this->appServer->id]);
});

test('without an app server the Coolify host stays available to everyone', function () {
    $this->appServer->settings->forceFill(['is_usable' => false])->save();

    ($this->as)($this->member);
    expect(availMayDeployToServer($this->host->fresh()))->toBeTrue()
        ->and(availDeployableServers(collect([$this->host]))->pluck('id')->all())->toBe([$this->host->id]);
});

test('creating a resource on the Coolify host is refused for admins and members', function () {
    $hostDestination = StandaloneDocker::query()->where('server_id', $this->host->id)->firstOrFail();
    $appDestination = StandaloneDocker::query()->where('server_id', $this->appServer->id)->firstOrFail();

    foreach ([$this->admin, $this->member] as $user) {
        ($this->as)($user);
        expect(availMayDeployToServer($this->host->fresh()))->toBeFalse()
            ->and(find_resource_destination_for_current_team($hostDestination->uuid))->toBeNull()
            ->and(find_resource_destination_for_current_team($appDestination->uuid)?->id)->toBe($appDestination->id);
    }
    ($this->as)($this->owner);
    expect(find_resource_destination_for_current_team($hostDestination->uuid)?->id)->toBe($hostDestination->id);
});

test('the server step is skipped for a member when there is one app server', function () {
    $project = Project::factory()->create(['team_id' => 0]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    ($this->as)($this->member);

    // "New resource" reads its project from the page route; drive the component directly.
    $component = new Select;
    $component->parameters = ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid];
    $component->loadServers();
    expect(collect($component->servers)->pluck('id')->all())->toBe([$this->appServer->id]);

    try {
        $component->setType('public');
    } catch (\Throwable) {
        // the redirect to the next page needs a live request; the choice is made before it
    }
    expect((int) $component->server_id)->toBe($this->appServer->id)
        ->and($component->current_step)->not->toBe('servers');
});

test('the API refuses the Coolify host for an admin token', function () {
    $project = Project::factory()->create(['team_id' => 0]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    session(['currentTeam' => Team::find(0)]);
    $token = $this->admin->createToken('test', ['root'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/applications/public', [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'server_uuid' => $this->host->uuid,
        'git_repository' => 'https://github.com/coollabsio/coolify-examples',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
    ])->assertStatus(422)->assertJsonPath('errors.server_uuid.0', 'Use an app server. Only the owner can deploy to the Coolify host.');
});
