<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Commands;

use AichaDigital\Larabill\Services\VatApiIntegrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TestVatApisCommand extends Command
{
    protected $signature = 'larabill:test-vat-apis
                            {--save-stubs : Save API responses as stubs for testing}
                            {--vat-number=ESB12345678 : VAT number to test}
                            {--country=ES : Country code to test}
                            {--test-fallback : Test automatic fallback between APIs}';

    protected $description = 'Test VAT verification APIs and optionally save responses as stubs';

    public function handle(): int
    {
        $this->info('Testing VAT verification APIs...');

        // Load .env.local if it exists
        $this->loadLocalEnv();

        $vatNumber = $this->option('vat-number');
        $countryCode = $this->option('country');
        $saveStubs = $this->option('save-stubs');
        $testFallback = $this->option('test-fallback');

        // Check if API keys are configured
        $this->checkApiConfiguration();

        if ($testFallback) {
            // Test automatic fallback
            $this->testAutomaticFallback($vatNumber, $countryCode, $saveStubs);
        } else {
            // Test individual APIs
            $this->testIndividualApis($vatNumber, $countryCode, $saveStubs);
        }

        return self::SUCCESS;
    }

    /**
     * Test individual APIs separately.
     */
    private function testIndividualApis(string $vatNumber, string $countryCode, bool $saveStubs): void
    {
        $service = new VatApiIntegrationService();

        // Test AbstractAPI
        $this->info("Testing AbstractAPI with VAT: {$vatNumber} ({$countryCode})");
        $abstractApiResult = $service->verifyWithAbstractApi($vatNumber, $countryCode);
        $this->displayResult('AbstractAPI', $abstractApiResult);

        // Test API Layer
        $this->info("Testing API Layer with VAT: {$vatNumber} ({$countryCode})");
        $apiLayerResult = $service->verifyWithApiLayer($vatNumber, $countryCode);
        $this->displayResult('API Layer', $apiLayerResult);

        // Save stubs if requested
        if ($saveStubs) {
            $this->saveStubs($vatNumber, $countryCode, $abstractApiResult, $apiLayerResult);
        }
    }

    /**
     * Test automatic fallback between APIs.
     */
    private function testAutomaticFallback(string $vatNumber, string $countryCode, bool $saveStubs): void
    {
        $this->info("Testing automatic fallback with VAT: {$vatNumber} ({$countryCode})");
        $this->line('This will test the VatVerificationService with automatic fallback...');
        $this->line('');

        // Use the main service that has fallback logic
        $vatVerificationService = app(\AichaDigital\Larabill\Services\VatVerificationService::class);
        
        $result = $vatVerificationService->verifyVatNumber($vatNumber, $countryCode);
        
        $this->displayResult('VatVerificationService (with fallback)', $result);
        
        // Save stub if requested
        if ($saveStubs) {
            $this->saveFallbackStub($vatNumber, $countryCode, $result);
        }
    }

    /**
     * Save fallback test stub.
     */
    private function saveFallbackStub(string $vatNumber, string $countryCode, array $result): void
    {
        $stubsDir = __DIR__.'/../../tests/stubs/vat-responses';

        if (! File::exists($stubsDir)) {
            File::makeDirectory($stubsDir, 0755, true);
        }

        $key = strtolower($countryCode.'_'.$vatNumber);

        $fallbackStub = [
            'api' => 'fallback_test',
            'vat_number' => $vatNumber,
            'country_code' => $countryCode,
            'response' => $result['response_data'] ?? null,
            'processed' => $result,
            'timestamp' => now()->toDateTimeString(),
        ];

        File::put(
            $stubsDir."/fallback_test_{$key}.json",
            json_encode($fallbackStub, JSON_PRETTY_PRINT)
        );

        $this->info("Fallback test stub saved to: {$stubsDir}");
        $this->line("  - fallback_test_{$key}.json");
    }

    /**
     * Load .env.local file if it exists.
     */
    private function loadLocalEnv(): void
    {
        $envLocalPath = __DIR__.'/../../.env.local';

        if (File::exists($envLocalPath)) {
            $this->info('Loading .env.local configuration...');

            $lines = File::lines($envLocalPath);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && ! str_starts_with($line, '#')) {
                    [$key, $value] = explode('=', $line, 2);
                    if (! array_key_exists($key, $_ENV)) {
                        $_ENV[$key] = $value;
                        putenv("{$key}={$value}");
                    }
                }
            }
        }
    }

    /**
     * Check if API keys are configured.
     */
    private function checkApiConfiguration(): void
    {
        $abstractApiKey = env('LARABILL_ABSTRACTAPI_KEY');
        $apiLayerKey = env('LARABILL_APILAYER_KEY');

        if (! $abstractApiKey || $abstractApiKey === 'your_abstractapi_key_here') {
            $this->warn('⚠️  LARABILL_ABSTRACTAPI_KEY not configured - using mock responses');
        } else {
            $this->info('✅ LARABILL_ABSTRACTAPI_KEY configured');
        }

        if (! $apiLayerKey || $apiLayerKey === 'your_apilayer_key_here') {
            $this->warn('⚠️  LARABILL_APILAYER_KEY not configured - using mock responses');
        } else {
            $this->info('✅ LARABILL_APILAYER_KEY configured');
        }

        $this->line('');
    }

    private function displayResult(string $apiName, array $result): void
    {
        $this->line("  {$apiName} Result:");
        $this->line('    Valid: '.($result['is_valid'] ? 'Yes' : 'No'));
        $this->line('    Company: '.($result['company_name'] ?? 'N/A'));
        $this->line('    Address: '.($result['company_address'] ?? 'N/A'));
        $this->line('    API Source: '.$result['api_source']);
        
        // Show fallback information
        if (isset($result['fallback_used']) && $result['fallback_used']) {
            $this->warn("    ⚠️  FALLBACK USED - Primary API ({$result['primary_api_failed']}) failed");
        }
        
        if (isset($result['mock_fallback']) && $result['mock_fallback']) {
            $this->error("    ❌ MOCK FALLBACK - All APIs failed");
        }
        
        if (isset($result['all_apis_failed']) && $result['all_apis_failed']) {
            $this->error("    ❌ ALL APIS FAILED - Using mock response");
        }

        if (isset($result['response_data'])) {
            $this->line('    Raw Response: '.json_encode($result['response_data'], JSON_PRETTY_PRINT));
        }

        $this->line('');
    }

    private function saveStubs(string $vatNumber, string $countryCode, array $abstractApiResult, array $apiLayerResult): void
    {
        $stubsDir = __DIR__.'/../../tests/stubs/vat-responses';

        if (! File::exists($stubsDir)) {
            File::makeDirectory($stubsDir, 0755, true);
        }

        $key = strtolower($countryCode.'_'.$vatNumber);

        // Save AbstractAPI stub
        $abstractApiStub = [
            'api' => 'abstractapi',
            'vat_number' => $vatNumber,
            'country_code' => $countryCode,
            'response' => $abstractApiResult['response_data'] ?? null,
            'processed' => $abstractApiResult,
            'timestamp' => now()->toDateTimeString(),
        ];

        File::put(
            $stubsDir."/abstractapi_{$key}.json",
            json_encode($abstractApiStub, JSON_PRETTY_PRINT)
        );

        // Save API Layer stub
        $apiLayerStub = [
            'api' => 'apilayer',
            'vat_number' => $vatNumber,
            'country_code' => $countryCode,
            'response' => $apiLayerResult['response_data'] ?? null,
            'processed' => $apiLayerResult,
            'timestamp' => now()->toDateTimeString(),
        ];

        File::put(
            $stubsDir."/apilayer_{$key}.json",
            json_encode($apiLayerStub, JSON_PRETTY_PRINT)
        );

        $this->info("Stubs saved to: {$stubsDir}");
        $this->line("  - abstractapi_{$key}.json");
        $this->line("  - apilayer_{$key}.json");
    }
}
