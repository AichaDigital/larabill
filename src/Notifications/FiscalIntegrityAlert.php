<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Notifications;

use AichaDigital\Larabill\Exceptions\FiscalIntegrityException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FiscalIntegrityAlert Notification
 *
 * Sends critical alert when fiscal configuration integrity is compromised.
 * Delivered via email and database channels.
 *
 * Carries scalar context only (severity, affected user id, duplicate config
 * ids, package message): the queue serializes this object verbatim into the
 * `jobs`/`failed_jobs` payloads, so no exception object, model or stack trace
 * may travel with it (AID-1464).
 *
 * Uses queue if available, falls back to sync if not configured.
 *
 * @see ADR-001 for architectural decisions
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class FiscalIntegrityAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string|int>  $duplicateConfigIds
     */
    public function __construct(
        protected string $severity,
        protected ?string $affectedUserId,
        protected array $duplicateConfigIds,
        protected string $message,
    ) {
        // Use default connection (sync if not configured)
        $this->onConnection(config('queue.default', 'sync'));
    }

    /**
     * Build the notification from the fiscal integrity exception, keeping only
     * queue-safe scalars (AID-1464).
     */
    public static function fromException(FiscalIntegrityException $exception): self
    {
        $duplicateConfigIds = [];

        foreach ($exception->getDuplicateConfigs() as $duplicateConfig) {
            $id = $duplicateConfig instanceof Model
                ? $duplicateConfig->getKey()
                : null;

            if (is_int($id) || is_string($id)) {
                $duplicateConfigIds[] = $id;
            }
        }

        return new self(
            $exception->getSeverity(),
            $exception->getAffectedUserId(),
            $duplicateConfigIds,
            $exception->getMessage(),
        );
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->error()
            ->subject($this->getSubject())
            ->greeting(__('larabill::notifications.fiscal_integrity.greeting'))
            ->line($this->message);

        if ($this->isGlobal()) {
            $mail->line(__('larabill::notifications.fiscal_integrity.global_impact'));
        } else {
            $mail->line(__('larabill::notifications.fiscal_integrity.atomic_impact', [
                'user_id' => $this->affectedUserId,
            ]));
        }

        $mail->line(__('larabill::notifications.fiscal_integrity.duplicate_ids', [
            'ids' => implode(', ', $this->duplicateConfigIds),
        ]));

        $mail->action(
            __('larabill::notifications.fiscal_integrity.action'),
            $this->getDashboardUrl()
        );

        $mail->line(__('larabill::notifications.fiscal_integrity.urgency'));

        return $mail;
    }

    /**
     * Get the array representation of the notification (for database).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type'                 => 'fiscal_integrity_alert',
            'severity'             => $this->severity,
            'is_global'            => $this->isGlobal(),
            'affected_user_id'     => $this->affectedUserId,
            'duplicate_config_ids' => $this->duplicateConfigIds,
            'message'              => $this->message,
            'created_at'           => now()->toIso8601String(),
        ];
    }

    /**
     * Whether this is a global (company-level) integrity issue.
     */
    protected function isGlobal(): bool
    {
        return $this->severity === FiscalIntegrityException::SEVERITY_GLOBAL;
    }

    /**
     * Get email subject based on severity.
     */
    protected function getSubject(): string
    {
        if ($this->isGlobal()) {
            return __('larabill::notifications.fiscal_integrity.subject_global');
        }

        return __('larabill::notifications.fiscal_integrity.subject_atomic');
    }

    /**
     * Get the dashboard URL for the action button.
     */
    protected function getDashboardUrl(): string
    {
        // Use config or default to admin path
        $basePath = config('larabill.admin.path', '/admin');

        if ($this->isGlobal()) {
            return url($basePath.'/company-fiscal-configs');
        }

        return url($basePath.'/users/'.$this->affectedUserId.'/edit');
    }

    /**
     * Determine if the notification should be sent.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        // Always send for critical fiscal integrity issues
        return true;
    }

    /**
     * Get the notification's database type.
     */
    public function databaseType(object $notifiable): string
    {
        return 'fiscal_integrity_alert';
    }
}
