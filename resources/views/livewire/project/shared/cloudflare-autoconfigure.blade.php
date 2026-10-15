{{-- DNS entries: Domain Connect (Cloud only + key) and/or generic Type/Name/Value records. --}}
@php
    $domainConnectAvailable = $this->domainConnectAvailable();
    $dnsAuthResource = property_exists($this, 'application') ? $this->application : $this->service;
@endphp

<div x-data="{ dnsEntriesOpen: false }" class="relative" @click.outside="dnsEntriesOpen = false">
    <button type="button" class="button" @click="dnsEntriesOpen = !dnsEntriesOpen" aria-haspopup="menu"
        x-bind:aria-expanded="dnsEntriesOpen" title="DNS entries for this server">
        <x-reicon name="globe" class="size-3.5" />
        DNS entries
        <span class="inline-flex transition-transform" :class="dnsEntriesOpen && 'rotate-180'">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                stroke="currentColor" class="size-3.5 shrink-0 opacity-60">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8 9 4-4 4 4m0 6-4 4-4-4" />
            </svg>
        </span>
    </button>
    <div x-show="dnsEntriesOpen" x-cloak role="menu" x-transition.origin.top.right
        class="listbox-panel left-auto! right-0! z-[90]! w-56! min-w-56!">
        @if ($domainConnectAvailable)
            <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                wire:click="openCloudflareAutoconfigureModal" @click="dnsEntriesOpen = false">
                <x-reicon name="globe" class="size-3.5 shrink-0 opacity-70" />
                Cloudflare
            </button>
        @endif
        <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
            wire:click="openManualDnsRecords" @click="dnsEntriesOpen = false">
            <x-reicon name="documentation" class="size-3.5 shrink-0 opacity-70" />
            Manual records
        </button>
    </div>
</div>

@if ($showCloudflareAutoconfigureModal && $domainConnectAvailable)
    <div x-data="{ modalOpen: @entangle('showCloudflareAutoconfigureModal') }"
        @keydown.escape.window="modalOpen = false; $wire.closeCloudflareAutoconfigureModal()"
        class="relative h-auto w-auto" :class="{ 'z-40': modalOpen }">
        <template x-teleport="body">
            <div x-show="modalOpen" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="absolute inset-0 h-full w-full bg-black/50 backdrop-blur-[2px]"
                    @click="modalOpen = false; $wire.closeCloudflareAutoconfigureModal()"></div>
                <div class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="application-settings-form application-settings-section relative flex w-full max-w-lg flex-col overflow-hidden"
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                        <header class="flex-nowrap!">
                            <h3 class="min-w-0 flex-1 truncate">Configure DNS on Cloudflare</h3>
                            <button type="button" wire:click="closeCloudflareAutoconfigureModal"
                                class="icon-button shrink-0" aria-label="Close">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body flex flex-col gap-4">
                            @php
                                $cloudflareHosts = $this->allDomainHostnames();
                            @endphp
                            <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                                Opens Cloudflare Domain Connect for every domain on this resource, with A records
                                prefilled to this server’s IP. Authorize each change in Cloudflare.
                            </p>

                            <div>
                                <p class="mb-1.5 text-sm font-medium text-black dark:text-white">Domains</p>
                                <ul
                                    class="max-h-40 space-y-1 overflow-y-auto rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 font-mono text-[13px] text-black dark:border-coolgray-300 dark:bg-coolgray-100 dark:text-fg">
                                    @foreach ($cloudflareHosts as $host)
                                        <li>{{ $host }}</li>
                                    @endforeach
                                </ul>
                            </div>

                            <x-forms.input label="Server IP (A record target)"
                                value="{{ $this->publicServerIpForDomainConnect() ?: 'Unavailable' }}" readonly
                                helper="This IP is taken from the destination server." />

                            <div class="flex flex-wrap items-center justify-end gap-2 pt-2">
                                <x-forms.button type="button" wire:click="closeCloudflareAutoconfigureModal">
                                    Cancel
                                </x-forms.button>
                                <x-forms.button type="button" wire:click="applyCloudflareAutoconfigure"
                                    isHighlighted>
                                    Open Cloudflare
                                    <x-external-link class="size-3 opacity-70" />
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endif

