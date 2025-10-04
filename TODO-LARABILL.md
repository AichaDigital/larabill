# 📋 TODO ESPECIALIZADO: AichaDigital/Larabill

## 🎯 **MÓDULO DE FACTURACIÓN LARAVEL PROFESIONAL**

**Repositorio:** `AichaDigital/Larabill`  
**Namespace:** `AichaDigital\Larabill`  
**Estructura:** Spatie Package Skeleton Laravel  
**Objetivo:** Sistema completo de facturación fiscal para España, UE y resto del mundo

---

## 📋 **FASE 1: ESTRUCTURA BASE DEL MÓDULO** [1 día]

### 1.1 Crear Estructura del Paquete
- [x] **Instalar Spatie Package Skeleton**
  ```bash
  composer create-project spatie/package-skeleton-laravel packages/aichadigital/larabill
  ```
- [x] **Configurar composer.json** del paquete
  - [x] Namespace: `AichaDigital\Larabill`
  - [x] Dependencias: Laravel, Http Client, DomPDF
  - [x] Autoloading PSR-4
  - [x] Keywords: billing, invoice, vat, tax, spain, eu
  - [x] PHP 8.3 (Preferible usar sintaxis PHP 8.4 que sea permitida en php 8.3)
- [x] **Configurar composer.json** del proyecto principal
  - [x] Agregar autoload para packages/
  - [x] Agregar repository path
- [x] **Configurar Service Provider**
  - [x] Registro de servicios
  - [x] Publicación de config
  - [x] Publicación de migraciones
  - [x] Publicación de vistas

### 1.2 Modelos Base (AGNÓSTICOS)
- [x] **Invoice.php**
  - [x] Campos: number, type, status, user_id, user_tax_info_encrypted
  - [x] Campos: is_immutable, immutable_at, subtotal, tax_amount, total
  - [x] Campos: fiscal_data, vat_verification, due_date, paid_at
  - [ ] Mutators para encriptación automática
  - [x] Método makeImmutable()
  - [x] Override update() para prevenir modificaciones
  - [x] Relaciones: items(), user() (configurable via config)
- [x] **InvoiceItem.php**
  - [x] Campos: invoice_id, description, quantity, unit_price, subtotal
  - [x] Campos: tax_rate, tax_amount, total, vat_category
  - [x] Relación belongsTo Invoice
  - [ ] Mutators para calcular totales automáticamente
- [x] **UserTaxInfo.php**
  - [x] Campos: user_id, is_current, tax_id, company_name, address
  - [x] Campos: city, postal_code, country, state, phone
  - [x] Relación belongsTo User (configurable)
  - [x] Método makeCurrent()
  - [x] Scope current()
- [ ] **TaxRate.php**
  - [ ] Campos: country_code, country_name, tax_name, tax_type, rate
  - [ ] Campos: is_active, applies_to, special_conditions
  - [ ] Método estático getSpanishRates()
  - [ ] Método estático getEURates()
  - [ ] Scope para países activos
- [x] **VatVerification.php**
  - [x] Campos: vat_number, country_code, is_valid, company_name
  - [x] Campos: address, verification_date, api_used, response_data
  - [x] Cast response_data como array
  - [x] Scope para verificaciones válidas

### 1.2.1 Modelos ROI (Reverse Charge Operator) - NUEVO
- [ ] **UserRoiVerification.php**
  - [ ] Campos: user_id, vat_number, country_code, is_roi
  - [ ] Campos: company_name, company_address, last_check, expired_at
  - [ ] Campos: api_source, response_data, cache_hit
  - [ ] Método isExpired() - verificar si el cache expiró
  - [ ] Método isValid() - verificar si es ROI válido
  - [ ] Scope valid(), expired(), byCountry()
  - [ ] Relación belongsTo User (configurable)
- [ ] **RoiQuery.php**
  - [ ] Campos: user_id, vat_number, country_code, query_type
  - [ ] Campos: api_source, response_data, queried_at, cache_used
  - [ ] Campos: legal_retention_until (7 años)
  - [ ] Scope byDateRange(), byQueryType(), legalRetention()
  - [ ] Relación belongsTo User (configurable)

