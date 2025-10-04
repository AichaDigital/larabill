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
- [x] **TaxRate.php**
  - [x] Campos: country_code, country_name, tax_name, tax_type, rate
  - [x] Campos: is_active, applies_to, special_conditions
  - [x] Método estático getSpanishRates()
  - [x] Método estático getEURates()
  - [x] Scope para países activos
- [x] **VatVerification.php**
  - [x] Campos: vat_number, country_code, is_valid, company_name
  - [x] Campos: address, verification_date, api_used, response_data
  - [x] Cast response_data como array
  - [x] Scope para verificaciones válidas

### 1.2.1 Modelos ROI (Reverse Charge Operator) - NUEVO
- [x] **UserRoiVerification.php**
  - [x] Campos: user_id, vat_number, country_code, is_roi
  - [x] Campos: company_name, company_address, last_check, expired_at
  - [x] Campos: api_source, response_data, cache_hit
  - [x] Método isExpired() - verificar si el cache expiró
  - [x] Método isValid() - verificar si es ROI válido
  - [x] Scope valid(), expired(), byCountry()
  - [x] Relación belongsTo User (configurable)
- [x] **RoiQuery.php**
  - [x] Campos: user_id, vat_number, country_code, query_type
  - [x] Campos: api_source, response_data, queried_at, cache_used
  - [x] Campos: legal_retention_until (7 años)
  - [x] Scope byDateRange(), byQueryType(), legalRetention()
  - [x] Relación belongsTo User (configurable)

### 1.2.2 Modelos IVA de Destino - NUEVO
- [x] **CompanyFiscalConfig.php**
  - [x] Campos: company_id, apply_destination_iva, eu_sales_threshold
  - [x] Campos: current_eu_sales_amount, threshold_exceeded_at
  - [x] Campos: fiscal_year, auto_apply_destination, notification_sent
  - [x] Método checkThreshold() - verificar si se supera umbral
  - [x] Método updateEuSales() - actualizar ventas intracomunitarias
  - [x] Scope byFiscalYear(), thresholdExceeded()
- [x] **VatCategory.php**
  - [x] Campos: name, description, country_code, vat_rate
  - [x] Campos: category_type, is_active, applies_to_products
  - [x] Campos: special_conditions, last_updated
  - [x] Método estático getByCountry()
  - [x] Método estático getByCategoryType()
  - [x] Scope active(), byCountry(), byCategoryType()
- [x] **EuSalesThreshold.php**
  - [x] Campos: company_id, fiscal_year, total_amount
  - [x] Campos: threshold_exceeded, exceeded_at, notification_sent
  - [x] Campos: breakdown_by_country (JSON)
  - [x] Método calculateTotal() - calcular total de ventas
  - [x] Método checkThreshold() - verificar umbral
  - [x] Scope byFiscalYear(), exceeded(), byCompany()
- [x] **CountryVatRate.php**
  - [x] Campos: country_code, country_name, standard_rate
  - [x] Campos: reduced_rates (JSON), exempt_categories (JSON)
  - [x] Campos: last_updated, data_source, is_active
  - [x] Método getRateForCategory() - obtener tasa por categoría
  - [x] Método getReducedRates() - obtener tasas reducidas
  - [x] Scope active(), byCountry(), byRate()

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
- [x] **NUEVO: create_user_roi_verifications_table.php**
  - [x] Índice único para user_id + vat_number + country_code
  - [x] Índice para expired_at
  - [x] Índice para last_check
  - [x] Constraint para expired_at > last_check
- [x] **NUEVO: create_roi_queries_table.php**
  - [x] Índice para user_id + queried_at
  - [x] Índice para query_type
  - [x] Índice para legal_retention_until
  - [x] Constraint para legal_retention_until > queried_at
- [x] **NUEVO: create_company_fiscal_configs_table.php**
  - [x] Índice único para company_id + fiscal_year
  - [x] Índice para threshold_exceeded_at
  - [x] Índice para apply_destination_iva
- [x] **NUEVO: create_vat_categories_table.php**
  - [x] Índice único para country_code + name
  - [x] Índice para category_type
  - [x] Índice para is_active
- [x] **NUEVO: create_eu_sales_thresholds_table.php**
  - [x] Índice único para company_id + fiscal_year
  - [x] Índice para threshold_exceeded
  - [x] Índice para exceeded_at
- [x] **NUEVO: create_country_vat_rates_table.php**
  - [x] Índice único para country_code
  - [x] Índice para is_active
  - [x] Índice para last_updated
- [x] **Seeders**
  - [x] TaxRatesSeeder con datos españoles
  - [x] TaxRatesSeeder con datos europeos
  - [x] TaxRatesSeeder con datos mundiales básicos
  - [x] **NUEVO: CountryVatRatesSeeder** con datos de rate_list_eu.json
  - [x] **NUEVO: VatCategoriesSeeder** con categorías estándar

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
- [x] **Configuración de Cache Agnóstico**
  - [x] Driver configurable (file/redis)
  - [x] TTL configurable (15 días por defecto)
  - [x] Prefix para cache keys
  - [x] Tags para invalidación
- [x] **Método verifyRoiStatus()**
  - [x] Verificar cache primero
  - [x] Consultar API si cache expirado
  - [x] Guardar en UserRoiVerification
  - [x] Registrar en RoiQuery
  - [x] Retornar resultado con metadata
- [x] **Método isRoiValid()**
  - [x] Verificar si es ROI válido
  - [x] Considerar expiración de cache
  - [x] Forzar verificación si es necesario
