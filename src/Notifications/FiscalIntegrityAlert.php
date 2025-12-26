<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Notifications;

use AichaDigital\Larabill\Exceptions\FiscalIntegrityException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * FiscalIntegrityAlert Notification
 *
 * Sends critical alert when fiscal configuration integrity is compromised.
 * Delivered via email and database channels.
 *
 * Uses queue if available, falls back to sync if not configured.
 *
 * @see ADR-001 for architectural decisions
 */
class FiscalIntegrityAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected FiscalIntegrityException $exception
    ) {
        // Use default connection (sync if not configured)
        $this->onConnection(config('queue.default', 'sync'));
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
            ->line($this->exception->getMessage());

        if ($this->exception->isGlobal()) {
            $mail->line(__('larabill::notifications.fiscal_integrity.global_impact'));
        } else {
            $mail->line(__('larabill::notifications.fiscal_integrity.atomic_impact', [
                'user_id' => $this->exception->getAffectedUserId(),
            ]));
        }

        $mail->line(__('larabill::notifications.fiscal_integrity.duplicate_ids', [
            'ids' => $this->exception->getDuplicateConfigs()->pluck('id')->implode(', '),
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
            'severity'             => $this->exception->getSeverity(),
            'is_global'            => $this->exception->isGlobal(),
            'affected_user_id'     => $this->exception->getAffectedUserId(),
            'duplicate_config_ids' => $this->exception->getDuplicateConfigs()->pluck('id')->toArray(),
            'message'              => $this->exception->getMessage(),
            'created_at'           => now()->toIso8601String(),
        ];
    }

    /**
     * Get email subject based on severity.
     */
    protected function getSubject(): string
    {
        if ($this->exception->isGlobal()) {
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

        if ($this->exception->isGlobal()) {
            return url($basePath.'/company-fiscal-configs');
        }

        return url($basePath.'/users/'.$this->exception->getAffectedUserId().'/edit');
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
