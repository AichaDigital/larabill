# Fix: `InvoiceSeriesControlFactory` default `number_format` en minúsculas

**Fecha**: 2026-06-04
**Autor**: Abdelkarim Mateos
**Tipo**: bug fix (patch) — propuesta ejecutable
**Detectado desde**: consumer `castris/clientes` (sesión 2026-06-04, al construir `SeriesDemoSeeder` + corregir el mismo bug en la UI `InvoiceSeriesCreate`).

---

## Problema

`InvoiceSeriesControlFactory::definition()` define el `number_format` por defecto en **minúsculas**:

```php
// src/Database/Factories/InvoiceSeriesControlFactory.php:33
'number_format' => '{{prefix}}-{{year}}-{{number}}',
```

Pero `InvoiceNumberingService::formatNumber()` **solo sustituye claves en MAYÚSCULAS**:

```php
// src/Services/InvoiceNumberingService.php:128-130
'{{PREFIX}}' => $prefix,
'{{YEAR}}'   => (string) $fiscalYear,
'{{NUMBER}}' => str_pad((string) $number, 6, '0', STR_PAD_LEFT),
```

Consecuencia: una serie creada **vía factory** (sin override de `number_format`) genera un número de factura con los placeholders **literales**:

```
{{prefix}}-{{year}}-{{number}}   ← en vez de  FAC-2026-000001
```

El propio servicio, cuando auto-crea una serie en `createSeriesControl()`, ya usa la caja correcta (`src/Services/InvoiceNumberingService.php:168` → `'{{PREFIX}}-{{YEAR}}-{{NUMBER}}'`). La factory es el único punto del paquete que quedó en minúsculas. La columna `number_format` en la migración también defaultea a mayúsculas (`{{PREFIX}}-{{YEAR}}-{{NUMBER}}`).

## Por qué no lo cazó ningún test

- `tests/Unit/Services/InvoiceNumberingServiceTest.php` llama `generateNumber()` **sin pre-crear** la serie → el servicio auto-crea con su default **uppercase** (línea 168), no con la factory. Por eso pasa.
- `tests/Unit/Models/InvoiceSeriesControlTest.php` crea filas con `factory()` pero solo testea scopes/atributos (`is_active`, `fiscal_year`, `serie`); **nunca** pasa el `number_format` por el formateador.

Es decir: el `number_format` de la factory no se ejerce a través de `formatNumber()` en ningún test → bug invisible.

## Fix (primario, mínimo)

Alinear el default de la factory con la caja que sustituye el servicio (y con la columna y el resto del ecosistema):

```diff
--- a/src/Database/Factories/InvoiceSeriesControlFactory.php
+++ b/src/Database/Factories/InvoiceSeriesControlFactory.php
@@ -30,7 +30,7 @@ public function definition(): array
             'last_number'       => 0,
             'start_number'      => 1,
             'reset_annually'    => true,
-            'number_format'     => '{{prefix}}-{{year}}-{{number}}',
+            'number_format'     => '{{PREFIX}}-{{YEAR}}-{{NUMBER}}',
             'is_active'         => true,
```

## Test de regresión (TDD — escribir ANTES del fix, debe quedar RED)

Añadir a `tests/Unit/Services/InvoiceNumberingServiceTest.php` (o al test del modelo):

```php
it('a factory-created series formats numbers without literal placeholders', function () {
    // La factory aplica su default number_format (el caso bajo prueba).
    InvoiceSeriesControl::factory()->create([
        'prefix'      => 'FAC',
        'serie'       => InvoiceSerieType::INVOICE->value,
        'fiscal_year' => now()->year,
        'user_id'     => null,
    ]);

    $number = (string) $this->service->generateNumber('FAC', InvoiceSerieType::INVOICE->value);

    expect($number)
        ->not->toContain('{{')                 // RED con el default en minúsculas
        ->toMatch('/^FAC-\d{4}-\d{6}$/');
});
```

Verificar RED (placeholders literales) → aplicar el fix → GREEN.

## Opcional (hardening, decisión de diseño — NO imprescindible)

Hacer `formatNumber()` **case-insensitive** para que cualquier plantilla introducida a mano (p.ej. desde una UI) funcione aunque venga en minúsculas:

```php
// Antes del str_replace, normalizar las claves conocidas:
$template = preg_replace_callback(
    '/\{\{\s*(prefix|year|number|timestamp|user_id)\s*\}\}/i',
    fn ($m) => '{{'.strtoupper($m[1]).'}}',
    $template
);
```

**Recomendación**: NO incluirlo en este patch. La convención del ecosistema es **uppercase como fuente única** (columna, servicio, seeder del consumer, UI del consumer ya alineados). El case-insensitive enmascararía drift futuro y cambia el contrato. Si se quiere, va en su propia minor con su decisión documentada.

## Versionado y publicación

- Bump **patch**: `v0.8.0` → `v0.8.1` (bug fix, sin cambio de API ni de migraciones).
- Entrada CHANGELOG:

```markdown
## [0.8.1] - 2026-06-04

### Fixed

- `InvoiceSeriesControlFactory` defaulted `number_format` to lowercase
  `{{prefix}}-{{year}}-{{number}}`, but `InvoiceNumberingService::formatNumber()`
  only substitutes UPPERCASE placeholders — a factory-created series emitted
  literal `{{prefix}}...` as the invoice number. Aligned the factory default to
  `{{PREFIX}}-{{YEAR}}-{{NUMBER}}` (matching the service, the column default and
  the documented convention). Added a regression test.
```

- `composer pint` + `php vendor/bin/pest` verdes.
- `git tag v0.8.1` + push de `main` y del tag.

## Follow-up en el consumer (`castris/clientes`)

El consumer ya corrigió el mismo bug en su UI (`InvoiceSeriesCreate`, commit `4004567`) y su `SeriesDemoSeeder` usa mayúsculas. Tras publicar:

```bash
cd ~/SitesLR12/clientes
composer update aichadigital/larabill   # dev-main; resync packagist si hace falta
php artisan test
```

No requiere cambios de código en el consumer — solo recoger el paquete actualizado. Si packagist sirve metadatos viejos, forzar resync (ver `clientes/CLAUDE.md` → lección Packagist webhook).