### 1.2.2 Modelos IVA de Destino - NUEVO
- [ ] **CompanyFiscalConfig.php**
  - [ ] Campos: company_id, apply_destination_iva, eu_sales_threshold
  - [ ] Campos: current_eu_sales_amount, threshold_exceeded_at
  - [ ] Campos: fiscal_year, auto_apply_destination, notification_sent
  - [ ] Método checkThreshold() - verificar si se supera umbral
  - [ ] Método updateEuSales() - actualizar ventas intracomunitarias
  - [ ] Scope byFiscalYear(), thresholdExceeded()
- [ ] **VatCategory.php**
  - [ ] Campos: name, description, country_code, vat_rate
  - [ ] Campos: category_type, is_active, applies_to_products
  - [ ] Campos: special_conditions, last_updated
  - [ ] Método estático getByCountry()
  - [ ] Método estático getByCategoryType()
  - [ ] Scope active(), byCountry(), byCategoryType()
- [ ] **EuSalesThreshold.php**
  - [ ] Campos: company_id, fiscal_year, total_amount
  - [ ] Campos: threshold_exceeded, exceeded_at, notification_sent
  - [ ] Campos: breakdown_by_country (JSON)
  - [ ] Método calculateTotal() - calcular total de ventas
  - [ ] Método checkThreshold() - verificar umbral
  - [ ] Scope byFiscalYear(), exceeded(), byCompany()
- [ ] **CountryVatRate.php**
  - [ ] Campos: country_code, country_name, standard_rate
  - [ ] Campos: reduced_rates (JSON), exempt_categories (JSON)
  - [ ] Campos: last_updated, data_source, is_active
  - [ ] Método getRateForCategory() - obtener tasa por categoría
  - [ ] Método getReducedRates() - obtener tasas reducidas
  - [ ] Scope active(), byCountry(), byRate()

### 1.3 Configuración Agnóstica
- [x] **config/larabill.php**
  - [x] Configuración de modelos (User, UserTaxInfo, Invoice, etc.)
  - [x] Mapeo de campos fiscales configurables
  - [x] Configuración de APIs (VAT verification)
  - [x] Configuración de numeración
  - [x] Configuración de inmutabilidad
  - [x] Configuración de PDF
  - [x] Configuración de emails
  - [x] Configuración de impuestos por defecto
  - [ ] **NUEVO: Configuración ROI**
    - [ ] Cache duration (15 días por defecto)
    - [ ] Force API check (false por defecto)
    - [ ] Legal retention (7 años)
    - [ ] API rate limits
  - [ ] **NUEVO: Configuración IVA de Destino**
    - [ ] Default threshold (€10,000)
    - [ ] Fiscal year start (01-01)
    - [ ] Auto apply destination VAT
    - [ ] Notification settings
  - [ ] **NUEVO: Configuración Cache Agnóstico**
    - [ ] Cache driver (file/redis)
    - [ ] Cache prefix
    - [ ] Cache TTL
    - [ ] Cache tags

### 1.4 Migraciones
- [x] **create_user_tax_infos_table.php**
  - [x] Índices para user_id, is_current
  - [x] Constraint único para user_id + is_current
- [x] **create_invoices_table.php**
  - [x] Índices para number, user_id, status
  - [x] Índice compuesto para type + status
- [x] **create_invoice_items_table.php**
  - [x] Índice para invoice_id
- [ ] **create_tax_rates_table.php**
  - [ ] Índice único para country_code + tax_type
  - [ ] Índice para is_active
- [x] **create_vat_verifications_table.php**
  - [x] Índice único para vat_number + country_code
  - [x] Índice para verification_date
- [ ] **NUEVO: create_user_roi_verifications_table.php**
  - [ ] Índice único para user_id + vat_number + country_code
  - [ ] Índice para expired_at
  - [ ] Índice para last_check
  - [ ] Constraint para expired_at > last_check
- [ ] **NUEVO: create_roi_queries_table.php**
  - [ ] Índice para user_id + queried_at
  - [ ] Índice para query_type
  - [ ] Índice para legal_retention_until
  - [ ] Constraint para legal_retention_until > queried_at
- [ ] **NUEVO: create_company_fiscal_configs_table.php**
  - [ ] Índice único para company_id + fiscal_year
  - [ ] Índice para threshold_exceeded_at
  - [ ] Índice para apply_destination_iva
- [ ] **NUEVO: create_vat_categories_table.php**
  - [ ] Índice único para country_code + name
  - [ ] Índice para category_type
  - [ ] Índice para is_active