- [x] **Métodos privados**
  - [x] getCachedRoiVerification()
  - [x] cacheRoiVerification()
  - [x] logRoiQuery()
  - [x] isCacheExpired()

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
- [x] **Gestión de Umbrales**
  - [x] Verificar umbral actual de ventas intracomunitarias
  - [x] Calcular total de ventas por año fiscal
  - [x] Detectar superación de umbral (€10,000)
  - [x] Notificar cambio de régimen fiscal
- [x] **Aplicación de IVA de Destino**
  - [x] Determinar si aplicar IVA de destino
  - [x] Obtener tasa VAT del país de destino
  - [x] Calcular IVA según categoría de producto
  - [x] Generar notas fiscales específicas
- [x] **Configuración por Empresa**
  - [x] Permitir configuración flexible por empresa
  - [x] Soporte para diferentes umbrales por empresa
  - [x] Configuración de año fiscal personalizado
  - [x] Notificaciones configurables
- [x] **Métodos privados**
  - [x] calculateEuSalesTotal()
  - [x] checkThresholdExceeded()
  - [x] getDestinationVatRate()
  - [x] generateFiscalNotes()

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
- [x] **Gestión de Configuración Fiscal**
  - [x] Crear/actualizar configuración fiscal por empresa
  - [x] Gestionar umbrales personalizados
  - [x] Configurar año fiscal personalizado
  - [x] Activar/desactivar IVA de destino
- [x] **Flexibilidad de Modelos**
  - [x] Permitir modelos de empresa personalizados
  - [x] Mapeo de campos configurables
  - [x] Soporte para diferentes estructuras de datos
  - [x] Validaciones configurables
- [x] **Métodos privados**
  - [x] getCompanyModel()
  - [x] mapCompanyFields()
  - [x] validateCompanyConfig()
  - [x] updateFiscalSettings()

---

## 📋 **FASE 3: GENERACIÓN PDF** [1 día]

### 3.1 PDFGenerator Service
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

### 3.2 Integración con Facturas
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

## 📋 **FASE 4: INTEGRACIÓN CON STRIPE** [1 día]

### 4.1 Webhook Integration
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

### 4.2 Payment Integration
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

## 📋 **FASE 5: TESTING COMPLETO** [1 día]

### 5.1 Tests Unitarios
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
- [x] **NUEVO: RoiVerificationServiceTest**
  - [x] Verificación ROI exitosa
  - [x] Cache con expiración
  - [x] Fallback entre APIs
  - [x] Registro de consultas legales
  - [x] Manejo de errores
- [x] **NUEVO: DestinationVatServiceTest**
  - [x] Cálculo de umbrales intracomunitarios
  - [x] Aplicación de IVA de destino
  - [x] Notificaciones de umbral
  - [x] Configuración por empresa
  - [x] Categorización de productos
- [x] **NUEVO: CompanyConfigServiceTest**
  - [x] Configuración fiscal flexible
  - [x] Mapeo de modelos personalizados
  - [x] Validaciones configurables
  - [x] Gestión de umbrales
  - [x] Soporte multi-empresa

### 5.2 Tests de Integración
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
- [x] **NUEVO: RoiVerificationIntegrationTest**
  - [x] Flujo completo de verificación ROI
  - [x] Cache con expiración
  - [x] Registro de consultas legales
  - [x] Integración con APIs externas
  - [x] Manejo de errores y fallbacks
- [x] **NUEVO: DestinationVatIntegrationTest**
  - [x] Flujo completo de IVA de destino
  - [x] Cálculo de umbrales
  - [x] Aplicación automática de IVA
  - [x] Notificaciones de umbral
  - [x] Configuración por empresa
- [x] **NUEVO: CompanyConfigIntegrationTest**
  - [x] Configuración fiscal completa
  - [x] Mapeo de modelos personalizados
  - [x] Validaciones configurables
  - [x] Soporte multi-empresa
  - [x] Integración con servicios

### 5.3 Tests de Feature
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
- [x] **NUEVO: RoiVerificationFeatureTest**
  - [x] Verificación ROI desde interfaz
  - [x] Cache y expiración
  - [x] Registro de consultas
  - [x] Manejo de errores
  - [x] Notificaciones de estado
- [x] **NUEVO: DestinationVatFeatureTest**
  - [x] Gestión de umbrales desde interfaz
  - [x] Aplicación de IVA de destino
  - [x] Notificaciones de umbral
  - [x] Configuración por empresa
  - [x] Categorización de productos
- [x] **NUEVO: CompanyConfigFeatureTest**
  - [x] Configuración fiscal desde interfaz
  - [x] Mapeo de modelos personalizados
  - [x] Validaciones configurables
  - [x] Soporte multi-empresa
  - [x] Gestión de umbrales

---

## 📋 **FASE 6: DOCUMENTACIÓN Y DEPLOY** [1 día]

### 6.1 Documentación
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

### 6.2 Deploy y Testing
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

1. ✅ **Crear repositorio** AichaDigital/Larabill
2. ✅ **Instalar Spatie Package Skeleton**
3. ✅ **Configurar estructura** base del paquete
4. ✅ **Implementar modelos** y migraciones
5. ✅ **Desarrollar servicios** core
6. ✅ **Testing completo** (301 tests pasando)
7. **Implementar PDF** generation
8. **Integrar con Stripe**
9. **Documentación**
10. **Deploy y monitoreo**

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

**Última actualización:** 2025-01-04  
**Versión:** 1.2.0  
**Estado:** ✅ COMPLETADO - FASE 1, 2 y 5 + SIGUIENTE: FASE 3 (PDF)
