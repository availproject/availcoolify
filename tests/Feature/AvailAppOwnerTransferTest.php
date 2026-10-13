<?php

use App\Livewire\Project\Application\AppOwner;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * Avail: the app owner (the member who created the app, or who it was handed to) keeps their rights on it,
 * and admins, the team owner or the app owner can hand the app over to another member, admin or the owner.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));
    Team::find(0) ?? Team::unguarded(fn () => Team::create(['id' => 0, 'name' => 'Avail Team', 'personal_team' => true]));
    $this->team = Team::find(0);
    $this->owner = User::factory()->create(['name' => 'Olga Owner']);
    $this->admin = User::factory()->create(['name' => 'Ada Admin']);
    $this->creator = User::factory()->create(['name' => 'Cleo Creator']);
    $this->other = User::factory()->create(['name' => 'Otto Other']);
    $this->guest = User::factory()->create(['name' => 'Gus Guest']);
    foreach ([[$this->owner, 'owner'], [$this->admin, 'admin'], [$this->creator, 'member'], [$this->other, 'member'], [$this->guest, 'guest']] as [$user, $role]) {
        $user->teams()->attach(0, ['role' => $role]);
    }
    $server = Server::factory()->create(['team_id' => 0]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => 0]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->availApp = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'avail_created_by_user_id' => $this->creator->id,
    ]);
    $this->as = function (User $user) {
        $this->actingAs($user->fresh());
        session(['currentTeam' => $this->team]);
    };
});

test('the app owner keeps every right on their app, and only on their app', function () {
    $foreign = $this->availApp->replicate()->forceFill(['uuid' => 'foreign-app-uuid', 'avail_created_by_user_id' => $this->other->id]);
    $foreign->save();

    ($this->as)($this->creator);
    $denied = collect(['configure', 'manageEnvironment', 'readLogs', 'delete', 'deploy', 'transferOwnership'])
        ->reject(fn ($ability) => Gate::allows($ability, $this->availApp->fresh()))->values()->all();
    expect($denied)->toBe([]);
    foreach (['configure', 'manageEnvironment', 'readLogs', 'delete', 'transferOwnership'] as $ability) {
        expect(Gate::allows($ability, $foreign->fresh()))->toBeFalse("someone else's app: {$ability}");
    }
});

test('admins, the team owner and the app owner may transfer; other members and guests may not', function () {
    foreach ([[$this->owner, true], [$this->admin, true], [$this->creator, true], [$this->other, false], [$this->guest, false]] as [$user, $allowed]) {
        ($this->as)($user);
        expect(Gate::allows('transferOwnership', $this->availApp->fresh()))->toBe($allowed, $user->name);
    }
});

test('the app owner hands the app over and the rights move with it', function () {
    $this->withoutDefer();
    ($this->as)($this->creator);

    Livewire::test(AppOwner::class, ['application' => $this->availApp])
        ->assertSee('Cleo Creator')
        ->set('newOwnerId', (string) $this->other->id)
        ->call('transfer')
        ->assertDispatched('success');

    $app = $this->availApp->fresh();
    expect($app->avail_created_by_user_id)->toBe($this->other->id)
        ->and(AuditEvent::query()->where('event', 'ui.application.owner_transferred')->exists())->toBeTrue();

    ($this->as)($this->creator);
    expect(Gate::allows('configure', $app))->toBeFalse()->and(Gate::allows('manageEnvironment', $app))->toBeFalse();
    ($this->as)($this->other);
    expect(Gate::allows('configure', $app))->toBeTrue()->and(Gate::allows('manageEnvironment', $app))->toBeTrue();
});

test('an admin can hand over an app they did not create', function () {
    ($this->as)($this->admin);

    Livewire::test(AppOwner::class, ['application' => $this->availApp])
        ->set('newOwnerId', (string) $this->admin->id)
        ->call('transfer');

    expect($this->availApp->fresh()->avail_created_by_user_id)->toBe($this->admin->id);
});

test('guests are never offered and cannot receive an app', function () {
    ($this->as)($this->admin);

    $component = Livewire::test(AppOwner::class, ['application' => $this->availApp]);
    expect($component->instance()->candidates()->pluck('id')->all())
        ->toContain($this->owner->id, $this->admin->id, $this->other->id)
        ->not->toContain($this->guest->id, $this->creator->id);

    $component->set('newOwnerId', (string) $this->guest->id)->call('transfer')->assertDispatched('error');
    expect($this->availApp->fresh()->avail_created_by_user_id)->toBe($this->creator->id);
});

test('another member cannot transfer the app', function () {
    ($this->as)($this->other);

    Livewire::test(AppOwner::class, ['application' => $this->availApp])
        ->assertDontSee('Transfer to')
        ->set('newOwnerId', (string) $this->other->id)
        ->call('transfer');

    expect($this->availApp->fresh()->avail_created_by_user_id)->toBe($this->creator->id);
});
