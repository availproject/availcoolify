<?php

namespace App\Notifications\Avail;

use App\Models\AvailDomainRemovalRequest;
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
 * Avail: a member asked the Owner to remove a custom domain. Goes to the team's notification channels
 * and, when transactional email is set up, to the Owner by email. Only plain values are kept.
 */
class CustomDomainRemovalRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(
        public string $host,
        public string $application,
        public string $requester,
        public string $dashboardUrl,
    ) {
        $this->onQueue('high');
    }

    public static function for(AvailDomainRemovalRequest $request): self
    {
        return new self(
            host: $request->host,
            application: (string) ($request->application?->name ?? 'an app'),
            requester: (string) ($request->requester?->name ?: $request->requester?->email ?: 'A member'),
            dashboardUrl: base_url().'/',
        );
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('general');
    }

    public function title(): string
    {
        return 'Custom domain removal requested';
    }

    public function text(): string
    {
        return "{$this->requester} asked to remove {$this->host} from {$this->application}. "
            ."The Owner approves or rejects it on the dashboard, under Custom domains: {$this->dashboardUrl}";
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(title: $this->title(), description: $this->text(), color: SlackMessage::warningColor());
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(title: $this->title(), description: $this->text(), color: DiscordMessage::warningColor());
    }

    public function toTelegram(): array
    {
        return ['message' => $this->title()."\n".$this->text()];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(title: $this->title(), level: 'warning', message: $this->text());
    }

    public function toWebhook(): array
    {
        return [
            'success' => true,
            'event' => 'avail_custom_domain_removal_requested',
            'message' => $this->title(),
            'host' => $this->host,
            'application' => $this->application,
            'requested_by' => $this->requester,
            'url' => $this->dashboardUrl,
        ];
    }

    public function toMail(): MailMessage
    {
        return (new MailMessage)->subject('AvailCoolify: '.$this->title())->line($this->text());
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
                    Log::warning("Avail removal request email to {$email} failed: ".$e->getMessage());
                }
            }
        })->onQueue('high');
    }
}