{{-- Always mounted so open/close is Alpine-only (no Livewire round-trip). --}}
@php
    $dnsHints = $this->dnsRecordHints();
    $dnsCopyText = $this->dnsRecordsCopyText();
    // Avail: custom domains get their Cloudflare record (CNAME for a subdomain, A for a root domain), created
    // automatically when the zone is connected, instead of the generic list.
    $availCustomMode = availCustomDomainRulesEnabled() && method_exists($this, 'availCloudflareRecords');
    $availRecords = $availCustomMode ? collect($this->availCloudflareRecords())->map(fn (array $r) => [
        ...$r,
        'state' => ! $r['automatic'] ? 'manual' : (availHasManagedRecord($this->application, $r['fqdn']) ? 'created' : 'creating'),
    ])->all() : [];
    $availManualRecords = collect($availRecords)->where('state', 'manual')->values()->all();
    $availPendingCustomHosts = [];
    if ($availCustomMode) {
        $availPendingCustomHosts = $this->availPendingCustomHosts();
        $dnsHints = collect($dnsHints)->reject(fn (array $record) => in_array($record['name'], $availPendingCustomHosts, true))->values()->all();
        $dnsCopyText = \App\Support\DnsRecordHints::toCopyText($dnsHints);
    }
    $availCopyText = collect($availManualRecords)->map(fn (array $r) => "{$r['type']}  {$r['name']}  {$r['value']}  (zone {$r['zone']}, {$r['proxy']})")->implode("\n");
