<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LegalEntityTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $entityTypes = [
            // Personas Físicas
            [
                'code'            => 'PERSONA_FISICA',
                'name'            => 'Persona Física',
                'name_en'         => 'Individual/Natural Person',
                'abbreviation'    => null,
                'country_code'    => 'ES',
                'description'     => 'Persona física que realiza actividades económicas o profesionales',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 10,
                'metadata'        => json_encode([
                    'tax_id_format'  => 'NIF (DNI o NIE)',
                    'tax_id_pattern' => '/^[0-9]{8}[A-Z]$|^[XYZ][0-9]{7}[A-Z]$/',
                ]),
            ],

            // Sociedades Mercantiles
            [
                'code'            => 'SOCIEDAD_LIMITADA',
                'name'            => 'Sociedad de Responsabilidad Limitada',
                'name_en'         => 'Limited Liability Company',
                'abbreviation'    => 'SL',
                'country_code'    => 'ES',
                'description'     => 'Sociedad mercantil de capital con responsabilidad limitada al capital aportado',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 20,
                'metadata'        => json_encode([
                    'tax_id_format'  => 'CIF',
                    'tax_id_pattern' => '/^[A-HJ-NP-SUVW][0-9]{7}[0-9A-J]$/',
                    'capital_minimo' => 3000,
                ]),
            ],
            [
                'code'            => 'SOCIEDAD_ANONIMA',
                'name'            => 'Sociedad Anónima',
                'name_en'         => 'Public Limited Company',
                'abbreviation'    => 'SA',
                'country_code'    => 'ES',
                'description'     => 'Sociedad mercantil de capital con acciones libremente transmisibles',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 30,
                'metadata'        => json_encode([
                    'tax_id_format'  => 'CIF',
                    'tax_id_pattern' => '/^[A-HJ-NP-SUVW][0-9]{7}[0-9A-J]$/',
                    'capital_minimo' => 60000,
                ]),
            ],
            [
                'code'            => 'SOCIEDAD_LIMITADA_NUEVA_EMPRESA',
                'name'            => 'Sociedad Limitada Nueva Empresa',
                'name_en'         => 'New Enterprise Limited Company',
                'abbreviation'    => 'SLNE',
                'country_code'    => 'ES',
                'description'     => 'Variante de SL con constitución simplificada para emprendedores',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 40,
                'metadata'        => json_encode([
                    'tax_id_format'  => 'CIF',
                    'tax_id_pattern' => '/^[A-HJ-NP-SUVW][0-9]{7}[0-9A-J]$/',
                    'capital_minimo' => 3000,
                    'capital_maximo' => 120000,
                    'socios_maximo'  => 5,
                ]),
            ],
            [
                'code'            => 'SOCIEDAD_LIMITADA_LABORAL',
                'name'            => 'Sociedad Limitada Laboral',
                'name_en'         => 'Labor Limited Company',
                'abbreviation'    => 'SLL',
                'country_code'    => 'ES',
                'description'     => 'Sociedad de economía social donde mayoría del capital es de trabajadores',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 50,
                'metadata'        => json_encode([
                    'tax_id_format'  => 'CIF',
                    'tax_id_pattern' => '/^[A-HJ-NP-SUVW][0-9]{7}[0-9A-J]$/',
                    'capital_minimo' => 3000,
                ]),
            ],

            // Sociedades Colectivas y Comanditarias
            [
                'code'            => 'SOCIEDAD_COLECTIVA',
                'name'            => 'Sociedad Colectiva',
                'name_en'         => 'General Partnership',
                'abbreviation'    => 'SC',
                'country_code'    => 'ES',
                'description'     => 'Sociedad personalista con responsabilidad ilimitada y solidaria',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 60,
                'metadata'        => json_encode([
                    'tax_id_format'   => 'CIF',
                    'tax_id_pattern'  => '/^[A-HJ-NP-SUVW][0-9]{7}[0-9A-J]$/',
                    'responsabilidad' => 'ilimitada',
                ]),
            ],
            [
                'code'            => 'SOCIEDAD_COMANDITARIA_SIMPLE',
                'name'            => 'Sociedad Comanditaria Simple',
                'name_en'         => 'Limited Partnership',
                'abbreviation'    => 'SComS',
                'country_code'    => 'ES',
                'description'     => 'Sociedad con socios colectivos (resp. ilimitada) y comanditarios (resp. limitada)',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 70,
                'metadata'        => json_encode([
                    'tax_id_format'  => 'CIF',
                    'tax_id_pattern' => '/^[A-HJ-NP-SUVW][0-9]{7}[0-9A-J]$/',
                ]),
            ],
            [
                'code'            => 'SOCIEDAD_COMANDITARIA_ACCIONES',
                'name'            => 'Sociedad Comanditaria por Acciones',
                'name_en'         => 'Partnership Limited by Shares',
                'abbreviation'    => 'SComA',
                'country_code'    => 'ES',
                'description'     => 'Sociedad comanditaria donde el capital se divide en acciones',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 80,
                'metadata'        => json_encode([
                    'tax_id_format'  => 'CIF',
                    'tax_id_pattern' => '/^[A-HJ-NP-SUVW][0-9]{7}[0-9A-J]$/',
                    'capital_minimo' => 60000,
                ]),
            ],

            // Cooperativas y Economía Social
            [
                'code'            => 'COOPERATIVA',
                'name'            => 'Sociedad Cooperativa',
                'name_en'         => 'Cooperative Society',
                'abbreviation'    => 'COOP',
                'country_code'    => 'ES',
                'description'     => 'Sociedad de economía social basada en principios cooperativos',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 90,
                'metadata'        => json_encode([
                    'tax_id_format'  => 'CIF',
                    'tax_id_pattern' => '/^[FG][0-9]{7}[0-9A-J]$/',
                    'socios_minimo'  => 3,
                ]),
            ],
            [
                'code'            => 'SOCIEDAD_AGRARIA_TRANSFORMACION',
                'name'            => 'Sociedad Agraria de Transformación',
                'name_en'         => 'Agricultural Transformation Society',
                'abbreviation'    => 'SAT',
                'country_code'    => 'ES',
                'description'     => 'Sociedad civil dedicada a actividades agrarias',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 100,
                'metadata'        => json_encode([
                    'tax_id_format'  => 'CIF',
                    'tax_id_pattern' => '/^[FG][0-9]{7}[0-9A-J]$/',
                    'socios_minimo'  => 3,
                ]),
            ],

            // Entidades Sin Ánimo de Lucro
            [
                'code'            => 'ASOCIACION',
                'name'            => 'Asociación',
                'name_en'         => 'Association',
                'abbreviation'    => null,
                'country_code'    => 'ES',
                'description'     => 'Entidad sin ánimo de lucro con fines sociales, culturales, deportivos, etc.',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 110,
                'metadata'        => json_encode([
                    'tax_id_format'  => 'CIF',
                    'tax_id_pattern' => '/^[G][0-9]{7}[0-9A-J]$/',
                    'animo_lucro'    => false,
                ]),
            ],
            [
                'code'            => 'FUNDACION',
                'name'            => 'Fundación',
                'name_en'         => 'Foundation',
                'abbreviation'    => null,
                'country_code'    => 'ES',
                'description'     => 'Entidad sin ánimo de lucro con dotación patrimonial para fines de interés general',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 120,
                'metadata'        => json_encode([
                    'tax_id_format'   => 'CIF',
                    'tax_id_pattern'  => '/^[G][0-9]{7}[0-9A-J]$/',
                    'animo_lucro'     => false,
                    'dotacion_minima' => 30000,
                ]),
            ],

            // Autónomos
            [
                'code'            => 'AUTONOMO',
                'name'            => 'Trabajador Autónomo',
                'name_en'         => 'Self-Employed/Freelancer',
                'abbreviation'    => null,
                'country_code'    => 'ES',
                'description'     => 'Persona física que realiza actividad económica de forma habitual por cuenta propia',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 130,
                'metadata'        => json_encode([
                    'tax_id_format'  => 'NIF',
                    'tax_id_pattern' => '/^[0-9]{8}[A-Z]$|^[XYZ][0-9]{7}[A-Z]$/',
                    'regimen_fiscal' => 'estimacion_directa',
                ]),
            ],

            // Comunidades
            [
                'code'            => 'COMUNIDAD_BIENES',
                'name'            => 'Comunidad de Bienes',
                'name_en'         => 'Community of Goods',
                'abbreviation'    => 'CB',
                'country_code'    => 'ES',
                'description'     => 'Agrupación de personas físicas con propiedad compartida de bienes o derechos',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 140,
                'metadata'        => json_encode([
                    'tax_id_format'   => 'CIF',
                    'tax_id_pattern'  => '/^[EH][0-9]{7}[0-9A-J]$/',
                    'socios_minimo'   => 2,
                    'responsabilidad' => 'ilimitada',
                ]),
            ],

            // Administraciones Públicas
            [
                'code'            => 'ORGANISMO_PUBLICO',
                'name'            => 'Organismo Público',
                'name_en'         => 'Public Entity',
                'abbreviation'    => null,
                'country_code'    => 'ES',
                'description'     => 'Entidad de derecho público (Administración, organismos autónomos, etc.)',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 150,
                'metadata'        => json_encode([
                    'tax_id_format'  => 'CIF',
                    'tax_id_pattern' => '/^[PQRS][0-9]{7}[0-9A-J]$/',
                    'sector'         => 'publico',
                ]),
            ],

            // Otros
            [
                'code'            => 'OTRO',
                'name'            => 'Otro Tipo de Entidad',
                'name_en'         => 'Other Entity Type',
                'abbreviation'    => null,
                'country_code'    => 'ES',
                'description'     => 'Otras formas jurídicas no especificadas anteriormente',
                'requires_tax_id' => true,
                'is_active'       => true,
                'sort_order'      => 999,
                'metadata'        => json_encode([
                    'tax_id_format' => 'Variable',
                ]),
            ],
        ];

        foreach ($entityTypes as $entityType) {
            DB::table('legal_entity_types')->insert(array_merge($entityType, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
