<div @class(['mb-0! min-w-0' => $rows !== [], 'hidden' => $rows === []])>
    @if ($rows !== [])
        <section class="mb-0! min-w-0" data-avail-custom-domains>
            <x-section-heading title="Custom domains"
                subtitle="Only you can add these records in Cloudflare: A record, DNS only (grey cloud), TTL Auto. AvailCoolify has no Cloudflare access." />
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
                                    @if ($row['url'])
                                        <a class="underline-offset-2 hover:underline" href="{{ $row['url'] }}" wire:navigate>{{ $row['resource'] }}</a>
                                    @else
                                        {{ $row['resource'] }}
                                    @endif
                                    @if ($row['location'] !== '')
                                        <div class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $row['location'] }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5">
                                    <x-status-badge :label="$row['status']['label']" :type="$row['status']['type']" />
                                    @if ($row['status']['type'] === 'error' && $row['status']['message'] !== '')
                                        <div class="mt-1 max-w-xs text-[12px] text-neutral-500 dark:text-fg-dim">{{ $row['status']['message'] }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 font-mono text-[12px]">
                                    @if ($row['record'])
                                        <div>{{ $row['record']['type'] }} {{ $row['record']['name'] }} → {{ $row['record']['value'] }}</div>
                                        @if ($row['preview_record'])
                                            <div>{{ $row['preview_record']['type'] }} {{ $row['preview_record']['name'] }} → {{ $row['preview_record']['value'] }} <span class="font-sans text-neutral-500 dark:text-fg-dim">(PR previews)</span></div>
                                        @endif
                                        <div class="font-sans text-neutral-500 dark:text-fg-dim">zone {{ $row['record']['zone'] }}</div>
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
