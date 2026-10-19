<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

/**
 * Avail: a member's request to remove a custom domain from their app, decided by the Owner.
 */
class AvailDomainRemovalRequest extends Model
{
    protected $fillable = ['team_id', 'application_id', 'host', 'requested_by', 'status', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public static function pendingFor(Application $application, string $host): ?self
    {
        return self::where('application_id', $application->id)->where('host', strtolower($host))->where('status', 'pending')->first();
    }

    /** Tell the member who asked how the Owner decided, when transactional email is set up. */
    public function emailRequester(): void
    {
        $email = $this->requester?->email;
        if (! $email || ! is_transactional_emails_enabled()) {
            return;
        }
        $approved = $this->status === 'approved';
        $app = $this->application?->name ?? 'your app';
        $request = $this;
        dispatch(function () use ($request, $email, $approved, $app) {
            try {
                $mail = (new MailMessage)
                    ->subject('AvailCoolify: custom domain removal '.($approved ? 'approved' : 'rejected'))
                    ->line($approved
                        ? "The Owner removed {$request->host} from {$app}. Redeploy the app so it stops answering on it."
                        : "The Owner kept {$request->host} on {$app}. Ask them if you need to know why.");
                send_user_an_email($mail, $email);
            } catch (\Throwable $e) {
                Log::warning("Avail removal decision email to {$email} failed: ".$e->getMessage());
            }
        })->onQueue('high');
    }
}
