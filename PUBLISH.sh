#!/bin/bash

# ═══════════════════════════════════════════════════════════════
# Larabill v0.1.0 - Script de Publicación
# ═══════════════════════════════════════════════════════════════
#
# IMPORTANTE: Lee cada sección antes de ejecutar
# Puedes ejecutar sección por sección
#
# ═══════════════════════════════════════════════════════════════

set -e  # Detener en errores

echo "╔═══════════════════════════════════════════════════════════╗"
echo "║   LARABILL v0.1.0 - PUBLICACIÓN EN GITHUB + PACKAGIST   ║"
echo "╚═══════════════════════════════════════════════════════════╝"
echo ""

# ═══════════════════════════════════════════════════════════════
# PASO 1: VERIFICACIÓN PRE-PUBLICACIÓN
# ═══════════════════════════════════════════════════════════════

echo "📋 PASO 1: Verificación..."
echo ""

# Verificar que estamos en refactor/agnostic
CURRENT_BRANCH=$(git branch --show-current)
echo "✓ Branch actual: $CURRENT_BRANCH"

# Verificar tags
echo "✓ Tags disponibles:"
git tag -l
echo ""

# Verificar tests
echo "🧪 Ejecutando tests..."
vendor/bin/pest --compact 2>&1 | grep "Tests:"
echo ""

# Verificar archivos en raíz
echo "📁 Archivos .md en raíz:"
ls *.md
echo ""

read -p "¿Continuar con publicación? (y/n): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "❌ Publicación cancelada"
    exit 1
fi

# ═══════════════════════════════════════════════════════════════
# PASO 2: PUSH A GITHUB
# ═══════════════════════════════════════════════════════════════

echo ""
echo "📤 PASO 2: Push a GitHub..."
echo ""

# Verificar remote
if git remote | grep -q "^origin$"; then
    echo "✓ Remote 'origin' existe:"
    git remote get-url origin
else
    echo "⚠️  Remote 'origin' NO existe"
    echo "Ejecuta manualmente:"
    echo "  git remote add origin https://github.com/aichadigital/larabill.git"
    exit 1
fi

read -p "¿Hacer push del branch? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo "Pushing branch..."
    git push origin $CURRENT_BRANCH
    echo "✅ Branch pushed"
fi

read -p "¿Hacer push de los tags? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo "Pushing tags..."
    git push origin --tags --force
    echo "✅ Tags pushed"
fi

# ═══════════════════════════════════════════════════════════════
# PASO 3: PACKAGIST
# ═══════════════════════════════════════════════════════════════

echo ""
echo "📦 PASO 3: Registro en Packagist"
echo ""
echo "Ahora debes hacer manualmente:"
echo ""
echo "1. Ir a: https://packagist.org/packages/submit"
echo "2. Login con tu cuenta GitHub"
echo "3. Pegar URL: https://github.com/aichadigital/larabill"
echo "4. Click 'Check'"
echo "5. Verificar que detecta composer.json"
echo "6. Click 'Submit'"
echo ""
echo "7. Configurar Auto-Update:"
echo "   - GitHub Repo → Settings → Webhooks → Add webhook"
echo "   - Payload URL: (copiar de Packagist settings)"
echo "   - Content-type: application/json"
echo "   - Events: Just the push event"
echo "   - Active: ✓"
echo ""

read -p "Presiona ENTER cuando hayas completado el registro en Packagist..."

# ═══════════════════════════════════════════════════════════════
# PASO 4: VERIFICACIÓN POST-PUBLICACIÓN
# ═══════════════════════════════════════════════════════════════

echo ""
echo "✅ PASO 4: Verificación"
echo ""
echo "Comandos de verificación:"
echo ""
echo "# Ver package en Packagist:"
echo "open https://packagist.org/packages/aichadigital/larabill"
echo ""
echo "# Ver repositorio en GitHub:"
echo "open https://github.com/aichadigital/larabill"
echo ""
echo "# Instalar en nueva app de prueba:"
echo "composer create-project laravel/laravel test-larabill"
echo "cd test-larabill"
echo "composer require aichadigital/larabill:^0.1"
echo ""

# ═══════════════════════════════════════════════════════════════
# FINALIZACIÓN
# ═══════════════════════════════════════════════════════════════

echo "╔═══════════════════════════════════════════════════════════╗"
echo "║              ✅ PUBLICACIÓN COMPLETADA                    ║"
echo "╚═══════════════════════════════════════════════════════════╝"
echo ""
echo "📊 Package Status:"
echo "   - Tests: 522/530 passing (98.5%)"
echo "   - Mutation Score: 32.5%"
echo "   - Version: 0.1.0 (development)"
echo "   - Tags: 0.1.0, dev"
echo ""
echo "🔗 Links:"
echo "   - GitHub: https://github.com/aichadigital/larabill"
echo "   - Packagist: https://packagist.org/packages/aichadigital/larabill"
echo ""
echo "🎯 Próximos pasos:"
echo "   1. Testing en apps reales (UUID/ULID/Int)"
echo "   2. Mejorar mutation score (32.5% → 50%+)"
echo "   3. Iterar basándote en feedback"
echo ""

