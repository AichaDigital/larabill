<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\RoundingMode;
use AichaDigital\Larabill\Enums\CancellationType;
use AichaDigital\Larabill\Enums\ServiceStatus;
use AichaDigital\Larabill\Events\ServiceActivated;
use AichaDigital\Larabill\Events\ServiceCancelled;
use AichaDigital\Larabill\Events\ServiceExpired;
use AichaDigital\Larabill\Events\ServiceSuspended;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service Lifecycle Management
 *
 * Handles activation, suspension, cancellation, and expiration of services
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class ServiceLifecycleService
{
    /**
     * Activate a service
     */
    public function activate(ArticleServiceStatus $service): void
    {
        $service->update([
            'status'     => ServiceStatus::ACTIVE,
            'started_at' => $service->started_at ?? now(),
        ]);

        ServiceActivated::dispatch($service);
    }

    /**
     * Suspend a service with a reason
     */
    public function suspend(ArticleServiceStatus $service, ?string $reason = null): void
    {
        $metadata               = $service->metadata ?? [];
        $metadata['suspension'] = [
            'suspended_at' => now()->toIso8601String(),
            'reason'       => $reason,
        ];

        $service->update([
            'status'   => ServiceStatus::SUSPENDED,
            'metadata' => $metadata,
        ]);

        ServiceSuspended::dispatch($service, $reason);
    }

    /**
     * Cancel a service with specified cancellation type
     */
    public function cancel(
        ArticleServiceStatus $service,
        CancellationType $type,
        ?string $reason = null
    ): void {
        $effectiveDate = $this->calculateCancellationEffectiveDate($service, $type);

        $metadata                 = $service->metadata ?? [];
        $metadata['cancellation'] = [
            'requested_at'   => now()->toIso8601String(),
            'effective_date' => $effectiveDate->toIso8601String(),
            'type'           => $type->value,
            'reason'         => $reason,
        ];

        $updates = [
            'cancellation_type'         => $type,
            'cancellation_requested_at' => now(),
            'cancellation_effective_at' => $effectiveDate,
            'refund_unused'             => $type->requiresRefund(),
            'metadata'                  => $metadata,
        ];

        // If immediate cancellation, update status now
        if ($type === CancellationType::IMMEDIATE) {
            $updates['status'] = ServiceStatus::CANCELLED;
        }

        $service->update($updates);

        ServiceCancelled::dispatch($service, $type, $reason);
    }

    /**
     * Expire a service
     */
    public function expire(ArticleServiceStatus $service): void
    {
        $service->update([
            'status'     => ServiceStatus::EXPIRED,
            'expires_at' => $service->expires_at ?? now(),
        ]);

        ServiceExpired::dispatch($service);
    }

    /**
     * Calculate refund amount for a service based on unused period
     *
     * @return int|null Amount in Base100, or null if no refund
     */
    public function calculateRefund(ArticleServiceStatus $service): ?int
    {
        if (! $service->refund_unused || ! $service->next_billing_date || $service->effective_price === null) {
            return null;
        }

        $today              = now()->startOfDay();
        $nextBillingDate    = $service->next_billing_date->startOfDay();
        $lastBillingDate    = $this->calculateLastBillingDate($service);
        $totalDaysInPeriod  = $lastBillingDate->diffInDays($nextBillingDate);
        $unusedDays         = $today->diffInDays($nextBillingDate);

        if ($unusedDays <= 0 || $totalDaysInPeriod <= 0) {
            return null;
        }

        // Proportional refund, settled to the cent with HalfUp (EU/Spain norm).
        $refundAmount = $service->effective_price
            ->multipliedBy((int) $unusedDays)
            ->dividedBy((int) $totalDaysInPeriod, 2, RoundingMode::HalfUp)
            ->unscaledValue();

        return $refundAmount;
    }

    /**
     * Process all services that have expired
     *
     * Uses individual transactions per service for isolation.
     *
     * @return int Number of services expired
     */
    public function processExpiredServices(): int
    {
        $expiredServices = ArticleServiceStatus::query()
            ->where('status', ServiceStatus::ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $count = 0;

        foreach ($expiredServices as $service) {
            try {
                DB::transaction(function () use ($service): void {
                    // Refresh within transaction for idempotency
                    $service->refresh();

                    // Skip if already expired (idempotency)
                    if ($service->status !== ServiceStatus::ACTIVE) {
                        return;
                    }

                    $this->expire($service);
                });
                $count++;
            } catch (\Throwable $e) {
                Log::error('Failed to expire service', [
                    'service_id' => $service->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Process all pending cancellations that should take effect
     *
     * Uses individual transactions per service for isolation.
     *
     * @return int Number of services cancelled
     */
    public function processPendingCancellations(): int
    {
        $pendingCancellations = ArticleServiceStatus::query()
            ->where('status', ServiceStatus::ACTIVE)
            ->whereNotNull('cancellation_effective_at')
            ->where('cancellation_effective_at', '<=', now())
            ->get();

        $count = 0;

        foreach ($pendingCancellations as $service) {
            try {
                DB::transaction(function () use ($service): void {
                    // Refresh within transaction for idempotency
                    $service->refresh();

                    // Skip if already cancelled (idempotency)
                    if ($service->status !== ServiceStatus::ACTIVE) {
                        return;
                    }

                    $cancellationType = $service->cancellation_type;
                    if ($cancellationType === null) {
                        throw new \RuntimeException("Service {$service->id} is scheduled for cancellation but has no cancellation_type.");
                    }

                    $service->update(['status' => ServiceStatus::CANCELLED]);
                    ServiceCancelled::dispatch(
                        $service,
                        $cancellationType,
                        $service->metadata['cancellation']['reason'] ?? null
                    );
                });
                $count++;
            } catch (\Throwable $e) {
                Log::error('Failed to cancel service', [
                    'service_id' => $service->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Calculate the effective date for cancellation based on type
     */
    protected function calculateCancellationEffectiveDate(
        ArticleServiceStatus $service,
        CancellationType $type
    ): Carbon {
        return match ($type) {
            CancellationType::IMMEDIATE     => now(),
            CancellationType::END_OF_PERIOD => $service->next_billing_date ?? now(),
            CancellationType::NOTICE_PERIOD => now()->addDays(
                $service->article->metadata['notice_period_days'] ?? 30
            ),
        };
    }

    /**
     * Calculate last billing date (approximation based on frequency)
     */
    protected function calculateLastBillingDate(ArticleServiceStatus $service): Carbon
    {
        if (! $service->next_billing_date) {
            return now();
        }

        // Use the service's billing frequency (stored at contract time)
        $frequency = $service->billing_frequency;

        return $frequency->subtractFromDate(
            $service->next_billing_date->copy(),
            1
        );
    }
}
