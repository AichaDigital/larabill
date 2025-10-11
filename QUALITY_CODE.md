## Contexto

Se plantean seis reglas fundamentales basadas en el Laravel Starter Kit de Nuno Maduro. El desafío no es solo aplicarlas en proyectos nuevos, sino integrar esta filosofía en proyectos existentes.

## Cambio de paradigma fundamental

**Tesis central**: La flexibilidad de Laravel no debe confundirse con libertad para escribir código descuidado. La strictness no es un obstáculo, es un acelerador.

**Problema identificado**: Desarrolladores que utilizan la "magia" de Laravel como excusa para evitar tipos, tests incompletos y análisis estático.

## Análisis de los seis principios

### Principio 1: Cobertura de tipos al 100%

**Qué implica**:
```bash
pest --type-coverage --min=100
```

**Análisis crítico**:

Este principio fuerza la intencionalidad en cada variable y parámetro. La cobertura de tipos no es documentación, es contrato.

**Ventajas**:

- Reduce bugs de integración en un 60-70% según estudios empíricos
- El IDE se convierte en aliado activo
- Refactorizaciones seguras sin miedo

**Desventajas**:

- Requiere tiempo inicial significativo en proyectos legacy
- Puede generar fricción con equipos acostumbrados a PHP dinámico
- Arrays asociativos complejos necesitan DTOs o Value Objects

**Implementación gradual en proyectos existentes**:

1. Activar cobertura de tipos sin bloquear CI inicialmente
2. Establecer baseline actual
3. Regla: nuevo código debe tener 100% de cobertura
4. Refactorizar módulos críticos primero
5. Incrementar baseline mensualmente

**Relación con SOLID**:

Dependency Inversion Principle se beneficia directamente. Interfaces tipadas fuerzan contratos claros.

### Principio 2: PHPStan en nivel MAX

**Qué implica**:

Análisis estático sin concesiones. PHPStan nivel 9 (MAX) detecta:

- Dead code
- Posibles nulls no manejados
- Tipos incompatibles en operaciones
- Propiedades no inicializadas

**Análisis crítico**:

El nivel MAX expone deuda técnica oculta. No es ego, es disciplina.

**Ventajas**:

- Detecta bugs antes de testing
- Complementa cobertura de tipos
- Fuerza a pensar en edge cases

**Desventajas**:

- Output inicial puede ser abrumador en proyectos legacy
- Requiere aprendizaje de sus reglas
- Algunas reglas pueden requerir baseline temporal

**Implementación gradual**:

```bash
# Paso 1: Generar baseline
vendor/bin/phpstan analyse --generate-baseline

# Paso 2: Subir nivel progresivamente
# phpstan.neon
parameters:
    level: 5  # Empezar aquí, no en MAX
    
# Paso 3: Incrementar cada sprint
# Sprint 1: nivel 5
# Sprint 2: nivel 6
# Sprint N: nivel 9 (MAX)
```

**Relación con Testing**:

PHPStan reduce la necesidad de ciertos tests unitarios. Si el tipo system garantiza que nunca pasarás null a una función, no necesitas testearlo.

### Principio 3: Cobertura de tests al 100%

**Qué implica**:
```bash
pest --parallel --coverage --exactly=100.0
```

**Análisis crítico**:

La palabra clave es "exactly". No 99.8%. Exactamente 100%.

**Ventajas**:

- Elimina código muerto rápidamente
- Tests paralelos aceleran feedback loop
- Confianza absoluta en refactorizaciones

**Desventajas**:

- 100% coverage no garantiza calidad de tests
- Puede incentivar tests superficiales solo por métricas
- Requiere infraestructura para tests paralelos confiables

**La trampa del 100%**:

Cobertura de líneas no es cobertura de comportamiento. Un test puede ejecutar línea sin validar su lógica.

**Mejor enfoque**:

Combinar cobertura de líneas con mutation testing:
```bash
# Infection PHP
vendor/bin/infection --min-msi=80
```

**Implementación gradual**:

1. Identificar módulos críticos de negocio
2. Llevar estos a 100% primero
3. Expandir a módulos de soporte
4. Mantener 100% en código nuevo desde día 1

**Relación con SOLID**:

Single Responsibility Principle facilita testing. Clases con una responsabilidad son más fáciles de testear al 100%.

