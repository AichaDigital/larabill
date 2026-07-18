# ADR-011: La frontera del PDF — contrato de contenido fiscal (larabill) vs presentación (consumidor)

> **Status**: Accepted
> **Date**: 2026-07-18
> **Relates**: cierra AID-502 (diseño de la frontera del PDF). Se apoya en AID-508 (el render por defecto conforme), AID-535 (frontera = único logger/traductor), AID-537 (QR 35mm + rechazo de referencias externas), AID-546/AID-328 (identidad congelada = snapshot persistido), AID-442/AID-444 (visibilidad de «Fecha de operación» en capa de datos). No supersede ningún ADR.

## Contexto

larabill posee el dominio de la factura; el consumidor posee su presentación. Hasta AID-508 esa frontera no existía: el PDF por defecto ni siquiera era una factura (número vacío, líneas inventadas, importes a 0,00 €). AID-508 entregó el render conforme; este ADR decide **cómo se garantiza que lo siga siendo** cuando el consumidor ejerce las costuras de presentación que el paquete ya ofrece (vistas publicables, registro `invoice_templates`, settings por empresa/cliente).

Cuatro fricciones concretas, heredadas del diseño original:

- **Vocabulario roto entre el dominio fiscal y el registro de plantillas.** `Invoice::getInvoiceType()` devuelve el label de la serie fiscal (`invoice`, `simplified`, `rectificative`, `proforma`), mientras `invoice_templates.type` habla el vocabulario de presentación (`fiscal`, `proforma`, `reverse-charge`, `exempt`). Consecuencia: para facturas fiscales el registro **nunca resolvía** — `template_name` se ignoraba con un warning y los settings sembrados no aplicaban. AID-508 desbloqueó de forma mínima solo el camino de notes/payment-terms (`convertToTemplateInvoiceType()`, un hop string→enum cuyos brazos reverse-charge/exempt eran inalcanzables); la decisión de fondo quedó para aquí.
- **Restilado sin guardarraíl.** Un consumidor que publica y restila un blade puede omitir en silencio un campo fiscal obligatorio (número, fecha de expedición, NIF, desglose de IVA…). No había contrato «restila sin romper la validez fiscal».
- **Entrada del consumidor sobre superficie `@internal`.** `Invoice::generatePDF()` se apoya en `PDFService` (`@internal`, AID-413) y su shape de resultado no estaba prometido.
- **Motor acoplado por omisión.** `PDFConnectorInterface` existe como costura de motor, pero sin decisión registrada sobre su futuro (¿spatie/laravel-pdf? ¿browsershot?).

## Decisión

### D1 — Vocabulario canónico: `TemplateInvoiceType` es el tipo de plantilla; la serie fiscal nunca habla con el registro

- **Dos vocabularios, dos dueños.** `InvoiceSerieType` (`serie`) es vocabulario **fiscal** (AEAT, numeración, AID-307). `TemplateInvoiceType` (`fiscal`/`proforma`/`reverse-charge`/`exempt`) es vocabulario de **presentación**: qué familia de plantilla corresponde al documento. No son proyecciones 1:1 — `simplified` y `rectificative` son series fiscales distintas que se presentan como `fiscal`; `reverse-charge` y `exempt` no son series, son condiciones fiscales del documento.
- **Derivación única:** `DomPDFService::resolveTemplateType(Invoice): TemplateInvoiceType` — proforma → `PROFORMA`; `is_roi_taxed` → `REVERSE_CHARGE`; exención VAT congelada en el `customer_snapshot` → `EXEMPT`; resto → `FISCAL`. Consolida la lógica antes dispersa en `getTemplateForInvoice()`; el orden de precedencia (ROI antes que exención) se conserva.
- **Clave de registro:** `TemplateInvoiceType::registryKey(): string` devuelve el literal que persiste `invoice_templates.type` (`fiscal`, `proforma`, `reverse-charge`, `exempt`). TODA consulta al registro (`InvoiceTemplate::getByName()`, `getDefaultForType()`) y a settings (`CompanyTemplateSettings`) pasa por `resolveTemplateType()`; el hop stringly-typed se retiró.
- **El registro gobierna de verdad:** la cadena de resolución de vista es `template_name` → fila default del tipo en el registro → fallback al blade del paquete (`defaultViewFor()`, para BDs sin sembrar). Hasta ahora la fila default solo aportaba settings, nunca la vista.
- `Invoice::getInvoiceType()` se conserva con su semántica real (label de la serie; lo usa `pdfFilename()`), pero deja de usarse como tipo de plantilla.

**Cambio de comportamiento (fix):** el registro resuelve por primera vez para facturas fiscales — `template_name` se honra y los settings sembrados aplican. Además, las lookups de notes/payment-terms/settings de facturas reverse-charge y exempt consultan su propio tipo (los brazos `REVERSE_CHARGE`/`EXEMPT` del vocabulario de settings eran inalcanzables: todo lo no-proforma resolvía como `FISCAL`).

### D2a — Garantía de restilado seguro: validación post-render del contenido fiscal