- [ ] **NUEVO: create_eu_sales_thresholds_table.php**
  - [ ] Índice único para company_id + fiscal_year
  - [ ] Índice para threshold_exceeded
  - [ ] Índice para exceeded_at
- [ ] **NUEVO: create_country_vat_rates_table.php**
  - [ ] Índice único para country_code
  - [ ] Índice para is_active
  - [ ] Índice para last_updated
- [ ] **Seeders**
  - [ ] TaxRatesSeeder con datos españoles
  - [ ] TaxRatesSeeder con datos europeos
  - [ ] TaxRatesSeeder con datos mundiales básicos
  - [ ] **NUEVO: CountryVatRatesSeeder** con datos de rate_list_eu.json
  - [ ] **NUEVO: VatCategoriesSeeder** con categorías estándar

---

## 📋 **FASE 2: SERVICIOS CORE** [2 días]

### 2.1 VatVerificationService
- [x] **Configuración de APIs** (básico)
  - [x] AbstractAPI (preferida) - https://vat.abstractapi.com
  - [x] APILayer (fallback) - http://apilayer.net
  - [ ] Round-robin para testing (100 calls límite)
- [x] **Método verifyVatNumber()** (básico)
  - [x] Cache de verificaciones (30 días)
  - [x] Fallback entre APIs
  - [x] Logging de errores
  - [ ] Rate limiting
- [x] **Métodos privados**
  - [x] tryAbstractApi()
  - [x] tryApiLayer()
  - [x] parseApiResponse()
- [x] **Configuración en billing.php**
  - [x] API keys
  - [x] API preferida
  - [ ] Límites de rate
  - [ ] Timeout configurables

### 2.1.1 RoiVerificationService - NUEVO
- [ ] **Configuración de Cache Agnóstico**
  - [ ] Driver configurable (file/redis)
  - [ ] TTL configurable (15 días por defecto)
  - [ ] Prefix para cache keys
  - [ ] Tags para invalidación
- [ ] **Método verifyRoiStatus()**
  - [ ] Verificar cache primero
  - [ ] Consultar API si cache expirado
  - [ ] Guardar en UserRoiVerification
  - [ ] Registrar en RoiQuery
  - [ ] Retornar resultado con metadata
- [ ] **Método isRoiValid()**
  - [ ] Verificar si es ROI válido
  - [ ] Considerar expiración de cache
  - [ ] Forzar verificación si es necesario
- [ ] **Métodos privados**
  - [ ] getCachedRoiVerification()
  - [ ] cacheRoiVerification()
  - [ ] logRoiQuery()
  - [ ] isCacheExpired()

### 2.2 TaxCalculationService
- [x] **Método calculateTax()** (básico)
  - [x] Parámetros: subtotal, customerCountry, customerType, vatVerification
  - [x] Retorna: total_tax, tax_breakdown, special_conditions, invoice_notes
- [x] **Métodos específicos por región** (básico)
  - [x] calculateSpanishTax() - IVA estándar (21%, 10%, 4%)
  - [ ] calculateSpecialSpanishTax() - Canarias (IGIC), Ceuta/Melilla (IPSI)
  - [x] calculateEUTax() - Intracomunitario con reverse charge
  - [ ] calculateWorldwideTax() - Resto del mundo (sin IVA)
- [ ] **NUEVO: Lógica de ROI y IVA de Destino**
  - [ ] Verificación de ROI (Registro de Operadores Intracomunitarios)
  - [ ] Aplicación de IVA de destino según umbrales
  - [ ] Notas fiscales específicas
  - [ ] Cálculo de umbrales intracomunitarios
- [x] **Casos especiales** (básico)
  - [ ] Canarias: IGIC 7%, exento de IVA español
  - [ ] Ceuta/Melilla: IPSI 0%, exento de IVA español
  - [x] UE B2B: Reverse charge (IVA 0% + nota)
  - [ ] **NUEVO: UE B2C: IVA de destino** con umbrales
  - [ ] EEUU: Sales Tax (configurable por estado)

### 2.2.1 DestinationVatService - NUEVO
- [ ] **Gestión de Umbrales**
  - [ ] Verificar umbral actual de ventas intracomunitarias
  - [ ] Calcular total de ventas por año fiscal
  - [ ] Detectar superación de umbral (€10,000)
  - [ ] Notificar cambio de régimen fiscal
- [ ] **Aplicación de IVA de Destino**
  - [ ] Determinar si aplicar IVA de destino
  - [ ] Obtener tasa VAT del país de destino
  - [ ] Calcular IVA según categoría de producto
  - [ ] Generar notas fiscales específicas
