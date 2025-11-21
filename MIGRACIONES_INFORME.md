# 📋 INFORME DE MIGRACIONES CREADAS - Verificación Requerida

> **Fecha**: 2025-11-21  
> **Paquete**: `aichadigital/larabill`  
> **Acción**: Creadas 5 migraciones faltantes para modelos huérfanos

---

## ✅ **MIGRACIONES CREADAS (5 nuevas)**

### **1. `create_country_vat_rates_table.php.stub`**

**Propósito**: Tasas de IVA por país (estándar, reducidas, exentas)

**Campos Clave**:
```php
- country_code (string(2), unique)     # ISO 3166-1 (ES, FR, DE...)
- country_name (string(100))           # "España", "France"...
- standard_rate (integer)              # Base 100: 21.50% = 2150
- reduced_rates (json, nullable)       # {"food": 1000, "books": 400}
- exempt_categories (json, nullable)   # ["education", "healthcare"]
- data_source (string, default '')     # NO NULL per boot()
- is_active (boolean, default true)
```

**⚠️ VERIFICAR**:
1. ✅ **`standard_rate` es `integer`** (base 100) - según modelo línea 57
2. ✅ **`data_source` default `''`** - modelo boot() línea 84-86 lo requiere
3. ✅ **`reduced_rates` y `exempt_categories` son JSON** - modelo línea 58-59
4. ⚠️ **`country_code` único** - modelo usa `findByCountry()` línea 155

**Índices**:
- `country_code` (unique + index)
- `is_active`
- `standard_rate`
- `last_updated`

---

### **2. `create_vat_categories_table.php.stub`**

**Propósito**: Categorías de IVA por producto/servicio y país

**Campos Clave**:
```php
- name (string(100))                   # "food", "books", "electronics"
- country_code (string(2))             # ISO 3166-1
- vat_rate (integer)                   # Base 100: 21.50% = 2150
- category_type (string(50))           # standard, reduced, super_reduced, exempt
- applies_to_products (boolean)        # true
- applies_to_services (boolean)        # true
- parent_category_id (FK self)         # Hierarchy support
```

**⚠️ VERIFICAR**:
1. ✅ **`vat_rate` es `integer`** (base 100) - modelo línea 78
2. ✅ **`category_type` valores** - modelo líneas 34-49 define constantes
3. ✅ **`parent_category_id` self-referencing FK** - modelo línea 26
4. ⚠️ **UNIQUE (`name`, `country_code`)** - para evitar duplicados por país

**Índices**:
- `(name, country_code)` (unique)
- `category_type`
- `is_active`
- `vat_rate`
- `sort_order`

---

### **3. `create_eu_sales_thresholds_table.php.stub`**

**Propósito**: Umbrales de ventas EU por usuario/año fiscal

**Campos Clave**:
```php
- user_id (binary(16))                 # UUID v7 binary FK
- fiscal_year (integer)                # 2024, 2025...
- total_amount (decimal(15,2))         # Monetary amount
- threshold_amount (decimal(15,2))     # Default €10,000.00
- threshold_exceeded (boolean)         # Alertas
- breakdown_by_country (json)          # {"ES": 5000.00, "FR": 3000.00}
```

**⚠️ VERIFICAR - CRÍTICO**:
1. ⚠️ **`user_id` es `binary(16)`** - ASUME UUID v7 binary (ver Larafactu)
   - Si `users.id` es diferente (int, uuid string, ulid), MODIFICAR
   - Modelo línea 17: `@property string $user_id` (ambiguo)
   
2. ✅ **`total_amount`/`threshold_amount` son `decimal`** - modelo línea 54-55 usa `float` pero en DB debe ser `decimal` para precisión

3. ✅ **UNIQUE (`user_id`, `fiscal_year`)** - un registro por usuario/año

**Índices**:
- `(user_id, fiscal_year)` (unique)
- `threshold_exceeded`
- `fiscal_year`
- `last_updated`

---

### **4. `create_roi_queries_table.php.stub`**

**Propósito**: Log de consultas ROI para auditoría legal (7 años)

**Campos Clave**:
```php
- user_id (binary(16))                 # UUID v7 binary FK
- vat_code (string(50))                # NIF/VAT del consultado
- country_code (string(2))             # País del VAT
- query_type (string(20))              # "api", "cache"
- response_data (json, nullable)       # Respuesta completa API
- queried_at (timestamp)               # Cuándo se hizo
- legal_retention_until (timestamp)    # +7 años (modelo línea 74)
```

**⚠️ VERIFICAR - CRÍTICO**:
1. ⚠️ **`user_id` es `binary(16)`** - ASUME UUID v7 binary
   - Modelo línea 18: `@property string $user_id` (ambiguo)
   
2. ✅ **`legal_retention_until` obligatorio** - modelo boot() línea 72-75
3. ✅ **`query_type` default 'api'** - modelo línea 41

**Índices**:
- `(user_id, vat_code)`
- `country_code`
- `queried_at`
- `legal_retention_until` (para cleanup jobs)

---

### **5. `create_user_roi_verifications_table.php.stub`**

**Propósito**: Cache de verificaciones ROI por usuario

