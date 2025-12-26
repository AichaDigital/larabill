# ADR-003: Unificación de Users y Customers

> **Status**: Accepted
> **Date**: 2025-12-08
> **Supersedes**: Arquitectura anterior con Customer/CustomerFiscalData separados

## Contexto

El sistema de facturación tiene tres actores:

1. **Emisor**: La empresa que emite facturas (tenedor del software)
2. **Receptores directos**: Usuarios del sistema a los que se factura
3. **Receptores delegados**: Clientes de los usuarios, facturados por delegación

La arquitectura anterior separaba estos conceptos en múltiples tablas:

```
users                    → Usuarios del sistema
customers                → Clientes de los usuarios
customer_fiscal_data     → Datos fiscales de receptores (vinculado a user_id)
company_fiscal_configs   → Datos fiscales del emisor
```

### Problemas detectados

1. **Duplicidad conceptual**: `customers` y `users` representan lo mismo (entidades facturables)
2. **FK confusa**: `customer_fiscal_data.user_id` mezcla conceptos
3. **Policies duplicadas**: Necesidad de UserPolicy + CustomerPolicy
4. **Gates complejos**: Cruzar tablas para permisos
5. **Desacople documentación/código**: Inconsistencias entre docs y realidad

## Decisión

Unificar todos los receptores de facturas bajo el modelo `User` con relación self-referencing.

### Nuevo modelo

```
┌─────────────────────────────────────────────────────────────────┐
│  users                                                          │
│  ══════                                                         │
│  - id (UUID v7 string)                                          │
│  - parent_user_id (nullable) → FK self-reference                │
│  - relationship_type (PHP Enum → unsignedTinyInteger)           │
│  - name, email, ...                                             │
│                                                                 │
│  parent_user_id = NULL   → Cliente directo de la Empresa        │
│  parent_user_id = X      → Cliente del User X (delegado)        │
└─────────────────────────────────────────────────────────────────┘
                        │
                        │ 1:N
                        ▼
┌─────────────────────────────────────────────────────────────────┐
│  user_tax_profiles (histórico fiscal unificado)                 │
│  ══════════════════════════════════════════════                 │
│  - id                                                           │
│  - user_id → FK users.id                                        │
│  - fiscal_name, tax_id, address, city, country_code...          │
│  - is_company, is_eu_vat_registered, is_exempt_vat              │
│  - valid_from / valid_until (temporalidad)                      │
│  - is_active                                                    │
└─────────────────────────────────────────────────────────────────┘
```

### PHP Enum para tipo de relación

```php
<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

enum UserRelationshipType: int
{
    case DIRECT = 0;      // Cliente directo de la Empresa
    case DELEGATED = 1;   // Cliente de un User (facturación delegada)

    public function label(): string
    {
        return match ($this) {
            self::DIRECT => __('larabill::enums.user_relationship.direct'),
            self::DELEGATED => __('larabill::enums.user_relationship.delegated'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DIRECT => 'success',
            self::DELEGATED => 'info',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DIRECT => 'heroicon-o-user',
            self::DELEGATED => 'heroicon-o-user-group',
        };
    }
}
```

### Relaciones en el modelo User

```php
// App\Models\User o modelo configurable

public function parent(): BelongsTo
{
    return $this->belongsTo(static::class, 'parent_user_id');
}

public function children(): HasMany
{
    return $this->hasMany(static::class, 'parent_user_id');
}

public function taxProfiles(): HasMany
{
    return $this->hasMany(UserTaxProfile::class);
}

public function activeTaxProfile(): HasOne
{
    return $this->hasOne(UserTaxProfile::class)
        ->where('is_active', true)
        ->whereNull('valid_until');
}

public function isDirect(): bool
{
    return $this->parent_user_id === null;
}

public function isDelegated(): bool
{
    return $this->parent_user_id !== null;
}
```

## Tablas a eliminar

| Tabla | Razón |
|-------|-------|
| `customers` | Unificado en `users` con `parent_user_id` |
| `customer_fiscal_data` | Renombrado a `user_tax_profiles` |

## Tablas a modificar

| Tabla | Cambio |
|-------|--------|
| `users` | Añadir `parent_user_id`, `relationship_type` |
| `invoices` | Mantener `user_id` (propietario), eliminar `customer_id` |

## Tabla nueva

| Tabla | Propósito |
|-------|-----------|
| `user_tax_profiles` | Histórico fiscal de cualquier User (directo o delegado) |

## Flujo de facturación

```
1. User Juan (DIRECT) solicita factura para su cliente ABC

2. ABC es un User con:
   - parent_user_id = Juan.id
   - relationship_type = DELEGATED

3. La factura se crea:
   - user_id = Juan (propietario/solicitante)
   - company_fiscal_config_id = Empresa emisora
   - user_tax_profile_id = Perfil fiscal activo de ABC

4. Policies simplifican:
   - Juan puede ver/gestionar a ABC (es su hijo)
   - ABC no puede ver a Juan ni otros hijos de Juan
```

## Consecuencias

### Positivas

- **Un solo modelo** para todos los receptores
- **Policies unificadas**: Una sola UserPolicy
- **Gates simples**: `$user->parent_user_id === $owner->id`
- **UI Admin**: Un solo Resource con filtros por `relationship_type`
- **Histórico fiscal unificado** en `user_tax_profiles`

### Negativas

- **Migración de datos** desde estructura anterior
- **Self-relations en UI** requieren cuidado (probado en POC, funciona)

### Neutras

- **company_fiscal_configs** permanece igual (emisor único)
- **Snapshots en invoices** mantienen inmutabilidad

## Validación

- POC probado con 10,000 usuarios
- Self-relations funcionan correctamente
- PHP Enums con métodos genéricos para UI (label, color, icon)

## Referencias

- ADR-001: Arquitectura fiscal (CompanyFiscalConfig + temporal validity)
- ADR-002: UUID v7 string (eliminación de uuid_binary)
