#!/bin/bash

# Install Git hooks for Larabill development

echo "🔧 Installing Git hooks..."

# Create .git/hooks directory if it doesn't exist
mkdir -p .git/hooks

# Copy pre-push hook
cp .githooks/pre-push .git/hooks/pre-push
chmod +x .git/hooks/pre-push

echo "✅ Git hooks installed successfully!"
echo ""
echo "The following hooks are now active:"
echo "  • pre-push: Runs code style, PHPStan, and tests before pushing"
echo ""
echo "To bypass hooks (not recommended), use: git push --no-verify"