- **Elegido D2a (validar el output) frente a D2b (partials no-pisables).** Un partial no garantiza nada: la plantilla del consumidor que no lo incluye renderiza sin él en silencio, y exigir su inclusión requiere… validar el output. La validación comprueba la verdad material (lo que el PDF dice), no el mecanismo; y no acopla la libertad de layout del consumidor a la estructura de bloques de larabill.
- **Mecanismo:** tras `renderTemplate()` y antes de dompdf, `FiscalContentValidator` (`@internal`) comprueba que cada dato fiscal obligatorio **no vacío** que la capa de datos entregó a la plantilla aparece en el HTML renderizado (normalizado: sin tags, entidades decodificadas, whitespace colapsado — needle y haystack por igual, así los valores partidos por markup inline siguen casando). Si falta alguno, lanza `FiscalContentMissingException` con la lista completa (la excepción es el contrato público, patrón `FiscalIntegrityChecker`) → la frontera la traduce a `['success' => false]` con su única línea de log (AID-535).
- **Alcance:** solo series fiscales (`serie->isFiscal()`); las proformas quedan fuera (no son documento fiscal). El set obligatorio deriva del RD 1619/2012 arts. 6/7: número (`fiscal_number`), fecha de expedición (`invoice_date`, formatos tolerados `d/m/Y`, `Y-m-d`, `d-m-Y`, `d.m.Y` — fuera de ese set es una reescritura, no un restilado), «Fecha de operación» **verbatim** cuando la capa de datos la computó, emisor (nombre + NIF del snapshot), receptor (nombre + NIF) **solo** cuando `serie->requiresFullCustomerData()` (las simplificadas quedan exentas, art. 7), por línea la descripción y el precio unitario sin impuesto (los mandatos nominados del art. 6.1.f), desglose por tipo (tasa, base y cuota de **cada** fila, art. 6.1.g/h) y totales (base imponible y total).
- **El contrato que codifica:** la presentación puede restilar todo; no puede **omitir ni reescribir** los valores fiscales. Los importes se imprimen como los entrega la capa de datos (strings exactos, AID-508) — si mañana una capa de localización los reformatea, lo hará en la capa de datos y el validador seguirá comprobando lo entregado.
- **Frontera con la completitud:** el validador garantiza **fidelidad de render** (lo que hay en los datos aparece en el papel), no **completitud de emisión** (que el dato exista es trabajo de la emisión y sus guards). Un dato obligatorio vacío en los datos no es un fallo del render y se salta.
- **Config:** `larabill.pdf.validate_fiscal_content` (env `LARABILL_PDF_VALIDATE_FISCAL_CONTENT`), default **true**. Escape honesto para quien asuma el riesgo, no un opt-in.

**Cambio de comportamiento:** una plantilla override que haya tirado un campo obligatorio pasa de producir un PDF no conforme en silencio a fallar explícito (filosofía fail-loud, precedente AID-508/AID-553).

### D3 — Contrato estable del consumidor: `Invoice::generatePDF()` es `@api` con shape prometido

- `Invoice::generatePDF()` se promueve a `@api` a nivel de método (lista amber 7→8, gate `SurfaceTaxonomyTest`; snapshot de contrato regenerado por el cauce sancionado). El shape del resultado queda documentado como contrato en el docblock: éxito → `success`, `pdf_path`, `pdf_url` (siempre `null`, AID-508), `qr_data`, `connector_used`, `generated_at`; fallo → `success=false`, `error`, `connector_used`, `generated_at`.
- `PDFService`/`DomPDFService`/`DefaultPDFConnector`/`FiscalContentValidator` siguen `@internal`: la superficie pública del subsistema es `Invoice::generatePDF()` + `PDFConnectorInterface` + las excepciones tipadas (`MissingFiscalVerificationQrException`, `FiscalContentMissingException`).
- Se mantiene array como tipo de retorno: migrar a DTO sería breaking sin imperativo (`STABILITY.md`).

### D4 — Motor: no-action sobre connectors, decisión registrada

- `PDFConnectorInterface` se mantiene como la costura de motor. No se añade ningún connector nuevo ni se cambia el default (dompdf).
- **spatie/laravel-pdf (Browsershot) evaluado y aplazado:** aportaría CSS moderno a cambio de arrastrar Chromium headless + Node en prod/CI para un documento fiscal deliberadamente simple. Se reabrirá solo con caso de consumidor concreto que dompdf no cubra (p.ej. requisito tipográfico/layout imposible en dompdf), nunca por gusto estético (`STABILITY.md`).

## No-objetivos (deliberados)

- **Localización de importes y fechas** (formato español `1.234,56 €`): fuera de alcance; los strings exactos de AID-508 se mantienen. Ticket propio cuando haya requisito de consumidor — y vivirá en la capa de datos, nunca en los blades, precisamente para que el validador siga funcionando.
- **Contenido específico de rectificativas** (referencia a la factura rectificada, art. 15 RD 1619/2012): el dato no existe estructurado en el modelo; fuera de alcance.
- **Entrega/autorización del PDF:** del consumidor, vía controller autorizado (AID-508 retiró `getPDFUrl()`).

## Consecuencias

**Positivas:**

- El registro de plantillas funciona para el 100% de los tipos por primera vez; el vocabulario tiene dueño y derivación única.
- Un restilado del consumidor no puede degradar la validez fiscal del documento en silencio: o es conforme, o falla explícito.
- El consumidor construye sobre `@api` prometido, no sobre un detalle interno.

**Negativas / coste:**

- Instalaciones con overrides no conformes verán fallos explícitos al actualizar (mitigado: config off + CHANGELOG en negrita).
- El validador añade un pase de normalización de HTML por render (coste marginal frente a dompdf).

## Criterios de reapertura

- **D2b (partials/markers):** solo si aparece un caso real donde la validación por contenido produzca falsos positivos irresolubles (p.ej. localización legítima del consumidor que reescribe valores) — y entonces como complemento, no sustituto.
- **Connector alternativo:** caso de consumidor concreto documentado que dompdf no pueda cubrir.
- **DTO de resultado:** solo en un major con imperativo cualificado (`STABILITY.md`).
