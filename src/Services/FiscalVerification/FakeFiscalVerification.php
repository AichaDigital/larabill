<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\FiscalVerification;

use AichaDigital\Larabill\Contracts\Services\FiscalVerificationContract;

/**
 * Fake Fiscal Verification Service
 *
 * Mock implementation for testing without real fiscal verification dependencies.
 * Always returns success with fake data.
 *
 * Usage in tests:
 *
 * ```php
 * $fake = new FakeFiscalVerification([
 *     'id' => 'FAKE-123',
 *     'qr' => 'fake-qr-code',
 *     'hash' => 'fake-hash-123',
 * ]);
 *
 * $this->app->instance(FiscalVerificationContract::class, $fake);
 * ```
 */
class FakeFiscalVerification implements FiscalVerificationContract
{
    /**
     * Fake verification responses.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $responses = [];

    /**
     * History of verification calls.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $history = [];

    /**
     * Constructor.
     *
     * @param  array<string, mixed>  $defaultResponse  Default response data
     */
    public function __construct(array $defaultResponse = [])
    {
        $this->responses['default'] = array_merge([
            'id'        => 'FAKE-'.uniqid(),
            'qr'        => 'data:image/png;base64,'.base64_encode('fake-qr'),
            'hash'      => hash('sha256', 'fake-invoice'),
            'signature' => 'fake-signature',
            'timestamp' => now()->toIso8601String(),
            'metadata'  => ['provider' => 'fake'],
        ], $defaultResponse);
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function verify(array $invoiceData): array
    {
        $this->history[] = [
            'method'  => 'verify',
            'invoice' => $invoiceData,
            'time'    => now(),
        ];

        $response = $this->responses['default'];

        return [
            'success'   => true,
            'id'        => $response['id'],
            'qr'        => $response['qr'],
            'hash'      => $response['hash'],
            'signature' => $response['signature'],
            'timestamp' => $response['timestamp'],
            'metadata'  => $response['metadata'],
            'error'     => null,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getStatus(string $verificationId): array
    {
        $this->history[] = [
            'method' => 'getStatus',
            'id'     => $verificationId,
            'time'   => now(),
        ];

        return [
            'verified'  => true,
            'status'    => 'verified',
            'timestamp' => now()->toIso8601String(),
            'metadata'  => ['provider' => 'fake'],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function cancel(string $verificationId, string $reason): array
    {
        $this->history[] = [
            'method' => 'cancel',
            'id'     => $verificationId,
            'reason' => $reason,
            'time'   => now(),
        ];

        return [
            'success' => true,
            'message' => 'Verification cancelled successfully',
            'error'   => null,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getSupportedCountries(): array
    {
        return ['ES', 'FR', 'IT', 'DE', 'PT']; // Fake all EU countries
    }

    /**
     * {@inheritDoc}
     */
    public function getConfiguration(): array
    {
        return [
            'provider'    => 'fake',
            'environment' => 'testing',
            'version'     => '1.0.0',
            'enabled'     => true,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function validateInvoiceData(array $invoiceData): array
    {
        // Fake validation always passes
        return [
            'valid'  => true,
            'errors' => [],
        ];
    }

    /**
     * Get verification history.
     *
     * @return array<array<string, mixed>>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Clear verification history.
     */
    public function clearHistory(): void
    {
        $this->history = [];
    }

    /**
     * Set custom response for next verification.
     *
     * @param  array<string, mixed>  $response
     */
    public function setNextResponse(array $response): void
    {
        $this->responses['next'] = $response;
    }

    /**
     * Make the next verification fail.
     *
     * @param  string  $error  Error message
     */
    public function failNext(string $error = 'Verification failed'): void
    {
        $this->responses['next'] = [
            'success'   => false,
            'id'        => null,
            'qr'        => null,
            'hash'      => null,
            'signature' => null,
            'timestamp' => null,
            'metadata'  => null,
            'error'     => $error,
        ];
    }

    /**
     * Assert that verification was called.
     */
    public function assertVerifyCalled(?int $times = null): void
    {
        $calls = collect($this->history)->where('method', 'verify')->count();

        if ($times !== null && $calls !== $times) {
            throw new \Exception("Expected verify() to be called {$times} times, but was called {$calls} times");
        }

        if ($times === null && $calls === 0) {
            throw new \Exception('Expected verify() to be called at least once, but it was not called');
        }
    }

    /**
     * Assert that getStatus was called.
     */
    public function assertGetStatusCalled(?int $times = null): void
    {
        $calls = collect($this->history)->where('method', 'getStatus')->count();

        if ($times !== null && $calls !== $times) {
            throw new \Exception("Expected getStatus() to be called {$times} times, but was called {$calls} times");
        }

        if ($times === null && $calls === 0) {
            throw new \Exception('Expected getStatus() to be called at least once, but it was not called');
        }
    }

    /**
     * Assert that cancel was called.
     */
    public function assertCancelCalled(?int $times = null): void
    {
        $calls = collect($this->history)->where('method', 'cancel')->count();

        if ($times !== null && $calls !== $times) {
            throw new \Exception("Expected cancel() to be called {$times} times, but was called {$times} times");
        }

        if ($times === null && $calls === 0) {
            throw new \Exception('Expected cancel() to be called at least once, but it was not called');
        }
    }
}
