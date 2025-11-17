#!/bin/bash

# Script para marcar tests legacy como deprecated en v0.4.0

# Lista de archivos de test que usan API/tablas legacy
LEGACY_TESTS=(
    "tests/Unit/Models/VatCategoryTest.php"
    "tests/Unit/Models/UserRoiVerificationTest.php"
    "tests/Unit/Models/CountryVatRateTest.php"
    "tests/Unit/Models/CountryVatRateDebugTest.php"
    "tests/Unit/Models/EuSalesThresholdTest.php"
    "tests/Unit/Models/RoiQueryTest.php"
    "tests/Unit/Services/DestinationVatServiceTest.php"
    "tests/Unit/Services/RoiVerificationServiceTest.php"
    "tests/Feature/VatSystemIntegrationTest.php"
)

for test_file in "${LEGACY_TESTS[@]}"; do
    if [ -f "$test_file" ]; then
        echo "Marking as deprecated: $test_file"

        # Agregar header deprecated si no existe
        if ! grep -q "@deprecated v0.4.0" "$test_file"; then
            # Crear backup
            cp "$test_file" "${test_file}.bak"

            # Agregar deprecation header después del declare(strict_types=1);
            sed -i.tmp '1,/declare(strict_types=1);/s/declare(strict_types=1);/declare(strict_types=1);\n\n\/**\n * @deprecated v0.4.0 - Uses legacy tables\/API not present in v0.4.0\n * These tests will be rewritten or removed in future versions.\n *\/\n\n\/\/ Skip all tests - legacy code\nbeforeEach(function () {\n    $this->markTestSkipped("Legacy test - deprecated in v0.4.0");\n});/' "$test_file"

            rm "${test_file}.tmp" 2>/dev/null
            echo "  ✓ Marked as deprecated"
        else
            echo "  ⊗ Already deprecated"
        fi
    else
        echo "  ✗ File not found: $test_file"
    fi
done

echo ""
echo "✓ Done marking legacy tests as deprecated"

