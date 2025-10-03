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
  - [x] Campos: tax_rate, tax_amount, total
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
- [ ] **VatVerification.php**
  - [ ] Campos: vat_number, country_code, is_valid, company_name
  - [ ] Campos: address, verification_date, api_used, response_data
  - [ ] Cast response_data como array
  - [ ] Scope para verificaciones válidas

### 1.3 Configuración Agnóstica
- [ ] **config/larabill.php**
  - [ ] Configuración de modelos (User, UserTaxInfo, Invoice, etc.)
  - [ ] Mapeo de campos fiscales configurables
  - [ ] Configuración de APIs (VAT verification)
  - [ ] Configuración de numeración
  - [ ] Configuración de inmutabilidad
  - [ ] Configuración de PDF
  - [ ] Configuración de emails
  - [ ] Configuración de impuestos por defecto

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
- [ ] **create_vat_verifications_table.php**
  - [ ] Índice único para vat_number + country_code
  - [ ] Índice para verification_date
- [ ] **Seeders**
  - [ ] TaxRatesSeeder con datos españoles
  - [ ] TaxRatesSeeder con datos europeos
  - [ ] TaxRatesSeeder con datos mundiales básicos

---

## 📋 **FASE 2: SERVICIOS CORE** [2 días]

### 2.1 VatVerificationService
- [x] **Configuración de APIs** (básico)
  - [x] AbstractAPI (preferida) - https://vat.abstractapi.com
  - [x] APILayer (fallback) - http://apilayer.net
  - [ ] Round-robin para testing (100 calls límite)
- [x] **Método verifyVatNumber()** (básico)
  - [ ] Cache de verificaciones (30 días)
  - [ ] Fallback entre APIs
  - [ ] Logging de errores
  - [ ] Rate limiting
- [ ] **Métodos privados**
  - [ ] tryAbstractApi()
  - [ ] tryApiLayer()
  - [ ] parseApiResponse()
- [x] **Configuración en billing.php**
  - [x] API keys
  - [x] API preferida
  - [ ] Límites de rate
  - [ ] Timeout configurables

### 2.2 TaxCalculationService
- [x] **Método calculateTax()** (básico)
  - [x] Parámetros: subtotal, customerCountry, customerType, vatVerification
  - [x] Retorna: total_tax, tax_breakdown, special_conditions, invoice_notes
- [x] **Métodos específicos por región** (básico)
  - [x] calculateSpanishTax() - IVA estándar (21%, 10%, 4%)
  - [ ] calculateSpecialSpanishTax() - Canarias (IGIC), Ceuta/Melilla (IPSI)
  - [x] calculateEUTax() - Intracomunitario con reverse charge
  - [ ] calculateWorldwideTax() - Resto del mundo (sin IVA)
- [x] **Lógica de reverse charge** (básico)
  - [ ] Verificación de ROI (Registro de Operadores Intracomunitarios)
  - [ ] Aplicación de IVA de destino
  - [ ] Notas fiscales específicas
- [x] **Casos especiales** (básico)
  - [ ] Canarias: IGIC 7%, exento de IVA español
  - [ ] Ceuta/Melilla: IPSI 0%, exento de IVA español
  - [x] UE B2B: Reverse charge (IVA 0% + nota)
  - [ ] UE B2C: IVA de destino
  - [ ] EEUU: Sales Tax (configurable por estado)

### 2.3 BillingService
- [x] **Método createInvoice()** (básico)
  - [ ] Verificación VAT si es necesario
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
- **UE B2C**: IVA de destino
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

---

**Última actualización:** 2024-12-01  
**Versión:** 1.0.0  
**Estado:** En desarrollo