- [ ] **Configuración por Empresa**
  - [ ] Permitir configuración flexible por empresa
  - [ ] Soporte para diferentes umbrales por empresa
  - [ ] Configuración de año fiscal personalizado
  - [ ] Notificaciones configurables
- [ ] **Métodos privados**
  - [ ] calculateEuSalesTotal()
  - [ ] checkThresholdExceeded()
  - [ ] getDestinationVatRate()
  - [ ] generateFiscalNotes()

### 2.3 BillingService
- [x] **Método createInvoice()** (básico)
  - [ ] **NUEVO: Verificación ROI si es necesario**
  - [x] Cálculo de impuestos
  - [x] Creación de factura
  - [x] Creación de items
  - [ ] Inmutabilidad opcional
- [ ] **Método createProforma()**
  - [ ] Numeración específica (PRO + número)
  - [ ] Estado draft
  - [ ] Sin inmutabilidad
- [ ] **Método convertToInvoice()**
  - [ ] Conversión de proforma
  - [ ] Nueva numeración (FAC + número)
  - [ ] Inmutabilidad opcional
  - [ ] Preservar datos fiscales
- [x] **Método generateNumber()** (básico)
  - [x] Configuración de prefijos (PRO, FAC)
  - [x] Numeración secuencial
  - [ ] Reset anual opcional
  - [ ] Formato configurable (YYYYMMDDHHMMNN)

### 2.3.1 CompanyConfigService - NUEVO
- [ ] **Gestión de Configuración Fiscal**
  - [ ] Crear/actualizar configuración fiscal por empresa
  - [ ] Gestionar umbrales personalizados
  - [ ] Configurar año fiscal personalizado
  - [ ] Activar/desactivar IVA de destino
- [ ] **Flexibilidad de Modelos**
  - [ ] Permitir modelos de empresa personalizados
  - [ ] Mapeo de campos configurables
  - [ ] Soporte para diferentes estructuras de datos
  - [ ] Validaciones configurables
- [ ] **Métodos privados**
  - [ ] getCompanyModel()
  - [ ] mapCompanyFields()
  - [ ] validateCompanyConfig()
  - [ ] updateFiscalSettings()

---

## 📋 **FASE 3: CONFIGURACIÓN Y DASHBOARD** [1 día]

### 3.1 Configuración Avanzada
- [ ] **config/billing.php**
  - [ ] API keys para verificación VAT
  - [ ] Datos de la empresa (ROI, dirección, etc.)
  - [ ] Configuración de numeración
  - [ ] Configuración de inmutabilidad
  - [ ] Configuración de PDF
  - [ ] Configuración de emails
- [ ] **Service Provider**
  - [ ] Registro de servicios
  - [ ] Publicación de config
  - [ ] Publicación de migraciones
  - [ ] Publicación de vistas
  - [ ] Registro de comandos Artisan

### 3.2 Dashboard de Configuración
- [ ] **TaxRateController**
  - [ ] CRUD de tasas de impuestos
  - [ ] Importación de tasas por país
  - [ ] Activación/desactivación
  - [ ] Validaciones específicas por país
- [ ] **BillingConfigController**
  - [ ] Configuración de numeración
  - [ ] Configuración de APIs
  - [ ] Configuración de empresa
  - [ ] Configuración de inmutabilidad
- [ ] **Vistas de administración**
  - [ ] Listado de tasas con filtros
  - [ ] Formulario de configuración
  - [ ] Dashboard principal con métricas
  - [ ] Importación masiva de tasas

---

## 📋 **FASE 4: INTERFAZ DE USUARIO** [1 día]

### 4.1 Controllers
- [ ] **InvoiceController**
  - [ ] show() - Siempre visible
  - [ ] edit() - Solo si no es inmutable
  - [ ] makeImmutable() - Marcar como inmutable
  - [ ] updateCustomerData() - Actualizar datos fiscales
  - [ ] downloadPDF() - Descargar PDF
  - [ ] emailPDF() - Enviar por email
- [ ] **InvoiceItemController**
  - [ ] CRUD de items
  - [ ] Validaciones de cantidad y precio
  - [ ] Cálculo automático de totales