### Principio 4: Formateo estricto automático

**Qué implica**:

Laravel Pint + Prettier eliminan decisiones de estilo.

**Análisis crítico**:

Este es el principio más subestimado. No es cosmético, es cognitivo.

**Ventajas**:

- Cero tiempo en code reviews discutiendo formato
- Diffs limpios enfocados en lógica
- Onboarding más rápido

**Desventajas**:

- Puede generar diffs masivos en primera aplicación
- Requiere consenso del equipo
- Algunos desarrolladores lo perciben como pérdida de "estilo personal"

**Implementación inmediata**:

```bash
# Aplicar una sola vez
./vendor/bin/pint

# Pre-commit hook
# .git/hooks/pre-commit
./vendor/bin/pint --dirty
```

**Este principio no tiene desventajas técnicas reales**. Solo resistencia humana.

### Principio 5: Control del entorno en tests

**Qué implica**:

- Congelar tiempo
- Fake HTTP calls
- Forzar HTTPS en tests

**Análisis crítico**:

Tests deben ser deterministas. El mundo exterior es no determinista.

**Ventajas en Laravel**:
```php
// Determinismo temporal
Carbon::setTestNow('2025-01-15 10:00:00');

// Aislamiento de HTTP
Http::fake([
    'api.external.com/*' => Http::response(['data' => 'fake'], 200)
]);
```

**Desventajas**:

- Requiere disciplina para identificar qué fakear
- Tests de integración reales siguen siendo necesarios
- Puede ocultar problemas de configuración real

**Implementación**:

Separar claramente:

- Unit tests: todo fakeado
- Integration tests: servicios reales en ambiente controlado
- E2E tests: flujo completo

**Relación con Testing**:

Este principio hace posible el 100% de cobertura. Sin él, tests son frágiles y lentos.

### Principio 6: CI como senior engineer más estricto

**Qué implica**:

GitHub Actions ejecutando:

- Pint (formato)
- Rector (refactoring automático)
- PHPStan (análisis estático)
- Pest (tests)
- Lint (sintaxis)

**Análisis crítico**:

CI no es opcional. Es el guardián de calidad 24/7.

**Ventajas**:

- Feedback inmediato en PRs
- Imposible mergear código que rompe estándares
- Documentación viva de estándares del equipo

**Desventajas**:

- Requiere configuración inicial
- Puede ralentizar merges si CI es lento
- Falsos positivos pueden frustrar

**Implementación óptima**:

```yaml
# .github/workflows/tests.yml
name: Tests

on: [push, pull_request]

jobs:
  tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.3
          coverage: xdebug
      
      - name: Install Dependencies
        run: composer install
      
      - name: Run Pint
        run: ./vendor/bin/pint --test
      
      - name: Run PHPStan
        run: ./vendor/bin/phpstan analyse
      
      - name: Run Pest
        run: ./vendor/bin/pest --parallel --coverage --min=100
```

**Estrategia gradual**:

1. Empezar solo con tests
2. Agregar Pint
3. Agregar PHPStan con baseline
4. Subir nivel progresivamente

## El error fundamental identificado

**Cita clave**: "Confundir flexibilidad con libertad"

Laravel es flexible en arquitectura, no en disciplina. La flexibilidad es para resolver problemas de negocio, no para evitar buenas prácticas.

### Análisis del error

**Manifestaciones comunes**:

- Arrays asociativos en lugar de DTOs
- Métodos sin type hints "porque total funciona"
- Tests solo de happy path
- "Lo arreglo después" que nunca llega

**Costo real**:

- Debugging que consume 40-60% del tiempo de desarrollo
- Bugs en producción que dañan reputación
- Refactorizaciones imposibles sin miedo
- Onboarding lento de nuevos desarrolladores

## Roadmap de implementación en proyectos existentes

### Fase 1: Evaluación y baseline (Sprint 1-2)

**Acciones**:

1. Ejecutar PHPStan nivel 5 con baseline
2. Medir cobertura de tipos actual
3. Medir cobertura de tests actual
4. Aplicar Pint una sola vez
5. Documentar estado actual

**Entregable**: Documento con métricas actuales y brechas.

### Fase 2: Quick wins (Sprint 3-4)

**Acciones**:

