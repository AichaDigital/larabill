# ADR-009: Tipo de dinero `FixedDecimal` (lara100 v2) en la API de modelos

> **Status**: Accepted
> **Date**: 2026-06-22
> **Relates**: adopta `aichadigital/lara100` v2.0.0. Complementa ADR-006 (UUID-first). No supersede ningún ADR. Issue: AID-237.

## Contexto

larabill representaba el dinero como entero base-100 (céntimos) vía el cast `Base100Int` de lara100, y hacía la aritmética fixed-point a mano (`(int) (qty × unit_price / 100)`). Ese patrón es frágil: la multiplicación de dos enteros base-100 exige dividir por 100 a mano, el redondeo queda implícito en un cast `(int)` que **trunca** hacia cero, y nada impide mezclar un importe con un número desnudo.

lara100 v2.0.0 introduce `FixedDecimal`: un value object decimal exacto (sobre `brick/math`), inmutable, con escala configurable y aritmética explícita (`plus/minus/multipliedBy/dividedBy/toScale` con `RoundingMode` obligatorio). Su cast `FixedDecimalCast` persiste el **entero unscaled** en la misma columna `integer` — el almacenamiento no cambia.

## Decisión

larabill adopta `FixedDecimal` como **tipo de dinero de la ruta fiscal**. Los 13 atributos monetarios de 8 modelos pasan de `Base100Int` a `FixedDecimalCast::class.':2'`:

- `Article::cost_price`, `ArticlePrice::price`, `ArticleOverride::custom_price`, `ArticleServiceStatus::effective_price`
- `InvoiceItem::{quantity, unit_price, taxable_amount, total_tax_amount, total_amount}`
- `Invoice::{taxable_amount, total_tax_amount, total_amount}`
- `Commission::rate`, `TaxCategory::default_rate`

Principio rector: `FixedDecimal` vive en memoria; se convierte a escalar **solo** en fronteras.

- **Capa persistida**: automática vía el cast (entero unscaled). Columnas y datos sin cambios.
- **Núcleo fiscal** (base imponible de línea, IVA, totales de factura, prorrateo de reembolso): aritmética exacta con `FixedDecimal` + redondeo **HalfUp** a 2 decimales (norma contable EU/España). El `SUM` SQL de líneas ya redondeadas se conserva (regla de agregación española: redondeo por línea y suma).
- **Fronteras externas** (`VerifactuAdapter` → XML AEAT, PDF, logs) y **salidas de display/ratio** (% de margen/descuento, equivalente mensual/anual, comisiones): conversión explícita (`->unscaledValue()` para céntimos, `->toFloat()` para display) conservando las firmas escalares existentes.

### Redondeo: corrección deliberada

El cálculo viejo de la base imponible `(int) (qty × unit_price / 100)` **truncaba** hacia cero. Esta versión adopta **HalfUp** (redondeo aritmético al céntimo más próximo), que es la norma EU/España. Es un **cambio de valor intencional**: la base imponible de una línea cuya `qty × unit_price` produce un medio céntimo cambia ±1 céntimo (p.ej. 1,50 × 12,33 = 18,4950 → 18,50, antes 18,49). Los importes de IVA ya redondeaban HalfUp (vía `round()` de PHP en `VatCalculationStrategy`) y no cambian.

## No-decisión (lo que esta ADR NO hace)

- **No cambia el almacenamiento.** Las columnas siguen siendo `integer` con los mismos céntimos. No hay migración de columnas ni de datos. Cumple la restricción "nada decimal en la base de datos" por diseño — es el propósito anti-float de lara100.
- **No migra los sistemas base-100 paralelos** fuera de los 8 modelos: `EuSalesThreshold`, `CompanyConfig` y los enteros `Commission::{rate_base100, min_amount_base100, max_amount_base100}`. Siguen como `int` crudo (también enteros en BD). El híbrido resultante queda documentado; su unificación es un follow-up opcional. (Actualización AID-240/242/246: `EuSalesThreshold` y `Commission` se migraron después en 2.0.0, y `VatCategory.vat_rate` + `CountryVatRate.standard_rate` en 3.0.0 — todos base-100 escala 2, **no** base-10000; la mención previa a "base-10000" para los tipos de IVA era incorrecta: `percentageToBase100` siempre fue `× 100`.)
- **No reescribe la semántica (quirky) de las comisiones.** `Commission::rate` se migra preservando su valor exacto (donde el código usaba `$this->rate` int, ahora usa `->unscaledValue()`); cualquier corrección de su lógica es trabajo aparte.

## Consecuencias

- **Breaking para consumidores**: los atributos de modelo exponen `FixedDecimal` en vez de `int`/`float`. Por eso esta versión es **1.0.0**, con guía de upgrade (`docs/upgrade-1.0-fixeddecimal.md`).
- **Exactitud fiscal**: la ruta de cálculo de factura es exacta y conforme a la norma de redondeo EU/España.
- **Coste de re-tipado**: ~90 sitios en `src/` y ~16 ficheros de test ajustados; un helper de test `cents()` (+ `fdMoney()`) reduce el churn. Cubierto por la suite (1002 tests SQLite) + integración MySQL (contrato UUID-first intacto, schema sin cambios).
- **Distinción clave para consumidores**: el acceso por query builder (`->value('price')`, `->sum(...)`, `->where(...)`, `->pluck(...)`) sigue devolviendo enteros; solo el acceso a **atributo** Eloquent (`$model->price`) devuelve `FixedDecimal`. (Nota: `Builder::value()` SÍ aplica el cast — hidrata un modelo de una columna — por lo que `getPriceFor()` convierte con `->unscaledValue()` para preservar su contrato `?float`.)

## Criterios de reapertura

Reabrir solo si: (a) un consumidor necesita más de 2 decimales en algún importe (cambiar la escala del cast en ese atributo concreto), o (b) se decide unificar los sistemas base-100/base-10000 paralelos bajo `FixedDecimal` (requiere su propio diseño de escala por columna, especialmente para los tipos de IVA en base-10000).
