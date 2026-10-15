<div @class(['mb-0! min-w-0' => $rows !== [] || $requests !== [], 'hidden' => $rows === [] && $requests === []])>
    @if ($requests !== [])
        <section class="mb-4! min-w-0" data-avail-removal-requests>
            <x-section-heading title="Custom domain removal requests"
                subtitle="Members can&#39;t remove custom domains themselves. Approving removes the domain and the records AvailCoolify created for it." />
            <div class="overflow-x-auto rounded-md border border-neutral-200 dark:border-coolgray-300">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <thead class="bg-neutral-50 text-[12px] uppercase tracking-wide text-neutral-500 dark:bg-coolgray-100 dark:text-fg-dim">
                        <tr>
                            <th class="px-3 py-2 font-medium">Domain</th>
                            <th class="px-3 py-2 font-medium">App</th>
                            <th class="px-3 py-2 font-medium">Asked by</th>
                            <th class="px-3 py-2 font-medium"><span class="sr-only">Decision</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-coolgray-300">
                        @foreach ($requests as $request)
                            <tr class="align-top text-[13px] text-black dark:text-fg" wire:key="avail-removal-request-{{ $request['id'] }}">
                                <td class="px-3 py-2.5"><div style="width: 14rem; white-space: normal; overflow-wrap: anywhere;">{{ $request['host'] }}</div></td>
                                <td class="px-3 py-2.5">
                                    <div style="width: 14rem; white-space: normal; overflow-wrap: anywhere;">
                                        {{ $request['resource'] }}
                                        @if ($request['location'] !== '')
                                            <div class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $request['location'] }}</div>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-2.5">
                                    {{ $request['requester'] }}
                                    <div class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $request['requested'] }}</div>
                                </td>
                                <td class="px-3 py-2.5">
                                    <div class="flex justify-end gap-2">
                                        <x-forms.button type="button" wire:click="rejectRemoval({{ $request['id'] }})" wire:target="rejectRemoval,approveRemoval">Reject</x-forms.button>
                                        <x-forms.button type="button" isError wire:click="approveRemoval({{ $request['id'] }})" wire:target="rejectRemoval,approveRemoval">Approve removal</x-forms.button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
    @if ($rows !== [])
        <section class="mb-0! min-w-0" data-avail-custom-domains>
            <x-section-heading title="Custom domains"
                subtitle="Records are created automatically in Cloudflare zones connected under Security → Integration tokens. Those marked &quot;Add in Cloudflare&quot; are yours to add: TTL Auto, proxy status as shown." />
            <div class="overflow-x-auto rounded-md border border-neutral-200 dark:border-coolgray-300">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <thead class="bg-neutral-50 text-[12px] uppercase tracking-wide text-neutral-500 dark:bg-coolgray-100 dark:text-fg-dim">
                        <tr>
                            <th class="px-3 py-2 font-medium">Domain</th>
                            <th class="px-3 py-2 font-medium">App</th>
                            <th class="px-3 py-2 font-medium">DNS</th>
                            <th class="px-3 py-2 font-medium">Record to add</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-coolgray-300">
                        @foreach ($rows as $row)
                            <tr class="align-top text-[13px] text-black dark:text-fg" wire:key="avail-custom-domain-{{ $row['resource'] }}-{{ $row['host'] }}">
                                <td class="px-3 py-2.5 break-all">
                                    {{ $row['host'] }}
                                    @if ($row['kept'])
                                        <div class="text-[12px] text-neutral-500 dark:text-fg-dim">Kept from before the rules: can&#39;t be changed or added to</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5">
                                    {{-- Cells here are nowrap and ignore max-width: the wrapper lets long app names wrap. --}}
                                    <div style="width: 14rem; white-space: normal; overflow-wrap: anywhere;">
                                        @if ($row['url'])
                                            <a class="underline-offset-2 hover:underline" href="{{ $row['url'] }}" wire:navigate>{{ $row['resource'] }}</a>
                                        @else
                                            {{ $row['resource'] }}
                                        @endif
                                        @if ($row['location'] !== '')
                                            <div class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $row['location'] }}</div>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-2.5">
                                    <x-status-badge :label="$row['status']['label']" :type="$row['status']['type']" />
                                    @if ($row['status']['type'] === 'error' && $row['status']['message'] !== '')
                                        <div style="width: 14rem; white-space: normal;" class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ $row['status']['message'] }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 font-mono text-[12px] whitespace-nowrap">
                                    @if ($row['record'])
                                        <div>{{ $row['record']['type'] }} {{ $row['record']['name'] }} → {{ $row['record']['value'] }}</div>
                                        @if ($row['preview_record'])
                                            <div>{{ $row['preview_record']['type'] }} {{ $row['preview_record']['name'] }} → {{ $row['preview_record']['value'] }} <span class="font-sans text-neutral-500 dark:text-fg-dim">(PR previews)</span></div>
                                        @endif
                                        <div class="font-sans text-neutral-500 dark:text-fg-dim">
                                            zone {{ $row['record']['zone'] }} · {{ ($row['record']['proxied'] ?? false) ? 'proxied' : 'DNS only' }} ·
                                            @if ($row['record']['created'] ?? false)
                                                created automatically
                                            @elseif ($row['record']['automatic'] ?? false)
                                                being created
                                            @else
                                                add in Cloudflare
                                            @endif
                                        </div>
                                    @else
                                        <span class="font-sans text-neutral-500 dark:text-fg-dim">Set the server&#39;s Public IP for DNS first (Servers → General).</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
