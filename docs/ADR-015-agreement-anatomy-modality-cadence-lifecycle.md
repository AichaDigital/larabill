# ADR-015 — Anatomía del acuerdo: modalidad, cadencia como modelo, ciclo de vida con causas y garantía

- **Estado:** **Accepted — decisión tomada, código PENDIENTE. Ejecutan AID-953, AID-949 y AID-952 (una major).**
- **Fecha:** 2026-10-06
- **Decide:** Abdelkarim Mateos
- **Contexto de origen:** tres rondas de decisión de dominio con el operador (2026-10-06), provocadas por el corpus real de WHMCS que migra en AID-895 (`castris/clientes`): 339 servicios sin equivalencia de estado, 25 contrataciones sin destino y 15 dominios con cadencia no representable. Síntesis completa y acta de las rondas: `~/claude/informes/2026-10-06-larabill-modelo-acuerdos-diseno-cerrado.md` (y documentos `-preguntas-*.md` hermanos).
- **Construye sobre:** ADR-004 (precios por frecuencia), ADR-013 (`effective_price` es estado contractual), ADR-014 (artefactos de corrección y liquidación).
- **Supersede parcialmente:** el diseño enum-cerrado de `ServiceStatus` (5 casos) y `BillingFrequency` (10 casos). No supersede ADR-004 — lo reformula sobre el nuevo modelo de cadencia.

- **Enmienda 1 (2026-10-06, Abdelkarim Mateos):** tras el gate adversarial ronda 1 (veredicto: método de esquema/datos correcto, método de release bloqueado por `STABILITY.md:26-32`), el propietario elige **honrar la ventana de deprecación**. La 7.0 mantiene los símbolos públicos retirados como **shims deprecados** mapeados al modelo nuevo — superficie de compatibilidad, no segundo motor: el código interno y el import usan exclusivamente el modelo nuevo — y la 8.0 los retira. Esto enmienda §8 («sin convertidor ni capa de compatibilidad de código») y precisa §3: la «no convivencia» gobierna el MOTOR (nunca dos lógicas vivas), no la superficie pública deprecada.

> ⚠️ **Este ADR describe la decisión, NO el código actual.** Hasta que los tickets se ejecuten, `ServiceStatus`, `BillingFrequency`, `CancellationType` y los dos `cancel()` **siguen vivos en `src/` tal como están**. Ninguna sesión debe leer este documento como descripción del comportamiento vigente. Mismo precedente que ADR-014: un ADR aceptado sin implementar es correcto solo si dice en voz alta que el código aún no ha llegado.
>
> **Registro de major:** la ejecución es breaking y exige abrir el registro central de gobierno (`approvals/majors/`, hoy vacío) antes de taguear — requisito del corpus del paraguas. El histórico «v7 fue CANCELADO» no aplica a esta línea: es un major nuevo con imperativo de uso cualificado y medido (el corpus real de AID-895).

## 1. El problema

El modelo de acuerdos se diseñó para lo recurrente de hosting y quedó corto frente al corpus real que hay que importar:

- **`ServiceStatus` no sabe por qué algo terminó.** `CANCELLED` exige un `CancellationType` que el importador no tiene; los 339 servicios Terminated/Completed/Fraud de WHMCS no tienen equivalencia veraz.
- **`BillingFrequency` no sabe decir «cada 5 años».** El enum cerró el modelo a cantidad + unidad; 15 dominios reales no son representables, y aproximarlos declara obligaciones que no existen.
- **No existe el concepto de modalidad.** Pago único y no facturable (patrocinio) no tienen destino: la tabla está concebida para lo recurrente y `ONE_TIME` solo es representable como precio.

## 2. Decisión — modalidad (AID-953)

- **Todo servicio contratado genera fila de acuerdo**, sea cual sea su modalidad. La modalidad es un **atributo** de la fila, no un estado.
- **Triada de modalidad** (conjunto cerrado): **recurrente** / **pago único** / **no facturable**.
- **Renovación, segundo atributo** (conjunto cerrado, agnóstico al hosting): **auto-renovable** (hosting, dominios, alquiler — se renueva salvo baja) frente a **contrato cerrado** (renting, leasing — al terminar, termina). El motor no renueva lo que no tiene renovación implícita; el cierre automático por `expires_at` ya existente lo cierra. La opción de compra es **otro contrato** y queda fuera de alcance.
- **Pago único:** se factura una vez al contratar; el registro queda sin cadencia. **Ampliable a petición del cliente** («me quedo un año más»): periodo nuevo dentro del mismo acuerdo + nueva factura de pago único; la modalidad no cambia.

## 3. Decisión — cadencia como modelo (AID-952)