### 4.2 Livewire Components
- [ ] **InvoiceManagement**
  - [ ] Listado de facturas con paginación
  - [ ] Filtros por estado, tipo, fecha, cliente
  - [ ] Acciones masivas (marcar inmutable, enviar email)
  - [ ] Búsqueda en tiempo real
- [ ] **InvoiceForm**
  - [ ] Creación/edición de facturas
  - [ ] Validaciones en tiempo real
  - [ ] Cálculo automático de impuestos
  - [ ] Selección de cliente con autocompletado
- [ ] **TaxRateManagement**
  - [ ] Gestión de tasas con filtros
  - [ ] Importación masiva desde CSV
  - [ ] Configuración por país
  - [ ] Validación de tasas duplicadas

### 4.3 Vistas
- [ ] **Layout base** con DaisyUI
  - [ ] Navegación responsive
  - [ ] Sidebar de configuración
  - [ ] Breadcrumbs
- [ ] **Listado de facturas** con filtros avanzados
- [ ] **Formulario de factura** con validaciones
- [ ] **Vista de factura** (solo lectura si inmutable)
- [ ] **Dashboard de configuración** con métricas
- [ ] **Gestión de tasas** con importación

---

## 📋 **FASE 5: GENERACIÓN PDF** [1 día]

### 5.1 PDFGenerator Service
- [ ] **Configuración de DomPDF**
  - [ ] Fuentes personalizadas (Arial, Times)
  - [ ] Configuración de página (A4, márgenes)
  - [ ] Configuración de encoding (UTF-8)
- [ ] **Método generateInvoicePDF()**
  - [ ] Datos de la empresa (logo, dirección, NIF)
  - [ ] Datos del cliente (encriptados si inmutable)
  - [ ] Items de la factura con descripción
  - [ ] Cálculo de impuestos detallado
  - [ ] Notas fiscales especiales
  - [ ] Pie de página con términos legales
- [ ] **Plantillas PDF**
  - [ ] Factura estándar (España)
  - [ ] Proforma
  - [ ] Factura con reverse charge (UE)
  - [ ] Factura exenta (Canarias, Ceuta, Melilla)
  - [ ] Factura internacional (resto del mundo)

### 5.2 Integración con Facturas
- [ ] **Método en Invoice model**
  - [ ] generatePDF()
  - [ ] downloadPDF()
  - [ ] emailPDF()
  - [ ] getPDFPath()
- [ ] **Controller methods**
  - [ ] download()
  - [ ] email()
  - [ ] preview()
  - [ ] regenerate()

---

## 📋 **FASE 6: INTEGRACIÓN CON STRIPE** [1 día]

### 6.1 Webhook Integration
- [ ] **StripeWebhookController**
  - [ ] Verificación de firma
  - [ ] Procesamiento de eventos
  - [ ] Generación automática de facturas
  - [ ] Manejo de errores
- [ ] **Eventos Stripe**
  - [ ] payment_intent.succeeded
  - [ ] invoice.payment_succeeded
  - [ ] customer.subscription.created
  - [ ] customer.subscription.updated
  - [ ] customer.subscription.deleted
- [ ] **Sincronización de datos**
  - [ ] Customer data (nombre, email, dirección)
  - [ ] Payment data (método, fecha, cantidad)
  - [ ] Invoice status (paid, failed, refunded)

### 6.2 Payment Integration
- [ ] **Método en BillingService**
  - [ ] createInvoiceFromStripe()
  - [ ] updateInvoiceStatus()
  - [ ] handleRefund()
  - [ ] syncCustomerData()
- [ ] **Modelo Payment**
  - [ ] Relación con Invoice
  - [ ] Datos de Stripe (payment_intent_id, customer_id)
  - [ ] Estado de pago (pending, succeeded, failed)
  - [ ] Método de pago (card, bank_transfer, etc.)

---

## 📋 **FASE 7: TESTING COMPLETO** [1 día]

### 7.1 Tests Unitarios
- [ ] **TaxCalculationServiceTest**
  - [ ] Cálculo IVA España (21%, 10%, 4%)
  - [ ] Casos especiales (Canarias IGIC, Ceuta/Melilla IPSI)
  - [ ] Reverse charge intracomunitario
  - [ ] Resto del mundo (sin IVA)
  - [ ] Múltiples impuestos (EEUU)
- [ ] **VatVerificationServiceTest**
  - [ ] Verificación exitosa con AbstractAPI
  - [ ] Fallback a APILayer
  - [ ] Cache de verificaciones
  - [ ] Manejo de errores de API
  - [ ] Rate limiting
