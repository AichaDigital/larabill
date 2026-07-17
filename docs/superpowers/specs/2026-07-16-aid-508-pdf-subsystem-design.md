# Diseño AID-508: el subsistema PDF dice la verdad o no produce documento

> **Status**: Approved (pendiente de plan de implementación)
> **Date**: 2026-07-16
> **Issue**: AID-508 (Urgent). Bloquea AID-501 D2b.
> **Relates**: AID-502 (frontera contenido/presentación — recibe el punto del vocabulario), AID-413 (taxonomía `@api`/`@internal`), AID-139 (camino QR con registro; precedente del antipatrón «verde sobre ficción»), AID-390 (precedente del patrón de adopción a medias).
> **Versión objetivo**: `v6.4.0` (minor, preliminar — se confirma con el diff delante). Cero migraciones.

## 1. Problema

`DomPDFService` nunca se terminó de escribir y se cableó a producción igualmente. El PDF que produce no es una factura: número vacío, una línea inventada («Servicio 1»), importes a `0,00 €` sobre una factura de 121,00 €, emisor de fantasía y un QR que no es un QR. La evidencia completa está en el ticket.

La causa raíz no son los stubs: es que **el servicio está construido para no fallar nunca**. Once guardas y `try/catch` convierten cualquier error en un valor de relleno, de modo que un servicio vacío no puede avisar de que está vacío. Por eso sobrevivió meses, y por eso v6.2.0 (AID-439) y v6.3.0 (AID-442) tocaron `prepareTemplateData()` —tres líneas por encima de los stubs— sin ver el `TOTAL: 0,00 €` de su propia salida.

**Gate de producción resuelto: sin incidente.** No hay consumidor en producción; 0 dependents en Packagist. El defecto es visible en el primer PDF, luego no ha podido causar daño silencioso a terceros.

## 2. Decisiones tomadas

- **D1 — Cablear y arrancar el blindaje** (opción B). Se conectan los stubs a los modelos reales **y** se retira el mecanismo de ocultación. No se rediseña la estructura connector/servicio/plantilla ni se reescribe `DomPDFService`.
- **D2 — El QR es efecto del registro fiscal, no un adorno del documento** (opción 4b). Larabill no genera ni reconstruye QR: solo consume `fiscal_verification_qr`. El generador local fake se borra sin sustituto, porque nunca fue un fake requerido — es una implementación provisional abandonada. Un fake, si algún día hace falta, pertenece a lara-verifactu mediante `QrGeneratorContract`.
- **D3 — El consumidor declara el contrato del documento**, no su obligación jurídica: `pdf.require_fiscal_verification_qr`, default `false`.
- **D4 — `PDFConnectorInterface` se conserva** (opción B del connector). Es `@api` (`docs/api-surface.md:24` y `:40`); retirarla exigiría deprecación y un major completo (`STABILITY.md` regla 3), y v7 está bloqueado por la constitución de la umbrella. `DefaultPDFConnector::generateQR()` pasa a lanzar `LogicException` — la clase es `@internal` y puede cambiar.
- **D5 — El `template_name` que no resuelve emite `Log::warning`**, no excepción: es una preferencia de presentación, no un bloqueo fiscal.
- **D6 — Un solo PR.** Cableado, desblindaje y pruebas son una sola garantía observable: o produce un documento íntegro o devuelve un fracaso explícito. Partirlo crearía estados intermedios deliberadamente incoherentes.

## 3. Arquitectura de errores

La regla que ordena todo el PR: **una excepción al obtener un dato no equivale a que ese dato no exista.**

- **Las capas internas propagan.** Distinguen ausencia de fallo y dejan subir las excepciones.
- **`PDFService::generatePDF()` es la frontera** — no propaga: registra el fallo y lo traduce al contrato de error `['success' => false, 'error' => ...]`. Quienes propagan son las capas inferiores hasta ella.
- **El consumidor respeta ambas superficies:** el array de fracaso y cualquier `Throwable` que escape. Verificado: `InvoicePdfDownloadController` de Castris (único consumidor real) mira `$result['success']`, lee `$result['error']` y envuelve en `catch (\Throwable) { report($e); }`.

### 3.1 Clasificación de los ocho `catch` de `DomPDFService`

