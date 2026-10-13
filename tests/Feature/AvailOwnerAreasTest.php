<?php

use App\Http\Middleware\AvailAreaAccess;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * Avail: owner-only and admin-only parts of the dashboard (reviewed 2026-10-13). Hidden items are left out
 * of the sidebar, page switcher, sub-menus and Ctrl+K, and their pages, Livewire calls and API endpoints
 * answer 403.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'is_api_enabled' => true]));
    $this->owner = User::factory()->create(['id' => 0]);
    Team::find(0) ?? Team::unguarded(fn () => Team::create(['id' => 0, 'name' => 'Avail Team', 'personal_team' => true]));
    Team::whereKey(0)->update(['show_boarding' => false]);
    $this->owner->teams()->syncWithoutDetaching([0 => ['role' => 'owner']]);
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach(0, ['role' => 'admin']);
    $this->member = User::factory()->create();
    $this->member->teams()->attach(0, ['role' => 'member']);
    $this->as = function (User $user) {
        $this->actingAs($user->fresh());
        session(['currentTeam' => Team::find(0)]);
    };
});

test('every reviewed page has the agreed level', function (string $route, ?string $level) {
    expect(availAreaForRoute($route))->toBe($level);
})->with([
    ['server.index', 'owner'], ['server.proxy', 'owner'], ['registries.index', 'owner'], ['terminal', 'owner'],
    ['project.application.command', 'owner'], ['source.all', 'owner'], ['destination.index', 'owner'],
    ['storage.index', 'owner'], ['notifications.slack', 'owner'], ['security.private-key.index', 'owner'],
    ['security.cloud-tokens', 'owner'], ['security.integration-tokens', 'owner'], ['security.cloud-init-scripts', 'owner'],
    ['settings.index', 'platform'], ['settings.advanced', 'platform'], ['settings.updates', 'platform'],
    ['settings.backup', 'platform'], ['settings.email', 'platform'], ['settings.oauth', 'platform'],
    ['settings.access-protection', 'admin'], ['settings.guest-access', 'admin'], ['shared-variables.index', 'admin'],
    ['team.index', null], ['team.member.index', null], ['team.audit-log', 'admin'], ['team.danger-zone', 'admin'],
    ['dashboard', null], ['project.index', null], ['analytics', null], ['tags.show', null], ['security.api-tokens', null],
    ['project.application.configuration', null], ['team.invitation.show', null],
]);

test('owner-only pages refuse admins and members', function (string $route) {
    foreach ([$this->admin, $this->member] as $user) {
        ($this->as)($user);
        $this->get(route($route))->assertForbidden();
    }
    ($this->as)($this->owner);
    expect($this->get(route($route))->getStatusCode())->not->toBe(403);
})->with(['server.index', 'source.all', 'destination.index', 'registries.index', 'storage.index',
    'notifications.email', 'security.private-key.index', 'security.cloud-tokens', 'settings.index',
    'settings.advanced', 'settings.updates', 'settings.email', 'settings.oauth']);

test('admin pages refuse members and let admins in', function (string $route) {
    ($this->as)($this->member);
    $this->get(route($route))->assertForbidden();
    ($this->as)($this->admin);
    expect($this->get(route($route))->getStatusCode())->not->toBe(403);
})->with(['shared-variables.index', 'team.audit-log', 'settings.access-protection', 'settings.guest-access']);

test('everyone keeps their API tokens page', function () {
    ($this->as)($this->member);
    expect($this->get(route('security.api-tokens'))->getStatusCode())->not->toBe(403);
});

test('members view the Team overview and member list, read-only', function () {
    ($this->as)($this->member);
    $this->get(route('team.index'))->assertOk()->assertDontSee('href="'.route('team.audit-log').'"', false);
    $this->get(route('team.member.index'))->assertOk();
    expect(Gate::allows('manageMembers', Team::find(0)))->toBeFalse()
        ->and(Gate::allows('manageInvitations', Team::find(0)))->toBeFalse()
        ->and(Gate::allows('update', Team::find(0)))->toBeFalse();
});