- [ ] **BillingServiceTest**
  - [ ] Creación de facturas
  - [ ] Conversión proforma → factura
  - [ ] Numeración automática
  - [ ] Inmutabilidad
  - [ ] Encriptación de datos
- [ ] **NUEVO: RoiVerificationServiceTest**
  - [ ] Verificación ROI exitosa
  - [ ] Cache con expiración
  - [ ] Fallback entre APIs
  - [ ] Registro de consultas legales
  - [ ] Manejo de errores
- [ ] **NUEVO: DestinationVatServiceTest**
  - [ ] Cálculo de umbrales intracomunitarios
  - [ ] Aplicación de IVA de destino
  - [ ] Notificaciones de umbral
  - [ ] Configuración por empresa
  - [ ] Categorización de productos
- [ ] **NUEVO: CompanyConfigServiceTest**
  - [ ] Configuración fiscal flexible
  - [ ] Mapeo de modelos personalizados
  - [ ] Validaciones configurables
  - [ ] Gestión de umbrales
  - [ ] Soporte multi-empresa

### 7.2 Tests de Integración
- [ ] **InvoiceTest**
  - [ ] CRUD completo
  - [ ] Encriptación de datos
  - [ ] Inmutabilidad
  - [ ] Generación PDF
  - [ ] Relaciones con items
- [ ] **StripeIntegrationTest**
  - [ ] Webhooks
  - [ ] Sincronización
  - [ ] Generación automática
  - [ ] Manejo de errores
- [ ] **NUEVO: RoiVerificationIntegrationTest**
  - [ ] Flujo completo de verificación ROI
  - [ ] Cache con expiración
  - [ ] Registro de consultas legales
  - [ ] Integración con APIs externas
  - [ ] Manejo de errores y fallbacks
- [ ] **NUEVO: DestinationVatIntegrationTest**
  - [ ] Flujo completo de IVA de destino
  - [ ] Cálculo de umbrales
  - [ ] Aplicación automática de IVA
  - [ ] Notificaciones de umbral
  - [ ] Configuración por empresa
- [ ] **NUEVO: CompanyConfigIntegrationTest**
  - [ ] Configuración fiscal completa
  - [ ] Mapeo de modelos personalizados
  - [ ] Validaciones configurables
  - [ ] Soporte multi-empresa
  - [ ] Integración con servicios

### 7.3 Tests de Feature
- [ ] **InvoiceManagementTest**
  - [ ] Listado y filtros
  - [ ] Creación de facturas
  - [ ] Edición (solo si no inmutable)
  - [ ] Generación PDF
  - [ ] Envío por email
- [ ] **TaxRateManagementTest**
  - [ ] CRUD de tasas
  - [ ] Importación masiva
  - [ ] Configuración por país
  - [ ] Validaciones
- [ ] **NUEVO: RoiVerificationFeatureTest**
  - [ ] Verificación ROI desde interfaz
  - [ ] Cache y expiración
  - [ ] Registro de consultas
  - [ ] Manejo de errores
  - [ ] Notificaciones de estado
- [ ] **NUEVO: DestinationVatFeatureTest**
  - [ ] Gestión de umbrales desde interfaz
  - [ ] Aplicación de IVA de destino
  - [ ] Notificaciones de umbral
  - [ ] Configuración por empresa
  - [ ] Categorización de productos
- [ ] **NUEVO: CompanyConfigFeatureTest**
  - [ ] Configuración fiscal desde interfaz
  - [ ] Mapeo de modelos personalizados
  - [ ] Validaciones configurables
  - [ ] Soporte multi-empresa
  - [ ] Gestión de umbrales

---

## 📋 **FASE 8: DOCUMENTACIÓN Y DEPLOY** [1 día]

### 8.1 Documentación
- [ ] **README.md**
  - [ ] Instalación via Composer
  - [ ] Configuración básica
  - [ ] Uso básico con ejemplos
  - [ ] Casos de uso específicos
  - [ ] Troubleshooting
- [ ] **Documentación técnica**
  - [ ] API de servicios
  - [ ] Configuración avanzada
  - [ ] Casos de uso por país
  - [ ] Integración con Stripe
- [ ] **Guías de usuario**
  - [ ] Dashboard de administración
  - [ ] Creación de facturas
  - [ ] Configuración de tasas
  - [ ] Generación de PDFs