1. Configurar Pint en pre-commit hook
2. Implementar CI básico (tests + Pint)
3. Nueva regla: código nuevo debe cumplir 100% tipos y tests
4. Identificar 3 módulos críticos para refactorizar

**Entregable**: CI funcionando, código nuevo bajo estándares.

### Fase 3: Refactoring progresivo (Sprint 5-12)

**Acciones**:

1. Refactorizar módulos críticos a 100% tipos y tests
2. Subir PHPStan un nivel cada 2 sprints
3. Reducir baseline de PHPStan 10% cada sprint
4. Code reviews enfocados en principios

**Entregable**: Módulos críticos bajo estándares, PHPStan nivel 7-8.

### Fase 4: Consolidación (Sprint 13+)

**Acciones**:

1. PHPStan nivel MAX sin baseline
2. 100% cobertura en todo el proyecto
3. Mutation testing implementado
4. Documentación de arquitectura

**Entregable**: Proyecto completamente bajo estándares.

## Comparación: Enfoque tradicional vs Good Code

| Aspecto | Tradicional | Good Code |
|---------|-------------|-----------|
| Types | Opcional | Obligatorio 100% |
| Tests | "Lo importante" | 100% exacto |
| Análisis estático | Si da tiempo | PHPStan MAX |
| Formato | Discutido en CR | Automatizado |
| CI | Tests básicos | Guardián completo |
| Velocidad inicial | Rápida | Moderada |
| Velocidad sostenida | Decreciente | Creciente |
| Confianza deployment | Rezar | Saber |

## Relación con SOLID

### Single Responsibility Principle
Clases con una responsabilidad son más fáciles de tipar y testear al 100%.

### Open/Closed Principle
Interfaces tipadas garantizan extensión sin modificación.

### Liskov Substitution Principle
PHPStan MAX detecta violaciones automáticamente.

### Interface Segregation Principle
Cobertura de tipos fuerza interfaces específicas.

### Dependency Inversion Principle
Type hints en constructores formalizan inversión de dependencias.

## Consideraciones finales

### Pregunta clave

¿Es esto overkill para proyectos pequeños?

**Respuesta**: Depende del horizonte temporal. Si el proyecto vivirá más de 6 meses, no es overkill. Es inversión.

### Obstáculos reales

1. **Resistencia del equipo**: Cambio cultural requiere tiempo
2. **Tiempo inicial**: Primera implementación consume sprints
3. **Falsa sensación de lentitud**: Strictness se siente lenta hasta que debuggear se vuelve raro

### Beneficios medibles

- Reducción de bugs en producción: 60-80%
- Tiempo de onboarding: -50%
- Confianza en refactoring: +200%
- Velocidad de features (después de 3 meses): +30%

## Conclusión

La filosofía de Good Code no es una lista de herramientas. Es un cambio de mentalidad: de "funciona ahora" a "funciona siempre".

El starter kit de Nuno Maduro no es el objetivo. Es el ejemplo de que es posible. La meta es internalizar estos principios hasta que escribir código sin tipos, sin tests o sin análisis estático se sienta antinatural.

**Tesis final**: Strictness no es burocracia. Es la única forma conocida de escalar complejidad sin colapsar en caos.

---

## Recursos

- [Laravel Starter Kit de Nuno Maduro](https://github.com/nunomaduro/laravel-starter-kit)
- [PHPStan Documentation](https://phpstan.org/user-guide/getting-started)
- [Pest PHP Documentation](https://pestphp.com/docs/installation)
- [Laravel Pint Documentation](https://laravel.com/docs/master/pint)
- [Principios SOLID aplicados a Laravel - Opinated](https://martinjoo.dev/solid-principles-with-laravel)
- [Agradecimientos a Vishal Rajpurohit por su hilo conductor](https://x.com/vishal_viitor/status/1976507014151860708)



##### Aviso
Esta documentación y su contenido, no implica que funcione en tu caso o determinados casos. 
También implica que tienes conocimientos sobre lo que trata, y que en cualquier caso tienes copias de seguridad.
El contenido el contenido se entrega, tal y como está, sin que ello implique ningún obligación ni responsabilidad por parte de [Castris](https://castris.com)

Si necesitas soporte profesional puedes contratar con Castris [soporte profesional](https://intranet.castris.com/store/soporte-profesional).