test('Livewire calls are checked against the page they come from', function () {
    expect(Livewire::getPersistentMiddleware())->toContain(AvailAreaAccess::class);

    ($this->as)($this->owner);
    $html = $this->get(route('notifications.email'))->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = collect($matches[1])
        ->map(fn ($raw) => html_entity_decode($raw, ENT_QUOTES))
        ->first(fn ($json) => str_starts_with((string) data_get(json_decode($json, true), 'memo.name'), 'notifications.'));
    expect($snapshot)->not->toBeNull();
    $payload = ['components' => [['snapshot' => $snapshot, 'updates' => new stdClass, 'calls' => []]]];
    $uri = app('livewire')->getUpdateUri();

    ($this->as)($this->admin);
    $this->withHeaders(['X-Livewire' => 'true'])->postJson($uri, $payload)->assertForbidden();

    ($this->as)($this->owner);
    $this->withHeaders(['X-Livewire' => 'true'])->postJson($uri, $payload)->assertOk();
});

test('owner-only API endpoints refuse admin tokens', function () {
    $token = function (User $user) {
        app('auth')->forgetGuards();
        session(['currentTeam' => Team::find(0)]);

        return $user->createToken('test', ['root'])->plainTextToken;
    };

    $this->withToken($token($this->admin))->getJson('/api/v1/servers')->assertForbidden();
    $this->withToken($token($this->admin))->getJson('/api/v1/security/keys')->assertForbidden();
    $this->withToken($token($this->owner))->getJson('/api/v1/servers')->assertOk();
    expect(availAreaForApiPath('api/v1/applications'))->toBeNull()
        ->and(availAreaForApiPath('api/v1/deploy'))->toBeNull()
        ->and(availAreaForApiPath('api/v1/team/envs'))->toBe('admin');
});

test('terminals and creating infrastructure are for the owner', function () {
    foreach ([[$this->owner, true], [$this->admin, false], [$this->member, false]] as [$user, $allowed]) {
        ($this->as)($user);
        expect(Gate::allows('canAccessTerminal'))->toBe($allowed);
        foreach ([Server::class, PrivateKey::class, S3Storage::class, GithubApp::class] as $model) {
            expect(Gate::allows('create', $model))->toBe($allowed, class_basename($model).' for '.$user->email);
        }
    }
});

test('the sidebar shows each role only what it may open', function () {
    $links = fn () => $this->get(route('project.index'))->assertOk()->getContent();

    ($this->as)($this->owner);
    $html = $links();
    foreach (['Terminal', 'Servers', 'Sources', 'Destinations', 'Registries', 'S3 Storage', 'Shared variables', 'Team', 'Notifications', 'Settings'] as $title) {
        expect($html)->toContain('title="'.$title.'"');
    }

    ($this->as)($this->admin);
    $html = $links();
    foreach (['Terminal', 'Servers', 'Sources', 'Destinations', 'Registries', 'S3 Storage', 'Notifications'] as $title) {
        expect($html)->not->toContain('title="'.$title.'"');
    }
    expect($html)->toContain('title="Shared variables"')->toContain('title="Team"')
        ->toContain(route('settings.access-protection'))->toContain(route('security.api-tokens'));

    ($this->as)($this->member);
    $html = $links();
    foreach (['Terminal', 'Servers', 'Shared variables', 'Notifications', 'Settings'] as $title) {
        expect($html)->not->toContain('title="'.$title.'"');
    }
    expect($html)->toContain('title="Keys & Tokens"')->toContain('title="Tags"')->toContain('title="Analytics"')
        ->toContain('title="Team"');
});

test('admins get only Access protection and Guest access in Settings', function () {
    ($this->as)($this->admin);
    $html = $this->get(route('settings.access-protection'))->assertOk()->getContent();

    expect($html)->toContain(route('settings.guest-access'))
        ->not->toContain('href="'.route('settings.index').'"')
        ->not->toContain('href="'.route('settings.oauth').'"');
});

test('Keys & Tokens shows members their API tokens only', function () {
    ($this->as)($this->member);
    $html = $this->get(route('security.api-tokens'))->assertOk()->getContent();

    expect($html)->not->toContain('href="'.route('security.private-key.index').'"')
        ->not->toContain('href="'.route('security.cloud-tokens').'"');
});

test('menu links are filtered by the same rules', function () {
    ($this->as)($this->member);
    expect(availCanOpenUrl(route('server.index')))->toBeFalse()
        ->and(availCanOpenUrl(route('project.index')))->toBeTrue()
        ->and(availCanOpenUrl(route('security.api-tokens')))->toBeTrue();
});
