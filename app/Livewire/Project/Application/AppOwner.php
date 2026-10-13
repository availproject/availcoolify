<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Avail: shows who owns the app (avail_created_by_user_id) and hands it over to another member, admin or
 * the owner of the team. The app owner may change the app's settings and environment variables, read its
 * runtime logs and delete it; admins, the team owner and the current app owner may transfer it.
 */
class AppOwner extends Component
{
    public Application $application;

    public ?string $newOwnerId = null;

    public function mount(): void
    {
        $this->authorize('view', $this->application);
    }

    /** Team users who may receive the app: members, admins and the owner, never guests or the current owner. */
    public function candidates(): Collection
    {
        $team = $this->application->team();
        if (! $team) {
            return collect();
        }

        return $team->members()
            ->wherePivotIn('role', ['owner', 'admin', 'member'])
            ->where('users.id', '!=', $this->application->avail_created_by_user_id)
            ->orderBy('name')
            ->get();
    }

    public function transfer(): void
    {
        try {
            $this->authorize('transferOwnership', $this->application);
            $this->validate(['newOwnerId' => 'required'], ['newOwnerId.required' => 'Choose who gets the app.']);

            $target = $this->candidates()->firstWhere('id', (int) $this->newOwnerId);
            if (! $target instanceof User) {
                $this->dispatch('error', 'That person is not a member, admin or owner of this team.');

                return;
            }

            $previousId = $this->application->avail_created_by_user_id;
            $this->application->forceFill(['avail_created_by_user_id' => $target->id])->save();
            AuditEvent::record('ui.application.owner_transferred', [
                'team_id' => $this->application->team()?->id,
                'application_uuid' => $this->application->uuid,
                'application_name' => $this->application->name,
                'from_user_id' => $previousId,
                'to_user_id' => $target->id,
            ]);

            $this->application->refresh();
            $this->newOwnerId = null;
            $this->dispatch('success', 'App transferred to '.(filled($target->name) ? $target->name : $target->email).'.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render(): View
    {
        $canTransfer = auth()->user()?->can('transferOwnership', $this->application) ?? false;

        return view('livewire.project.application.app-owner', [
            'ownerName' => availOwnerName($this->application),
            'canTransfer' => $canTransfer,
            'candidates' => $canTransfer ? $this->candidates() : collect(),
        ]);
    }
}