| Línea | Método | Qué traga hoy | Destino |
|---|---|---|---|
| 100 | `generatePDF()` | Traduce la excepción a `['success' => false]` **antes** de llegar a la frontera | Eliminar el `catch`; propagar |
| 202 | `getTemplateForInvoice()` | Excepción de BD al buscar plantilla → default | Propagar |
| 479 | `getInvoiceNotes()` | Excepción de BD → `null` | Propagar |
| 510 | `getPaymentTerms()` | Excepción de BD → `null` | Propagar |
| 545 | `getTemplateSettings()` | Excepción de BD → `[]` | Propagar |
| 595 | `getConfigValue()` | Excepción de `config()` → default | Borrar el método |
| 618 | `renderTemplate()` | — (registra y relanza) | Conservar (AID-139) |
| 672 | `isProductionEnvironment()` | Excepción de `app()->environment()` → `false` | Borrar el método |

**El `catch` de `:100` es el más dañino de los ocho** y por eso encabeza la tabla: no enmascara un dato, **destruye la evidencia**. Se queda con `getMessage()` y tira la clase, el stack trace y el `previous`. Y la frontera completa la pirueta: recibe el array, mira `success` y **reconstruye una `RuntimeException` genérica a partir del string** (`PDFService:118-120`). El recorrido real es excepción → array → excepción → array, con dos traducciones que pierden el original: el `Log::error` con contexto que `renderTemplate()` emite antes de relanzar (AID-139) documenta una excepción que muere doce líneas después. **Consecuencia a ejecutar en el mismo PR:** una vez `generatePDF()` propaga, el guard `if (! ($pdfResult['success'] ?? false)) throw ...` de `PDFService:118-120` queda inalcanzable — se retira con él, porque un guard que no puede dispararse es la misma clase de teatro que estamos borrando.

**La ausencia legítima nunca pasa por el `catch`.** `InvoiceTemplate::getByName()` devuelve `null` cuando no hay plantilla con ese nombre; `CompanyTemplateSettings::getDefaultNotes()` devuelve `null` cuando no hay notas configuradas. Ninguna lanza. Ese caso ya se resuelve con el `if ($template)` o el `?? null` de tres líneas más abajo y **se queda como está**. Lo único que captura el `catch` es una excepción: BD caída, tabla ausente, columna inexistente. Eso no es «no hay», es «no lo sé» — y un documento fiscal que no sabe algo no se imprime callando.

Los dos «teatro» desaparecen en lugar de propagar. `isProductionEnvironment()` es además cómplice del defecto del path: es quien bifurca entre `sys_get_temp_dir()` y `storage_path('app/invoices/')`.

### 3.2 El blindaje que se retira

Un paquete Laravel no envuelve APIs garantizadas del framework ni dependencias declaradas. Si faltan DomPDF, Eloquent o el contenedor, la instalación está rota y debe manifestarse.

- `class_exists('Dompdf\Dompdf')` en `initializeDomPDF()` + el mock anónimo que devuelve `'mock-pdf-content'` — dompdf es dependencia declarada (`^3.1`).
- `class_exists('\AichaDigital\Larabill\Models\InvoiceTemplate')` y `...\CompanyTemplateSettings` — clases del propio paquete.
- `app()->bound('db')`, `function_exists('storage_path')`, `function_exists('url')`, `function_exists('config')`.
- `generateMockHTML()` — fabrica `TEST-001` / `100.00` cuando no hay capa de vistas. Es el camino que AID-139 dejó vivo al endurecer el `catch` de `renderTemplate()`.

### 3.3 La frontera: tres piezas, tres destinos

`PDFService:145-165` no es una pieza:

- **`fallback_to_local`** (`:155-158`): ante cualquier excepción reintentaba con el connector del QR fabricado y devolvía `success: true`. Falseaba el resultado y ocultaba la causa original. **Se borra**, junto con su clave de config y cualquier referencia huérfana.
- **`Log::error` comentado** (`:146-153`, «disabled for testing»): **se restaura** como código vivo. La frontera **registra la excepción antes de traducirla** al contrato de error: es lo que hace que la traducción no pierda la causa.
- **`return ['success' => false, ...]`** (`:160-165`): **se conserva**. Es el contrato de error del servicio.

## 4. El QR

### 4.1 Hechos verificados

- **larabill no le pide el QR a lara-verifactu.** `fiscalVerificationQrResult()` lee `$invoice->fiscal_verification_qr`, columna que puebla `SyncLarabillInvoiceVerification` al recibir el `InvoiceRegisteredEvent`. El acoplamiento es por evento: lara-verifactu es opcional de verdad.
- **lara-verifactu no obtiene el QR de AEAT: lo construye.** `QrGenerator::getValidationUrl()` compone la URL de cotejo con exactamente cuatro parámetros —`nif`, `numserie`, `fecha` (dd-mm-yyyy), `importe`— y la renderiza con BaconQrCode. **La aprobación de AEAT no interviene**: el QR es efecto del registro de facturación local (con su huella), no de la respuesta remota. Sin registro no hay QR — y esa es la única condición, junto a la clase documental (§4.2).
- **La columna admite tres formatos:** el listener guarda `getQrSvg() ?? getQrPng() ?? getQrUrl()`. Una URL sola no es renderizable (§4.4.1) y larabill ya no genera QR: es dato incompleto.

