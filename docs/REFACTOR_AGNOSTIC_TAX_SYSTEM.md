# Propuesta de Refactorización: Sistema Fiscal Agnóstico

## 1. Objetivos y Principios Fundamentales

El objetivo de esta refactorización es evolucionar `Larabill` hacia un sistema de facturación verdaderamente agnóstico a nivel global. Esto implica soportar múltiples tasas impositivas por ítem de factura y garantizar la inmutabilidad de los registros fiscales, independientemente de los cambios normativos futuros.

Los principios que guían esta propuesta son:

- **Separación de Responsabilidades:** Se establece una división clara entre la **Capa de Configuración Fiscal** (viva y adaptable) y la **Capa de Registros Inmutables** (histórica e inalterable).
- **Agnosticismo de Productos:** `Larabill` no gestionará productos. Su responsabilidad es proporcionar un mecanismo robusto para que la aplicación consumidora asocie sus productos a un grupo de impuestos definido.
- **Inmutabilidad Fiscal:** Cada factura y sus líneas son una "fotografía" autocontenida de la transacción en el momento en que se produjo. Los datos fiscales se almacenan directamente en el registro y no dependen de tablas de configuración externas.

## 2. Arquitectura Propuesta

### 2.1. La Capa de Configuración Fiscal

Esta capa permite al usuario de `Larabill` modelar cualquier sistema fiscal.

#### A. Nuevos Modelos de Datos

1.  **`tax_rates` (Tasas Impositivas)**
    -   **Propósito:** Define cada tasa de impuesto de forma atómica.
    -   **Schema:**
        -   `id` (PK)
        -   `name`: `string` (Ej: "IVA General", "MA State Sales Tax")
        -   `rate`: `integer` (Base-100, ej: `2100` para 21%)
        -   `region`: `string` (Ej: "ES", "US-MA")
        -   `type`: `enum('vat', 'sales_tax', 'gst', 'other')`

2.  **`tax_groups` (Grupos Impositivos)**
    -   **Propósito:** Agrupa múltiples tasas que se aplican conjuntamente.
    -   **Schema:**
        -   `id` (PK)
        -   `name`: `string` (Ej: "Servicios Digitales UE", "Venta Estándar en Boston")
        -   `description`: `text` (Opcional)

3.  **`tax_group_tax_rate` (Tabla Pivote)**
    -   **Propósito:** Asocia tasas a grupos.
    -   **Schema:**
        -   `tax_group_id` (FK a `tax_groups`)
        -   `tax_rate_id` (FK a `tax_rates`)
        -   `priority`: `integer` (Para cálculos compuestos donde el orden importa)

### 2.2. La Capa de Registros Inmutables

Esta capa garantiza que los registros de facturación sean históricamente precisos y autocontenidos.

#### A. Refactorización de `invoice_items`

-   **Columnas a Eliminar:**
    -   `tax_rate`
    -   `tax_category_id`
-   **Columnas a Añadir:**
    1.  **`total_tax_amount`**: `integer` (Base-100). Es la suma de todos los importes de impuestos para esa línea. Optimizado para agregaciones rápidas a nivel de factura.
    2.  **`taxes_applied`**: `json`. Este es el *snapshot* inmutable que almacena un desglose de cada impuesto aplicado.

**Ejemplo de la estructura de `taxes_applied`:**

```json
[
  {
    "source_rate_id": 12,
    "name": "MA State Sales Tax",
    "rate": 625,
    "amount": 625
  },
  {
    "source_rate_id": 34,
    "name": "Boston City Surcharge",
    "rate": 50,
    "amount": 50
  }
]
```

### 2.3. La Capa de Lógica: `TaxCalculationService`

Este servicio actúa como el puente entre la configuración y el registro, aplicando el **Patrón de Diseño Strategy**.

1.  **Interfaz `TaxCalculationStrategy`:** Define el contrato para cualquier sistema de cálculo de impuestos.
2.  **Implementaciones Concretas:** Se crearán clases como `VatCalculationStrategy`, `SalesTaxCalculationStrategy`, etc. Cada una contiene la lógica específica de su sistema fiscal.
3.  **Funcionamiento:** El servicio principal (`TaxCalculationService`) selecciona la estrategia correcta basándose en la configuración y le delega el cálculo. La estrategia consulta las tablas de configuración (`tax_rates`, `tax_groups`) para obtener las tasas vigentes y devuelve un objeto con el `total_tax_amount` y el `array` detallado para el campo `taxes_applied`.

## 3. Modelo de Asociación con Productos/Servicios

`Larabill` se mantiene agnóstico a la gestión de productos, pero proporciona el mecanismo de `Tax Groups` para que la aplicación que lo utiliza pueda integrarse fácilmente.

#### Flujo de Integración

1.  **Configuración en Larabill:** El administrador del sistema que usa `Larabill` define los `Tax Groups` que necesita (ej: "Productos Tasa General", "Servicios Profesionales", "Software como Servicio").
2.  **Asociación en la Aplicación del Usuario:** La aplicación del usuario debe tener una forma de asociar sus productos/servicios con un `tax_group_id` de `Larabill`. Lo más común sería añadir una columna `larabill_tax_group_id` a su tabla `products`.
3.  **Creación de la Factura:**
    -   Al añadir un ítem a una factura, la aplicación del usuario obtiene el `larabill_tax_group_id` de su producto.
    -   Pasa este `tax_group_id` a `Larabill` al crear el `InvoiceItem`.
    -   `Larabill` utiliza este ID para encontrar el grupo de impuestos, realizar el cálculo con la estrategia adecuada y almacenar el resultado inmutable en la línea de la factura.

Este modelo ofrece una integración limpia y desacoplada, cumpliendo el objetivo de agnosticismo sin sacrificar la potencia del sistema fiscal.