**Campos Clave**:
```php
- user_id (binary(16))                 # UUID v7 binary FK
- vat_code (string(50))                # NIF/VAT del usuario
- country_code (string(2))             # País del VAT
- is_roi (boolean, default false)      # ¿Es operador intracomunitario?
- cache_hit (boolean)                  # ¿De cache?
- last_check (timestamp, nullable)     # Última verificación
- expired_at (timestamp, nullable)     # Caducidad cache
- response_data (json, nullable)       # Respuesta API completa
```

**⚠️ VERIFICAR - CRÍTICO**:
1. ⚠️ **`user_id` es `binary(16)`** - ASUME UUID v7 binary
   - Modelo línea 17: `@property string|int $user_id` (AMBIGUO - acepta int o string)
   
2. ✅ **UNIQUE (`user_id`, `vat_code`)** - una verificación por usuario+vat
3. ✅ **`expired_at` para cache invalidation** - modelo línea 27

**Índices**:
- `(user_id, vat_code)` (unique)
- `country_code`
- `is_roi`
- `last_check`
- `expired_at`

---

## 🔴 **VERIFICACIÓN CRÍTICA REQUERIDA**

### **PROBLEMA PRINCIPAL: `user_id` Type Mismatch**

**3 tablas tienen FK a `users`:**
1. `eu_sales_thresholds.user_id`
2. `roi_queries.user_id`
3. `user_roi_verifications.user_id`

**Decisión tomada en migraciones:**
```php
$table->binary('user_id', 16); // ASUME UUID v7 binary
$table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
```

**¿POR QUÉ?**
- Larafactu usa `users.id` como `binary(16)` UUID v7
- Documentación del proyecto lo confirma

**⚠️ DEBES VERIFICAR EN LARABILL:**

```bash
# En el paquete Larabill
cd /Users/abkrim/development/packages/aichadigital/larabill

# ¿Qué tipo de user_id espera el código?
grep -r "user_id.*type" config/larabill.php
```

**Si `user_id` NO es UUID binary:**
- Modificar las 3 migraciones
- Opciones:
  ```php
  // Int (auto-increment)
  $table->unsignedBigInteger('user_id');
  
  // UUID string (36 chars)
  $table->uuid('user_id');
  
  // ULID (26 chars)
  $table->char('user_id', 26);
  ```

---

## 📊 **ORDEN DE PUBLICACIÓN SUGERIDO**

**Las 5 migraciones son independientes**, PERO:

1. `country_vat_rates` - independiente
2. `vat_categories` - independiente (self-referencing OK)
3. `eu_sales_thresholds` - depende de `users` (ya existe)
4. `roi_queries` - depende de `users` (ya existe)
5. `user_roi_verifications` - depende de `users` (ya existe)

**Sugerencia de timestamps** (para `LarabillInstallCommand`):
```php
'2024_12_01_0020_create_country_vat_rates_table.php',
'2024_12_01_0021_create_vat_categories_table.php',
'2024_12_01_0022_create_eu_sales_thresholds_table.php',
'2024_12_01_0023_create_roi_queries_table.php',
'2024_12_01_0024_create_user_roi_verifications_table.php',
```

---

## ✅ **CHECKLIST DE VERIFICACIÓN**

### **Antes de Integrar:**
- [ ] **1. Verificar tipo de `user_id` en config/larabill.php**
- [ ] **2. Si no es UUID binary, modificar las 3 migraciones (3, 4, 5)**
- [ ] **3. Revisar que `country_code` sea ISO 3166-1 alpha-2** (2 chars)
- [ ] **4. Confirmar que base-100 es correcto para rates** (modelo lo usa)
- [ ] **5. Verificar que JSON es correcto para arrays** (MySQL 5.7+)

### **Lógica de Negocio:**
- [ ] **6. ¿`data_source` en `country_vat_rates` puede ser vacío?** (default '')
- [ ] **7. ¿`threshold_amount` default €10,000 es correcto?** (EU OSS threshold)
- [ ] **8. ¿Retención legal 7 años es correcto?** (España: SÍ, Art. 29.2.e LGT)
- [ ] **9. ¿`category_type` valores son completos?** (standard, reduced, super_reduced, exempt)
- [ ] **10. ¿Self-referencing en `vat_categories` es necesario?** (jerarquía de categorías)

---

## 🚀 **SIGUIENTE PASO**

**Una vez verificado:**

```bash
# Actualizar LarabillInstallCommand
# Añadir las 5 nuevas migraciones al array de publicación
# Ver línea ~80 de LarabillInstallCommand.php
```

**Código a añadir:**
```php
'country_vat_rates'        => '2024_12_01_0020',
'vat_categories'           => '2024_12_01_0021',
'eu_sales_thresholds'      => '2024_12_01_0022',
'roi_queries'              => '2024_12_01_0023',
'user_roi_verifications'   => '2024_12_01_0024',
```

---

## 📝 **RESUMEN**

✅ **5 migraciones creadas**  
⚠️ **1 verificación crítica**: Tipo de `user_id`  
✅ **Base 100 implementado correctamente**  
✅ **JSON para arrays complejos**  
✅ **Índices optimizados para queries comunes**  
⚠️ **Retención legal 7 años** (validar por jurisdicción)

---

**¿Listo para continuar?** Verifica el tipo de `user_id` y te actualizo las migraciones si es necesario.