### 4.2 Los ejes, y cuál gobierna el QR

**El concepto «factura expedida» no existe en este diseño, y `InvoiceStatus` no participa en ninguna decisión sobre el QR.** Usar `! isDraft()` como sinónimo de expedida habría metido un error de dominio: confunde el eje de entrega y cobro con el de emisión fiscal.

| Eje | Ejemplos | ¿Decide el QR? |
|---|---|---|
| Clase documental | Proforma, factura, simplificada, rectificativa | **Sí** |
| Emisión fiscal | Sin registro, registro generado | **Sí** |
| Entrega | No enviada, enviada, notificada | No |
| Cobro | Pendiente, pagada, vencida | No |
| Envío fiscal | Pendiente, remitido, aceptado, rechazado | No para generarlo; sí para seguimiento |

Fundamento: la emisión definitiva ocurre cuando **se genera el registro de facturación y se incorpora el QR** — no cuando se envía el PDF al cliente ni cuando AEAT acepta el registro. Borradores y proformas no llevan QR tributario. Fuente: FAQ oficial de la AEAT (https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/preguntas-frecuentes/cuestiones-generales-conceptos-definiciones.html?faqId=616d77fe52572910VgnVCM100000dc381e0aRCRD).

Una proforma enviada sigue siendo proforma. Una factura pagada no adquiere QR por estar pagada: el pago puede provocar que el sistema cree la factura definitiva, pero son dos hechos distintos (los pagos anticipados pueden generar obligación de expedir factura — RD 1619/2012 art. 2, https://www.boe.es/eli/es/rd/2012/11/30/1619 — pero esa consecuencia pertenece al flujo de negocio, no al PDF).

### 4.3 Reglas

- **Proforma, en cualquier estado → nunca QR.**
- **Config estricta desactivada** + factura fiscal sin resultado coherente → sale **sin QR**.
- **Config estricta activada** + factura fiscal → exige registro fiscal coherente **y** QR renderizable (§4.4.1); si falta cualquiera de los dos, **fracaso explícito**.
- **El estado de entrega, cobro o cancelación no participa.**
- Una **rectificativa** es otro documento fiscal y necesita su propio resultado.
- La factura original **conserva su QR** aunque después quede cancelada o rectificada.
- QR renderizable → las **cinco plantillas fiscales** lo renderizan con rótulo «QR tributario:» y 35 mm. **`proforma.blade.php` no renderiza QR en ningún caso**: pierde su bloque tributario por completo, porque una proforma nunca lo lleva (§4.2).

### 4.4 Dónde vive cada comprobación

```php
$isFiscal     = $invoice->serie->isFiscal();
$isRegistered = $invoice->isFiscallyVerified();
$qrResult     = $this->fiscalVerificationQrResult($invoice);
```

- **`Invoice::shouldIncludeQR()`** pasa a expresar exactamente los dos ejes que gobiernan:

  ```php
  return $this->serie->isFiscal() && $this->isFiscallyVerified();
  ```

  `InvoiceSerieType::isFiscal()` **ya existe** (`:66`) y cubre `INVOICE`, `SIMPLIFIED`, `RECTIFICATIVE`: la regla no añade superficie. **Efecto colateral deseado:** el `shouldIncludeQR()` actual es `INVOICE || RECTIFICATIVE` y **excluye `SIMPLIFIED`**; una factura simplificada es documento fiscal con valor legal y lleva su QR tributario. La regla de dominio corrige ese defecto latente sin proponérselo.

- **Duplicación a eliminar:** existen **dos** `shouldIncludeQR()` con la lógica copiada — `Invoice:456` y `DomPDFService:232` — y el mismo agujero de `SIMPLIFIED` en ambas. El dueño es el modelo; la del servicio delega o desaparece.

- **`PDFService` (la frontera)** aplica la política, y la comprueba sobre **los dos hechos, no solo sobre el QR**. Un SVG presente con `fiscal_verification_id` o `fiscal_verified_at` ausentes no es un registro coherente y no puede pasar:

  ```php
  if ($isFiscal && $strict && (! $isRegistered || $qrResult === null)) {
      throw MissingFiscalVerificationQr::forInvoice($invoice);
  }

  $qrData = $isFiscal && $isRegistered ? $qrResult : null;
  ```

  `fiscalVerificationQrResult()` devuelve `null` cuando el QR está ausente **o no es renderizable** (§4.4.1). Con resultado `null` y config no estricta → adelante sin bloque tributario. La rama del connector desaparece del flujo.

#### 4.4.1 Qué es un QR renderizable: validación estructural mínima

**El reconocimiento por prefijo no basta.** `data:image/png;base64,basura` lo pasaría, DomPDF produciría un PDF sin imagen y el servicio devolvería `success: true`; un `<svg` truncado, igual. Eso contradice de plano `require_fiscal_verification_qr`: el consumidor pidió un QR, no una cadena con un prefijo conocido. Sería este mismo defecto reintroducido dentro de su propio arreglo.

Larabill valida **únicamente que el valor persistido sea una imagen que puede entregar a DomPDF**:

- **PNG:** base64 **estricto** (`base64_decode($data, true)`) y **firma binaria PNG** en los bytes decodificados.
- **SVG:** **XML bien formado**, parseado **sin acceso de red** (`LIBXML_NONET`), con **elemento raíz `svg`**.

Y **no** valida: contenido fiscal, capacidad de escaneo, ni la URL interna del QR. Eso pertenece al productor.

Esto **no duplica a lara-verifactu**, y el argumento de «confía en quien lo produce» no se sostiene: lara-verifactu es **opcional** (el acoplamiento es por evento), la columna `fiscal_verification_qr` es de larabill, y puede poblarla otra integración o contener datos históricos. No puede constituir la garantía universal de una columna que no controla.

La validación sin red no es higiene teórica: el servicio construye DomPDF con `'is_remote_enabled' => true`, y el SVG se inyecta crudo en la plantilla (`{!! $qr_data['qr_svg'] !!}`).

Un valor que no pase esta validación **equivale a ausencia**: en modo estricto falla; en modo no estricto se omite el bloque tributario.

- **Las plantillas** solo pintan lo que reciben: si hay imagen, la renderizan con rótulo y 35 mm; si no hay `qr_data`, no dibujan bloque tributario alguno. Ningún literal `QR_CODE`, ningún volcado de texto.

### 4.5 Compromiso inevitable en v6

Una fila `serie=INVOICE, status=DRAFT` **no se puede distinguir** de una factura fiscal cuyo evento de registro se perdió. Con configuración estricta **debe fallar**: es la respuesta correcta ante un dato perdido. Quien quiera una previsualización sin trascendencia fiscal debe usar `serie=PROFORMA`. Resolver el caso de una factura fiscal realmente «preparada pero no emitida» exige el eje documental separado que plantea v7, y queda fuera de AID-508.

### 4.6 Normativa a anotar en el código

Los comentarios citan la fuente; no afirman la norma por su cuenta.

- **Contenido y presentación del QR:** spec AEAT QR v0.4.7, art. 20-21 de la orden de desarrollo — nivel de corrección M, contenido limitado a la URL de cotejo (`nif`, `numserie`, `fecha`, `importe`), impreso entre 30×30 y 40×40 mm y precedido del texto «QR tributario:». lara-verifactu delega explícitamente la presentación en el consumidor (docblock de `QrGenerator`); el consumidor es larabill.
- **Calendario de obligación:** 1 de enero de 2027 para contribuyentes del Impuesto sobre Sociedades; 1 de julio de 2027 para el resto de obligados del art. 3.1. El periodo anterior es de pruebas y permite comenzar o abandonar temporalmente VERI*FACTU. Fuentes: BOE, RD 1007/2023 consolidado (https://www.boe.es/buscar/act.php?id=BOE-A-2023-24840) y AEAT, nota informativa de ampliación de plazos (https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/nota-informativa-ampliacion-plazo-adaptacion-facturacion.html).
- **No se automatiza el cambio por fecha.** Larabill desconoce el tipo de contribuyente, las exclusiones, la modalidad elegida y el momento efectivo de adopción. Quien sabe si opera en ese flujo es el consumidor.

## 5. Alcance

### 5.1 Entra

- **Cableado:** `getInvoiceItems()` → relación `items`, entregando strings exactos (§5.4) y el desglose por línea desde `taxes_applied` (§5.5). `getInvoiceTotals()` → `taxable_amount` / `total_tax_amount` / `total_amount` como strings, más `tax_breakdown` (§5.5). `getCompanyData($invoice)` → `issuer_snapshot` (identidad histórica del emisor, coherente con AID-328: el lado congelado es el snapshot persistido, nunca la fila viva). `getCompanyId()` → `company_fiscal_config_id` real.
- **Presentación mínima (consecuencia de §5.4 y §5.5):** los seis blades pierden toda aritmética (`/ 100`, `number_format`) y pintan el desglose como **una fila por tipo**. No es rediseño de presentación —qué se muestra no cambia, salvo el desglose que hoy es falso—: el diseño de la frontera contenido/presentación sigue siendo de AID-502.
- **Contenido — las seis plantillas:** `fiscal_number` en lugar del inexistente `$invoice->number` (12 sitios: `<title>` + línea «Número» en `fiscal`, `fiscal-minimal`, `fiscal-modern`, `proforma`, `reverse-charge`, `exempt`).
- **Bloque QR — solo las fiscales**, según §4.2. El reparto exacto:

  - **Las cuatro fiscales rezagadas** (`fiscal-minimal`, `fiscal-modern`, `reverse-charge`, `exempt`) reciben el bloque QR real. Hoy hacen `QR: {{ $qr_data['qr_code'] ?? 'QR_CODE' }}`, que ante un QR real volcaría el SVG escapado como texto.
  - **`fiscal.blade.php` se completa:** su rama PNG lleva `width: 35mm; height: 35mm`, pero **la rama SVG va sin dimensiones** (`{!! $qr_data['qr_svg'] !!}`) — y el SVG es el formato preferente del listener (`getQrSvg() ?? getQrPng() ?? ...`), así que el camino más probable es el que incumple el tamaño reglamentario.
  - **`proforma.blade.php` pierde su bloque QR por completo.** No se «actualiza para pintar el QR real»: una proforma no lleva QR tributario en ningún caso.
- **QR:** según §4.
- **Desblindaje:** según §3.2 y §3.3.
- **Fichero:** una sola raíz, nombre unificado y sin URL pública — §5.6.
- **`template_name` huérfano:** `Log::warning` según §5.3.
- **Tests:** según §6.

### 5.2 Sale

- **El vocabulario `type` (`fiscal`…) contra `getInvoiceType()` (`invoice`…) se va entero a AID-502.** Es la única pieza que exige decidir qué vocabulario es canónico, que es precisamente la frontera que AID-502 traza. Nada de lo que entra lo necesita: el punto del fichero se resuelve usando `getInvoiceType()` en ambos lados.
- **Tickets nuevos:** la contradicción de contrato del subsistema PDF (§8.2); `InvoiceService:225` (`tax_rules_applied => []` persistido vacío); `CacheService:684`; mutation testing (Infection) acotado a los subsistemas de dinero y fiscalidad.

### 5.3 El `template_name` que no resuelve

Hoy, si un consumidor configura `template_name`, `getByName($name, 'invoice')` no encuentra nada —ninguna plantilla sembrada tiene `type='invoice'`— y el servicio cae al default en silencio: finge obedecer.

El `Log::warning` se emite **únicamente** cuando concurren las cuatro condiciones:

1. `template_name` fue configurado explícitamente.
2. La consulta terminó correctamente (**no** se envuelve en otro `catch`; si revienta, sube a la frontera).
3. El resultado fue `null`.
4. Se usa conscientemente la plantilla predeterminada.

Mensaje, que no afirma la causa porque hasta AID-502 no se sabe si falló por el nombre o por la taxonomía:

> `Configured invoice PDF template could not be resolved; using the default template.`

Contexto estructurado: nombre solicitado, tipo usado en la búsqueda, identificador de factura.

### 5.4 Importes: el servicio entrega strings exactos, los blades solo imprimen

**Hoy los seis blades mezclan dos convenciones de escala en líneas consecutivas:**

```blade
<td>{{ number_format($item['quantity'], 2) }}</td>              {{-- SIN /100 → espera escalado (1.5) --}}
<td>{{ number_format($item['unit_price'] / 100, 2) }} €</td>    {{-- CON /100 → espera unscaled (1234) --}}
```

Solo no explota porque el stub pasa `'quantity' => 1` literal. Cablear los datos reales sin resolver esto imprimiría `150,00` unidades o `0,12 €`.

**Decisión: el servicio entrega strings exactos con `FixedDecimal::toDecimalString()`; los blades solo imprimen.** Desaparece toda la aritmética de presentación (`$x / 100`, `number_format`): no se hacen cuentas con dinero en una plantilla, y menos en float.

- `getInvoiceItems()` devuelve por línea: `description` (string), `quantity`, `unit_price`, `taxable_amount`, `tax_amount`, `total` — **todos strings** vía `toDecimalString()`, más `taxes` (§5.5).
- `getInvoiceTotals()` devuelve `subtotal`, `tax_amount`, `total` como strings, más `tax_breakdown` (§5.5).
- Los blades pasan de `{{ number_format($item['unit_price'] / 100, 2) }} €` a `{{ $item['unit_price'] }} €`.

**Consecuencia visible, consciente:** `toDecimalString()` entrega el valor exacto **sin formato de locale** — `1234.56`, donde hoy sale `1,234.56`. Ninguno de los dos es el formato español (`1.234,56`): hoy ya está mal, y arreglarlo es localización de presentación, que pertenece a AID-502. Este PR entrega exactitud, no locale; el cambio va al CHANGELOG bajo *Fixed*.

### 5.5 Desglose por tipo impositivo

**El bloque de totales de los seis blades toma el tipo del primer ítem y lo asume para toda la factura**, con un `21` hardcodeado de fallback:

```blade
<td>IVA ({{ $items[0]['tax_rate'] ?? 21 }}%):</td>
```

Una factura con líneas a tipos mixtos (21 % y 10 %) muestra un desglose falso. No es cosmético: el RD 1619/2012 art. 6.1.g/h exige la base imponible y el tipo impositivo, desglosados cuando la factura comprende varios. **Entra completo en AID-508**: es la misma clase de defecto que el resto del ticket — importes que mienten.

**Fuente de verdad:** el snapshot inmutable `invoice_items.taxes_applied`, cuya forma exacta es:

```php
['source_rate_id' => int, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100]
```

`rate` es base-100 del porcentaje (`2100` = 21 %; `/10000` da la fracción con la que se calculó). `amount` es base-100 en euros.

**Agregación:** recorrer los ítems y sus `taxes_applied`, agrupando por `source_rate_id` (la identidad del tipo, no su porcentaje: el snapshot conserva el vigente en la emisión). Por grupo se acumula `amount`, y como base se acumula el `taxable_amount` del ítem que lo aplica — un ítem con dos tributos sobre la misma base (IVA + recargo de equivalencia) aporta su base a ambos, que es lo correcto.

```php
'tax_breakdown' => [
    ['rate' => '21.00', 'name' => 'IVA 21%', 'base' => '100.00', 'amount' => '21.00'],
    ['rate' => '10.00', 'name' => 'IVA 10%', 'base' => '50.00',  'amount' => '5.00'],
],
```

Los blades pintan **una fila por grupo**, no una fila fija. `rate` también es string (`toDecimalString()` sobre base-100): el `int` que asume el blade actual no puede representar un recargo de equivalencia del 5,2 %. Consecuencia visible: donde hoy pone `IVA (21%)` pasará a poner `IVA 21% (21.00%)` por grupo — al CHANGELOG.

**Factura sin impuestos** (`taxes_applied` vacío — ítem sin grupo fiscal resoluble, exenta, reverse-charge): `tax_breakdown` queda vacío y **no se pinta ninguna fila de IVA**. Nunca el `?? 21` de hoy, que fabricaba un tipo inexistente.

### 5.6 La raíz del fichero y la URL

Hoy hay **dos** desajustes, no uno. El del nombre (`$invoice->type`, columna inexistente, contra `getInvoiceType()`) y el de la ubicación: `savePDF()` escribe en `storage_path('app/invoices/')` (`:429`) mientras `generatePDFUrl()` publica `url('storage/invoices/')` (`:453`) — que con el enlace estándar de Laravel corresponde a `storage/app/public/invoices`. Nunca han apuntado al mismo sitio.

**Decisión: las facturas son privadas y larabill no fabrica URLs públicas.**

- **Raíz única: `storage_path('app/invoices/')`.** Sin bifurcación por entorno (muere con `isProductionEnvironment()`), sin `sys_get_temp_dir()`. Los tests apuntan la raíz a un directorio temporal por configuración de entorno de test, no por una rama en el código de producción.
- **`savePDF()` y `getPDFPath()` comparten nombre y raíz.** Son **los dos únicos sitios** que componen el nombre del fichero, y lo derivan de `getInvoiceType()`. Cierra la regeneración del PDF en cada descarga.
- **`generatePDFUrl()` se elimina:** queda huérfano.
- **`DomPDFService::generatePDF()` devuelve `'pdf_url' => null`.**
- **`Invoice::getPDFUrl()` devuelve `null`.** No es un hueco: es la respuesta verdadera. larabill no publica facturas por URL — la entrega es responsabilidad del consumidor, que ya la resuelve con un controlador autorizado (`InvoicePdfDownloadController` + `InvoicePolicy::download()`).

El motivo no es solo de coherencia, es de seguridad: `url('storage/invoices/invoice_<uuid>_invoice.pdf')` es una **URL pública sin autorización**. Si el consumidor hubiera creado el symlink y los nombres hubieran coincidido, **cualquiera que obtuviese la URL** se habría descargado la factura saltándose la policy — y una URL se filtra en logs, historiales de navegación y cabeceras `Referer`. La protección no puede descansar en lo difícil que sea adivinar un UUID. Que los dos lados nunca coincidieran es, por accidente, lo único que ha evitado ese agujero; fabricar esa URL «bien» sería convertir el accidente en una función.

**Las firmas no cambian** (`getPDFUrl(): ?string` ya es nullable), así que el golden-master sigue verde. `getPDFUrl()` queda anotado como candidato a deprecación en el ticket de §8.2: un método que solo puede devolver `null` es superficie que sobra, pero retirarlo exige un major y no se improvisa aquí.

## 6. Tests

- **Contenido, sobre las seis plantillas** (dataset Pest, patrón AID-439/442), afirmando sobre el HTML renderizado con modelo real: `fiscal_number` exacto, descripción e importe reales de cada línea, los tres totales, y el emisor del `issuer_snapshot`.
- **Escala (§5.4):** una línea con `quantity` = 1,5 y `unit_price` = 12,34 € imprime `1.50` y `12.34` — **no** `150.00` ni `0.12`. Es el guard de la trampa de las dos convenciones: hoy pasa solo porque el stub literal la esquiva.
- **Desglose (§5.5):** factura con **tipos mixtos** (21 % y 10 %) → **dos** filas de IVA con su base y su cuota correctas, no una fila con el tipo del primer ítem. Factura **sin impuestos** (`taxes_applied` vacío) → **ninguna** fila de IVA y ni rastro del `21` hardcodeado. Y un tipo con decimales (recargo 5,2 %) imprime `5.20`, que el `int` de hoy no puede representar.
- **Un end-to-end con `smalot/pdfparser`** (require-dev, versión fijada — evita depender de ejecutables del sistema en CI): genera el PDF real por la ruta de producción, extrae el texto y afirma **presencia** de número, línea, importes y emisor. **No** orden ni layout del texto extraído, que sería frágil.
- **Matriz QR**, gobernada por los dos ejes de §4.2. **Ninguna expectativa depende de `InvoiceStatus`** — el estado sí aparece en el dataset, precisamente para demostrar que no cambia nada:

  - Renderizables (§4.4.1): SVG bien formado con raíz `svg`, PNG con base64 estricto y firma binaria. **No** renderizables: URL sola, ausencia, formato desconocido, y los **dos negativos estructurales** — `data:image/png;base64,<base64 inválido>` y `<svg` mal formado o truncado. En modo estricto **fallan**; en modo no estricto **se omite el bloque**.
  - Factura fiscal (`INVOICE`, `SIMPLIFIED`, `RECTIFICATIVE`) + registro coherente + QR renderizable → imagen con rótulo «QR tributario:» y 35 mm; **nunca** SVG escapado ni `QR_CODE`. **Cubrir SVG y PNG por separado**: la rama SVG es la que hoy va sin dimensiones.
  - Factura fiscal + config estricta + (sin registro **o** ausencia/URL) → `success: false` y **ningún fichero escrito**. Incluido el caso que hoy pasaría: **QR presente pero registro incoherente** (`fiscal_verification_id` o `fiscal_verified_at` a `null`).
  - Factura fiscal + config no estricta + ausencia/URL → documento sin bloque QR.
  - **Proforma → nunca QR, en ningún estado y con cualquier config.** Y un caso que lo prueba de verdad: **proforma con `qr_data` inyectado → no lo muestra** (sustituye al caso «borrador exento»: lo que exime es la clase documental, no el estado).
  - **`SIMPLIFIED` verificada → lleva QR** (hoy no lo lleva: guard de regresión del defecto latente de §4.4).
  - **Entrega y cobro no participan:** el **mismo registro fiscal** con `SENT`, `PAID`, `OVERDUE` y `DRAFT` → **resultado idéntico**. Y `serie=INVOICE` sin registro + config estricta → falla **por carecer de registro, no por ser `DRAFT`** (§4.5).
- **Spy** que demuestre que el flujo normal **no invoca ningún connector** para fabricar el QR.
- **Sin URL pública** (§5.6): el resultado trae `pdf_url === null`, `Invoice::getPDFUrl()` devuelve `null`, y **la salida no contiene ninguna URL `/storage/invoices/...`**.
- **`DefaultPDFConnectorTest` se conserva**, sustituyendo el teatro de metadata por una prueba pequeña de que `generateQR()` falla explícitamente.
- **`FiscalTemplateRenderTest:31,49` y las cinco llamadas de `PDFServiceTest`** se refuerzan para afirmar contenido en vez de la bandera `success`.
- **Sensibilidad obligatoria** (regla de la casa, AID-390/AID-264): cada test nuevo se comprueba contra el código pre-fix y debe fallar. Un test de contenido que pase hoy es teatro nuevo.

## 7. Versión y entrega

- **Preliminar: `v6.4.0` (minor).** Cero migraciones, ninguna firma alterada, ninguna superficie retirada, golden-master intacto. Lo único que lo eleva por encima de patch es **una clave de config nueva**: `STABILITY.md` regla 5 clasifica «new columns, methods, config keys» como MINOR. Es además la única pieza del PR que le pide algo al consumidor (decidir cuándo ponerla a `true`); un patch le diría «no hagas nada» sobre justo esa decisión. La clasificación se confirma con el diff delante.
- **CHANGELOG bajo `Fixed`**, con el comportamiento antiguo y el nuevo explícitos. Es la **única** señal del cambio observable: los contract snapshots capturan forma (`api`, `deprecated`, `parameters`, `returnType`, `static`), no verdad — no habrían cazado este defecto y no cazarán este cambio de semántica.
- **Lectura de la config con default explícito:** `config('larabill.pdf.require_fiscal_verification_qr', false)`. Un consumidor ya instalado no recibe la clave nueva (el `vendor:publish` sin `--force` no machaca su config); sin el default, el arreglo llegaría roto a quien ya lo tiene.

## 8. Límites conocidos y seguimiento

### 8.1 Límites aceptados conscientemente

- **El booleano global es correcto solo si toda la instalación comparte régimen operativo.** Un consumidor que aloje emisores mezclados —España y Francia, o modalidades distintas— lo encontrará demasiado grueso y necesitará una decisión por emisor. No se amplía el diseño sin un caso real.
- **`PDFConnectorInterface` permanece intacta; lo que cambia es que el pipeline `@internal` deja de invocarla.** La interfaz sigue declarada, tagueada `@api` e implementable: no se retira superficie, así que la regla 3 de `STABILITY.md` no se activa y no hay deprecación que improvisar. Lo que sí desaparece es su efecto dentro de larabill, y eso se documenta en el CHANGELOG y se remite al ticket de §8.2.

  Precisión sobre el motivo, porque la formulación fácil es falsa: **el incumplimiento no procede de fabricar el QR localmente** — lara-verifactu también lo fabrica localmente, y así es como debe ser. Procede de **presentar un código que no lleva la URL de cotejo reglamentaria** (`nif`, `numserie`, `fecha`, `importe`), que es lo que hacía `generateQRCode()` con su `'QR:'.$hash.':'.base64(...)`.
- **Doble registro de un fallo de render.** `renderTemplate()` registra y relanza; la frontera capturará esa misma excepción y volverá a registrarla con el `Log::error` restaurado. Dos entradas para un fallo. No se amplía el cambio ahora: se revisa al mirar ruido y contexto de los logs.

### 8.2 La contradicción de contrato (ticket propio)

El subsistema PDF tiene tres contradicciones que este PR no resuelve y que deben decidirse juntas — si merece una integración pública real o una deprecación reglada:

- `PDFConnectorInterface` es `@api`, pero su única vía de registro (`PDFService::registerConnector()`) vive en una clase `@internal`.
- `Invoice::generatePDF()` no permite elegir connector, e `initializeConnectors()` hardcodea `new DefaultPDFConnector` como default.
- `getPDFUrl()` solo puede devolver `null` tras §5.6: superficie que sobra, retirable únicamente en un major.
- `docs/api-surface.md:40` afirma que la superficie pública es «`Invoice::generatePDF()` + `PDFConnectorInterface`», pero el golden-master registra `generatePDF`, `getInvoiceType`, `getPDFPath`, `getPDFUrl` y `shouldIncludeQR` todos con `"api": false`. **El doc y el código se contradicen.**