@endphp
<div
    x-data="{
        modalOpen: false,
        openDnsRecords() {
            this.modalOpen = true;
        },
        closeDnsRecords() {
            this.modalOpen = false;
        },
        async recheckDns() {
            this.modalOpen = true;
            try {
                await $wire.recheckDnsRecordsInModal();
            } finally {
                // Re-assert open after Livewire morph may re-init Alpine.
                this.modalOpen = true;
            }
        },
    }"
    @open-dns-records-modal.window="openDnsRecords()"
    class="relative h-auto w-auto" :class="{ 'z-40': modalOpen }"
    @keydown.window.escape="if (modalOpen) { closeDnsRecords() }">
    <template x-teleport="body">
        <div x-show="modalOpen" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
            <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 h-full w-full bg-black/50 backdrop-blur-[2px]"
                @click="closeDnsRecords()"></div>
            <div class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                    x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                    class="application-settings-form application-settings-section relative flex w-full max-w-2xl flex-col overflow-hidden"
                    style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                    <header class="flex-nowrap!">
                        <h3 class="min-w-0 flex-1 truncate">DNS entries</h3>
                        <button type="button" @click="closeDnsRecords()"
                            class="icon-button shrink-0" aria-label="Close">
                            <x-reicon name="x" class="size-4" />
                        </button>
                    </header>
                    <div class="application-settings-section-body flex flex-col gap-4">
                        <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                            Hosts that still need DNS at your provider (working domains are omitted). Create matching
                            Type / Name / Value records so traffic reaches this server.
                        </p>

                        @if ($availCustomMode && ($availRecords !== [] || $availPendingCustomHosts !== []))
                            <div class="flex flex-col gap-3" data-avail-cloudflare-records>
                                @if ($availManualRecords !== [])
                                    <x-callout type="warning" title="Owner action needed">
                                        The zone of {{ count($availManualRecords) === 1 ? 'this record' : 'these records' }} isn&#39;t connected to
                                        AvailCoolify (Security → Integration tokens), so the Owner adds the records marked
                                        &quot;Add in Cloudflare&quot; by hand. The Owner also gets a notice.
                                    </x-callout>
                                @elseif ($availRecords !== [])
                                    <x-callout type="info" title="Created automatically">
                                        AvailCoolify creates these records in Cloudflare through the connected token. Press Recheck in a minute.
                                    </x-callout>
                                @endif
                                @if ($availRecords === [])
                                    <x-callout type="danger" title="No public IP for DNS">
                                        This server is reached over a private address, so the IP for the record isn&#39;t known yet.
                                        The Owner sets it in Servers → {{ $this->application->destination?->server?->name ?? 'the server' }} → Public IP for DNS.
                                    </x-callout>
                                @else
                                    <div class="overflow-x-auto rounded-md border border-neutral-200 dark:border-coolgray-300">
                                        <table class="w-full min-w-[34rem] text-left text-sm">
                                            <thead
                                                class="bg-neutral-50 text-[12px] uppercase tracking-wide text-neutral-500 dark:bg-coolgray-100 dark:text-fg-dim">
                                                <tr>
                                                    <th class="px-3 py-2 font-medium">Type</th>
                                                    <th class="px-3 py-2 font-medium">Name</th>
                                                    <th class="px-3 py-2 font-medium">Target / IP address</th>
                                                    <th class="px-3 py-2 font-medium">Proxy status</th>
                                                    <th class="px-3 py-2 font-medium">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-neutral-200 dark:divide-coolgray-300">
                                                @foreach ($availRecords as $record)
                                                    <tr class="text-[13px] text-black dark:text-fg" wire:key="avail-cf-{{ $record['fqdn'] }}">
                                                        <td class="px-3 py-2.5">{{ $record['type'] }}</td>
                                                        <td class="px-3 py-2.5">
                                                            @include('livewire.project.shared.partials.dns-copy-cell', [
                                                                'text' => $record['name'],
                                                                'label' => 'Copy name',
                                                                'break' => true,
                                                            ])
                                                            <div class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                                                zone {{ $record['zone'] }}@if ($record['zone_guessed']) (check the zone name)@endif
                                                                @if ($record['purpose'] === 'PR previews') · for PR previews @endif
                                                            </div>
                                                        </td>
                                                        <td class="px-3 py-2.5">
                                                            @include('livewire.project.shared.partials.dns-copy-cell', [
                                                                'text' => $record['value'],
                                                                'label' => $record['type'] === 'CNAME' ? 'Copy target' : 'Copy IP address',
                                                                'break' => true,
                                                            ])
                                                        </td>
                                                        <td class="px-3 py-2.5 whitespace-nowrap">{{ $record['proxy'] }}</td>
                                                        <td class="px-3 py-2.5 whitespace-nowrap">
                                                            @if ($record['state'] === 'created')
                                                                <x-status-badge status="Created" type="success" />
                                                            @elseif ($record['state'] === 'creating')
                                                                <x-status-badge status="Being created" type="neutral" />
                                                            @else
                                                                <x-status-badge status="Add in Cloudflare" type="warning" />
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    @if ($availManualRecords !== [])
                                        <ol class="list-decimal space-y-1 pl-5 text-[13px] leading-6 text-neutral-600 dark:text-fg-dim">
                                            <li>Cloudflare → the zone above → DNS → Records → Add record.</li>
                                            <li>Paste the type, name and target, set the proxy status shown, TTL Auto.</li>
                                            <li>Save, then press Recheck here and redeploy the app. The HTTPS certificate follows automatically.</li>
                                        </ol>
                                        <div class="flex justify-end">
                                            <button type="button" class="button shrink-0" title="Copy the records to add" x-data="copyButton"
                                                @click.prevent="copy(@js($availCopyText))">
                                                <span x-text="copied ? 'Copied' : 'Copy all'"></span>
                                            </button>
                                        </div>
                                    @else
                                        <p class="text-[13px] leading-6 text-neutral-600 dark:text-fg-dim">Once the check is green, redeploy the app so it answers on the new domain.</p>
                                    @endif
                                @endif
                            </div>
                        @endif

                        @if ($availCustomMode && ($availRecords !== [] || $availPendingCustomHosts !== []) && count($dnsHints) === 0)
                            {{-- Only custom domains are pending: the card above covers them. --}}
                        @elseif (blank($serverIp) && count($dnsHints) === 0)
                            <x-callout type="warning" title="No server IP">
                                Could not determine a public IP for this destination. Set the server IP (or instance public IPv4 for localhost) first.
                            </x-callout>
                        @elseif (count($dnsHints) === 0)
                            <x-callout type="info" title="Nothing to configure">
                                No pending DNS entries. All listed domains already resolve correctly, or no domains are configured yet.
                                Use Recheck after changing DNS.
                            </x-callout>
                        @else
                            @if (collect($dnsProviderProposals)->isNotEmpty() && collect($dnsHints)->contains(fn (array $record): bool => ! \App\Support\DnsRecordHints::isPublicAddress($record['value'])))
                                <x-callout type="warning" title="No public IP">
                                    {{ \App\Support\DnsRecordHints::NO_PUBLIC_ADDRESS_MESSAGE }}
                                </x-callout>
                            @endif
                            <div class="overflow-x-auto rounded-md border border-neutral-200 dark:border-coolgray-300">
                                <table class="w-full min-w-[32rem] text-left text-sm">
                                    <thead
                                        class="bg-neutral-50 text-[12px] uppercase tracking-wide text-neutral-500 dark:bg-coolgray-100 dark:text-fg-dim">
                                        <tr>
                                            <th class="px-3 py-2 font-medium">Type</th>
                                            <th class="px-3 py-2 font-medium">Name</th>
                                            <th class="px-3 py-2 font-medium">Value</th>
                                            <th class="px-3 py-2 font-medium"><span class="sr-only">Action</span></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-neutral-200 dark:divide-coolgray-300">
                                        @foreach ($dnsHints as $record)
                                            <tr class="text-[13px] text-black dark:text-fg">
                                                <td class="px-3 py-2.5">{{ $record['type'] }}</td>
                                                <td class="px-3 py-2.5">
                                                    @include('livewire.project.shared.partials.dns-copy-cell', [
                                                        'text' => $record['name'],
                                                        'label' => 'Copy name',
                                                        'break' => true,
                                                    ])
                                                </td>
                                                <td class="px-3 py-2.5">
                                                    @include('livewire.project.shared.partials.dns-copy-cell', [
                                                        'text' => $record['value'],
                                                        'label' => 'Copy value',
                                                        'break' => true,
                                                    ])
                                                </td>
                                                <td class="px-3 py-2.5 text-right">
                                                    @php($recordProviders = \App\Support\DnsRecordHints::isPublicAddress($record['value']) ? collect($dnsProviderProposals)->where('hostname', $record['name'])->where('managed', false) : collect())
                                                    @foreach ($recordProviders as $provider)
                                                        <x-forms.button type="button"
                                                            wire:click="createManagedDnsRecord({{ \Illuminate\Support\Js::from($record['name']) }}, {{ $provider['zone_id'] }}, {{ \Illuminate\Support\Js::from($record['value']) }})"
                                                            wire:target="createManagedDnsRecord"
                                                            canGate="update" :canResource="$dnsAuthResource">
                                                            Add with {{ $provider['credential'] }}
                                                        </x-forms.button>
                                                    @endforeach
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if (filled($dnsCopyText))
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                        {{ count($dnsHints) }}
                                        {{ count($dnsHints) === 1 ? 'entry' : 'entries' }}
                                        · BIND zone format
                                    </p>
                                    <button type="button" class="button shrink-0"
                                        title="Copy as BIND-compatible zone file" x-data="copyButton"
                                        @click.prevent="copy(@js($dnsCopyText))">
                                        <span x-text="copied ? 'Copied' : 'Copy all'"></span>
                                    </button>
                                </div>
                            @endif
                        @endif

                        <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                            <x-forms.button type="button" @click="recheckDns()"
                                wire:target="recheckDnsRecordsInModal,checkAllDns,checkDomainDns"
                                title="Recheck DNS">
                                <x-reicon name="refresh" class="size-3.5" />
                                Recheck
                            </x-forms.button>
                            <x-forms.button type="button" @click="closeDnsRecords()" isHighlighted>
                                Done
                            </x-forms.button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
