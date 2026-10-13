<div class="flex flex-col gap-4">
    <div class="flex flex-col gap-1">
        <span class="text-sm font-medium text-black dark:text-white">App owner</span>
        <span class="text-sm text-neutral-600 dark:text-fg-dim">
            {{ $ownerName ?? 'Nobody (created before app owners were recorded)' }}
        </span>
    </div>

    @if ($canTransfer)
        @if ($candidates->isEmpty())
            <p class="text-sm text-neutral-500 dark:text-fg-dim">There is nobody else in the team to hand this app over to.</p>
        @else
            <form wire:submit="transfer" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1">
                    <x-forms.listbox id="newOwnerId" label="Transfer to" searchable
                        placeholder="Choose a member, admin or the owner"
                        helper="The new app owner can change this app's settings and environment variables, read its runtime logs and delete it. Guests cannot own apps."
                        :options="$candidates->map(fn ($user) => [
                            'value' => (string) $user->id,
                            'label' => filled($user->name) ? $user->name : $user->email,
                            'description' => filled($user->name) ? $user->email : null,
                        ])->values()->all()" />
                </div>
                <x-forms.button type="submit" class="shrink-0">Transfer</x-forms.button>
            </form>
        @endif
    @endif
</div>
