<?php

namespace App\Notifications\Avail;

use App\Models\Application;
use App\Models\Server;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Avail: custom domains were added to or removed from an app. For new domains it lists the DNS records
 * the Owner adds by hand in Cloudflare; for removed ones the records to delete. Goes to the team's
 * notification channels (Slack, Discord, Telegram, Pushover, webhook; "general" events never email the
 * whole team) and, when transactional email is set up, to the Owner by email.
 *
 * Only plain values are kept: the app may be deleted before the queued notice runs.
 */
class CustomDomainDnsChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    /**
     * @param  list<string>  $added
     * @param  list<string>  $removed
     * @param  list<string>  $previewParents
     * @param  list<array<string, mixed>>|null  $records  the records to add (new app domains), from availDesiredRecord()
     */
    public function __construct(
        public string $resourceName,
        public string $location,
        public ?string $domainsUrl,
        public ?int $serverId,
        public array $added,
        public array $removed,
        public array $previewParents,
        public ?array $records = null,
    ) {
        $this->onQueue('high');
    }

    /**
     * @param  list<string>  $added
     * @param  list<string>  $removed
     * @param  list<array<string, mixed>>|null  $records
     */
    public static function for(Application|ServiceApplication $resource, array $added, array $removed, ?array $records = null): self
    {
        if ($resource instanceof Application) {
            $environment = $resource->environment;
            $project = $environment?->project;
            $server = availApplicationServer($resource);
            // base_url(), not route(): queued notices run without a request, where route() gives APP_URL.
            $domainsUrl = ($project && $environment && ! $resource->trashed())
                ? base_url()."/project/{$project->uuid}/environment/{$environment->uuid}/application/{$resource->uuid}/domains"
                : null;
            $previewParents = array_values(array_intersect(availPreviewParentHosts($resource), $added));
        } else {
            $service = $resource->service;
            $environment = $service?->environment;
            $project = $environment?->project;
            $server = availServiceApplicationServer($resource);
            $domainsUrl = null;
            $previewParents = [];
        }

        return new self(
            resourceName: (string) ($resource instanceof Application ? $resource->name : ($resource->service?->name.' / '.$resource->name)),
            location: trim(($project?->name ?? '').' / '.($environment?->name ?? ''), ' /'),
            domainsUrl: $domainsUrl,
            serverId: $server?->id,
            added: $added,
            removed: $removed,
            previewParents: $previewParents,
            records: $records,
        );
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('general');
    }

    public function title(): string
    {
        return $this->added === [] ? 'Custom domain removed: delete the Cloudflare records' : 'Custom domain added: add the Cloudflare record';
    }

    /**
     * The message as plain lines (Slack and email render the same content).
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $server = $this->serverId !== null ? Server::find($this->serverId) : null;
        $ip = availDnsRecordIp($server);
        $where = $this->location !== '' ? " ({$this->location})" : '';
        $lines = [];

        if ($this->added !== [] && $this->records !== null) {
            $lines[] = 'Custom domain added to '.$this->resourceName.$where.': '.implode(', ', $this->added);
            $hosts = collect($this->records)->pluck('fqdn')->map(fn ($fqdn) => ltrim((string) $fqdn, '*.'))->unique();
            if ($hosts->count() < count($this->added)) {
                $lines[] = 'Owner: this server has no public IP for DNS yet. Set it in Servers > '.($server?->name ?? 'the server').' > Public IP for DNS, then open the app\'s Domains page for the records.';
            }
            if ($this->records !== []) {
                $lines[] = 'Owner: add these records in Cloudflare (the zone > DNS > Records > Add record), TTL Auto:';
            }
            foreach ($this->records as $record) {
                $note = $record['purpose'] === 'PR previews' ? ' (PR previews)' : '';
                $proxy = ($record['proxied'] ?? false) ? 'proxied' : 'DNS only';
                $lines[] = "  {$record['type']}  {$record['name']}  ->  {$record['value']}  in zone {$record['zone']}, {$proxy}{$note}";
            }
            if ($hint = availOtherZoneHint($this->records)) {
                $lines[] = $hint;
            }
            $lines[] = 'AvailCoolify has no Cloudflare access, so it can\'t add them itself. HTTPS starts once the records work; redeploy the app so it answers on the new domain.';
        } elseif ($this->added !== []) {
            $lines[] = 'Custom domain added to '.$this->resourceName.$where.': '.implode(', ', $this->added);
            if ($ip === null) {
                $lines[] = 'Owner: this server has no public IP for DNS yet. Set it in Servers > '.($server?->name ?? 'the server').' > Public IP for DNS, then open the app\'s Domains page for the records.';
            } else {
                $lines[] = 'Owner: add these records in Cloudflare. Proxy status DNS only (grey cloud), TTL Auto:';
                foreach (availCloudflareRecords($this->added, $this->previewParents, $ip) as $record) {
                    $note = $record['purpose'] === 'PR previews' ? ' (PR previews)' : '';
                    $lines[] = "  {$record['type']}  {$record['name']}  ->  {$record['value']}  in zone {$record['zone']}{$note}";
                }
            }
            $lines[] = 'AvailCoolify has no Cloudflare access, so it can\'t add them itself. HTTPS starts once the record works.';
        }

        if ($this->removed !== []) {
            $lines[] = 'Custom domain removed from '.$this->resourceName.$where.': '.implode(', ', $this->removed);
            $lines[] = 'Owner: delete these records in Cloudflare unless something else still uses them:';
            foreach ($this->removed as $host) {
                $zone = availDnsZoneFor($host);
                $wildcard = $zone['name'] === '@' ? '*' : '*.'.$zone['name'];
                $lines[] = "  {$zone['name']}  in zone {$zone['zone']}, and {$wildcard} if it exists (PR previews)";
            }
        }

        if ($this->domainsUrl) {
            $lines[] = 'Domains page: '.$this->domainsUrl;
        }

        return $lines;
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: $this->title(),
            description: implode("\n", $this->lines()),
            color: SlackMessage::warningColor(),
        );
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: $this->title(),
            description: implode("\n", $this->lines()),
            color: DiscordMessage::warningColor(),
        );
    }

    public function toTelegram(): array
    {
        return ['message' => $this->title()."\n".implode("\n", $this->lines())];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: $this->title(),
            level: 'warning',
            message: implode("\n", $this->lines()),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => true,
            'event' => 'avail_custom_domain_changed',
            'message' => $this->title(),
            'resource' => $this->resourceName,
            'added' => $this->added,
            'removed' => $this->removed,
            'details' => $this->lines(),
            'url' => $this->domainsUrl ?? base_url(),
        ];
    }

    public function toMail(): MailMessage
    {
        $mail = (new MailMessage)->subject('AvailCoolify: '.$this->title());
        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail;
    }

    /** Email the owner(s) of the Avail Team, queued, when transactional email is set up. */
    public function emailOwners(): void
    {
        if (! is_transactional_emails_enabled()) {
            return;
        }
        $notice = $this;
        dispatch(function () use ($notice) {
            $owners = Team::find(0)?->members()->wherePivot('role', 'owner')->pluck('email') ?? collect();
            foreach ($owners->filter() as $email) {
                try {
                    send_user_an_email($notice->toMail(), $email);
                } catch (\Throwable $e) {
                    Log::warning("Avail custom domain email to {$email} failed: ".$e->getMessage());
                }
            }
        })->onQueue('high');
    }
}