- **La cadencia es un modelo propio, no un enum:** tipo de temporalidad (mes, año) + cantidad (1–12 meses, 1–10 años). «Mensual» = 1 mes; «trimestral» = 3 meses; «bienal» = 2 años.
- **`BillingFrequency` se retira sin convivencia** mientras migra. larabill posee el modelo; la importación se acopla a larabill, nunca al revés.
- **Los precios se definen por temporalidad** (`article_prices`) con su modelo propio: tocar esto es tocar precios y acuerdos a la vez.
- **Ruta:** la correcta, ni la lenta ni la rápida. Cambio de esquema en paquete estable ⇒ **major** con migraciones que traten los datos existentes (misma regla dura de AID-398).

## 4. Decisión — ciclo de vida con causas (AID-949)

- **Estados (cinco, conjunto cerrado):**

| Estado | Significado | Viene de |
| -- | -- | -- |
| `PENDING` | Contratado, pendiente de aprovisionar | Ya existía — estado de servicio, no de pago |
| `ACTIVE` | En servicio | `ACTIVE` actual |
| `SUSPENDED` | Suspendido — **reversible** (pagas y vuelves a Alta) | `SUSPENDED` actual |
| `GUARANTEE` | Cerrado + no renovable + dinero por devolver | Nuevo (§5) |
| `CLOSED` | Terminal no facturable | `CANCELLED` + `EXPIRED` unificados |

- **La causa es un modelo propio — tabla-vocabulario que crece sin tocar código —, no un enum.** Colgada del estado:
  - **`CLOSED` (Baja):** impago (el «Terminado» de WHMCS) / petición del cliente (el «Cancelado») / cumplimiento (el «Completado») / fraude / rescisión del proveedor.
  - **`SUSPENDED`:** impago / hacking / otras.
- **El pago o impago es estado contable, separado del estado de servicio.** Son dos matices con obligaciones formales y legales distintas que WHMCS mezclaba; larabill no los mezcla.
- **El cierre automático por fecha** (`expires_at` + proceso existente) pasa a ser `CLOSED` + causa cumplimiento.
- **Suspendido prolongado** → `CLOSED` definitivo con eliminación de datos (territorio lara-privacy).
- **Para el import:** la causa **no es obligatoria** — basta registrar el fin del servicio; la narrativa de lo ocurrido va en notas del pedido.
- Los dos `cancel()` actuales y su política comercial implícita siguen el camino ya trazado por ADR-014 / AID-971; este ADR no consolida su divergencia — la ejecución de AID-971 es prerequisito natural del eje de cierre.

## 5. Decisión — garantía

- **Capacidad por producto, configurable:** qué productos tienen ventana de garantía (ej. 30 días) y cuáles no. Los dominios, el caso caro de exclusión; los VPS con aprovisionamiento y aceptación expresa, excluidos del desistimiento por legislación europea.
- **Cancelación dentro de la ventana** → estado `GUARANTEE` (no activo, no renovable) + **factura rectificativa (abono)** de lo pagado — el artefacto lo define ADR-014.
- **`GUARANTEE` es transitorio:** cuando el dinero vuelve, pasa a `CLOSED` con su causa, y la devolución queda registrada (fecha, importe). Terminal no sería: la obligación de devolver quedaría sin forma de marcarse como cumplida.
- **Registro de operaciones de garantía** — tabla propia, para que el consumidor aplique su política de cliente («quien usa la garantía, no vuelve»). larabill registra; la política de cliente es de la app.

## 6. Decisión — cambio de plan

Resuelto en este diseño, sin ticket propio: **política configurable por la empresa** (variable de configuración al menos en las primeras versiones).

- **Opción A:** fin del contrato actual con abono de lo no consumido + contrato nuevo por el nuevo plan, periodo completo desde el cambio.
- **Opción B:** igual, pero el contrato nuevo dura solo hasta la fecha de fin del anterior (precio calculado).
- El abono de lo no consumido es **liquidación técnica** (ADR-014), **no** garantía — caminos distintos.

## 7. Decisión — import WHMCS (AID-895)

- Estados finales de WHMCS → `CLOSED`, con causa cuando se sepa; notas del pedido para trazabilidad.
- **Facturas cero de patrocinios:** se importan como factura a 0 con línea de trazabilidad («error, procedente de WHMCS»), en serie no fiscal. El hueco de numeración sería válido (ya contabilizado), pero se prefiere incluir la factura.
- **Todo documento importado lleva marca `is_historical`**, además de la metadata namespaced con la procedencia.
- **VeriFACTU fuera de la conversación:** obligación legal en 2028; el histórico es anterior.

## 8. Decisión — versión y consumidores

- Cambios mayores ⇒ **major**. **Sin convertidor ni capa de compatibilidad de código; con migraciones de esquema y datos** que traten lo ya guardado (convertidor ≠ migración).
- El README lo documenta sin brutalidad: quien consuma el paquete se acopla a la nueva versión o sigue por su lado con un fork.

## 9. Qué NO decide este ADR

La micro-implementación: nombres exactos de columnas y tablas, forma concreta del modelo de cadencia (`unit` + `quantity` vs otros), el shape del vocabulario de causas, y el reparto de tareas entre los tres tickets. Eso es la spec — en inglés, según convención del repo — y su gate adversarial previo al TDD.