### 8.2 Deploy y Testing
- [ ] **Configuración de producción**
  - [ ] Variables de entorno
  - [ ] API keys (AbstractAPI, APILayer)
  - [ ] Configuración de base de datos
  - [ ] Configuración de email
- [ ] **Testing en producción**
  - [ ] Verificación de APIs
  - [ ] Generación de PDFs
  - [ ] Integración con Stripe
  - [ ] Performance testing
- [ ] **Monitoreo**
  - [ ] Logs de errores
  - [ ] Métricas de uso
  - [ ] Alertas de API failures
  - [ ] Dashboard de métricas

---

## 🎯 **MÉTRICAS DE ÉXITO**

- [ ] ✅ **Cumplimiento fiscal** completo para España y UE
- [ ] ✅ **Verificación VAT** funcional con fallback
- [ ] ✅ **Generación PDF** con todas las variantes
- [ ] ✅ **Integración Stripe** automática
- [ ] ✅ **Dashboard** funcional para administración
- [ ] ✅ **Testing** completo (unit, integration, feature)
- [ ] ✅ **Documentación** completa
- [ ] ✅ **Reutilizable** en otros proyectos
- [ ] ✅ **Performance** optimizado
- [ ] ✅ **Security** con encriptación

---

## 🚀 **PRÓXIMOS PASOS**

1. **Crear repositorio** AichaDigital/Larabill
2. **Instalar Spatie Package Skeleton**
3. **Configurar estructura** base del paquete
4. **Implementar modelos** y migraciones
5. **Desarrollar servicios** core
6. **Crear dashboard** de configuración
7. **Implementar PDF** generation
8. **Integrar con Stripe**
9. **Testing completo**
10. **Documentación**
11. **Deploy y monitoreo**

---

## 📝 **NOTAS IMPORTANTES**

### **Casos Fiscales Especiales**
- **España**: IVA 21%, 10%, 4%
- **Canarias**: IGIC 7%, exento de IVA
- **Ceuta/Melilla**: IPSI 0%, exento de IVA
- **UE B2B**: Reverse charge (IVA 0% + nota)
- **UE B2C**: IVA de destino con umbrales
- **Resto del mundo**: Sin IVA

### **APIs de Verificación VAT**
- **AbstractAPI**: Preferida (mejor documentación)
- **APILayer**: Fallback (100 calls límite)
- **Round-robin**: Para testing

### **Inmutabilidad**
- **Datos encriptados** para prevenir modificaciones externas
- **Marca temporal** de inmutabilidad
- **Prevención** de modificaciones en código
- **Vista de solo lectura** para administradores

### **Numeración**
- **Proformas**: PRO + número secuencial
- **Facturas**: FAC + número secuencial
- **Configurable**: Prefijos, formato, reset anual
- **Dashboard**: Gestión de numeración

### **NUEVO: Sistema ROI (Reverse Charge Operator)**
- **Cache configurable**: 15 días por defecto, 0 días para forzar API
- **Registro legal**: Todas las consultas guardadas por 7 años
- **APIs externas**: Verificación de operadores intracomunitarios
- **Fallback automático**: Entre diferentes APIs de verificación
- **Protección legal**: Registro completo de consultas para auditoría

### **NUEVO: Sistema IVA de Destino**
- **Umbral configurable**: €10,000 por defecto (año fiscal 01-01)
- **Aplicación automática**: Cuando se supera el umbral
- **Configuración por empresa**: Flexibilidad total
- **Notificaciones**: Alertas cuando se supera umbral
- **Categorización**: Productos/servicios con VAT específico

### **NUEVO: Cache Agnóstico**
- **Driver configurable**: File o Redis
- **TTL configurable**: Por tipo de cache
- **Tags para invalidación**: Cache inteligente
- **Prefix configurable**: Para evitar conflictos
- **Fallback automático**: Si Redis no disponible

### **NUEVO: Flexibilidad de Modelos**
- **Modelos personalizables**: Soporte para diferentes estructuras
- **Mapeo de campos**: Configurable por proyecto
- **Validaciones configurables**: Adaptables a cada caso
- **Soporte multi-empresa**: Configuración independiente
- **Integración simple**: Mínimo acoplamiento

---

**Última actualización:** 2025-01-03  
**Versión:** 1.1.0  
**Estado:** En desarrollo - Refactorizado con sistema ROI e IVA de destino
