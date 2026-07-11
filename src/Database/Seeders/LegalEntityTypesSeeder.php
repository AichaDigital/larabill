<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Database\Seeders;

use AichaDigital\Larabill\Models\LegalEntityType;
use Illuminate\Database\Seeder;

/**
 * Seeder for Spanish legal entity types.
 *
 * Creates the catalog of legal entity types used in Spain
 * with translations for Spanish and English.
 * Codes are in English for OpenSource compatibility.
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class LegalEntityTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = $this->getSpanishLegalEntityTypes();

        foreach ($types as $type) {
            LegalEntityType::updateOrCreate(
                ['code' => $type['code']],
                $type
            );
        }

        $this->command->info('Legal entity types for Spain seeded successfully.');
    }

    /**
     * Get Spanish legal entity types with translations.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getSpanishLegalEntityTypes(): array
    {
        return [
            // Natural persons / Personas físicas
            [
                'code' => 'INDIVIDUAL',
                'name' => [
                    'es' => 'Persona Física',
                    'en' => 'Individual / Natural Person',
                ],
                'abbreviation' => null,
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Persona física sin actividad empresarial formal',
                    'en' => 'Individual without formal business activity',
                ],
                'requires_tax_id' => true,
                'is_company'      => false,
                'is_active'       => true,
                'sort_order'      => 1,
            ],
            [
                'code' => 'SELF_EMPLOYED',
                'name' => [
                    'es' => 'Trabajador Autónomo',
                    'en' => 'Self-Employed / Sole Proprietor',
                ],
                'abbreviation' => null,
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Persona física que realiza actividad económica por cuenta propia',
                    'en' => 'Individual performing economic activity on their own account',
                ],
                'requires_tax_id' => true,
                'is_company'      => false,
                'is_active'       => true,
                'sort_order'      => 2,
            ],

            // Commercial companies / Sociedades mercantiles
            [
                'code' => 'LIMITED_COMPANY',
                'name' => [
                    'es' => 'Sociedad de Responsabilidad Limitada',
                    'en' => 'Private Limited Company',
                ],
                'abbreviation' => [
                    'es' => 'S.L.',
                    'en' => 'Ltd.',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Sociedad mercantil con responsabilidad limitada al capital aportado',
                    'en' => 'Commercial company with liability limited to contributed capital',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 10,
            ],
            [
                'code' => 'PUBLIC_LIMITED_COMPANY',
                'name' => [
                    'es' => 'Sociedad Anónima',
                    'en' => 'Public Limited Company / Corporation',
                ],
                'abbreviation' => [
                    'es' => 'S.A.',
                    'en' => 'PLC / Corp.',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Sociedad mercantil cuyo capital está dividido en acciones',
                    'en' => 'Commercial company with capital divided into shares',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 11,
            ],
            [
                'code' => 'NEW_ENTERPRISE_LIMITED',
                'name' => [
                    'es' => 'Sociedad Limitada Nueva Empresa',
                    'en' => 'New Enterprise Limited Company',
                ],
                'abbreviation' => [
                    'es' => 'S.L.N.E.',
                    'en' => 'NELC',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Variante simplificada de la S.L. para nuevos emprendedores',
                    'en' => 'Simplified variant of Ltd. for new entrepreneurs',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 12,
            ],

            // Worker-owned companies / Sociedades laborales
            [
                'code' => 'WORKER_OWNED_LIMITED',
                'name' => [
                    'es' => 'Sociedad Limitada Laboral',
                    'en' => 'Worker-Owned Limited Company',
                ],
                'abbreviation' => [
                    'es' => 'S.L.L.',
                    'en' => 'WOL',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Sociedad limitada donde la mayoría del capital pertenece a los trabajadores',
                    'en' => 'Limited company where majority of capital belongs to workers',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 20,
            ],
            [
                'code' => 'WORKER_OWNED_PUBLIC',
                'name' => [
                    'es' => 'Sociedad Anónima Laboral',
                    'en' => 'Worker-Owned Public Company',
                ],
                'abbreviation' => [
                    'es' => 'S.A.L.',
                    'en' => 'WOP',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Sociedad anónima donde la mayoría del capital pertenece a los trabajadores',
                    'en' => 'Public company where majority of capital belongs to workers',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 21,
            ],

            // Cooperatives / Cooperativas
            [
                'code' => 'COOPERATIVE',
                'name' => [
                    'es' => 'Sociedad Cooperativa',
                    'en' => 'Cooperative Society',
                ],
                'abbreviation' => [
                    'es' => 'S.Coop.',
                    'en' => 'Coop.',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Asociación autónoma de personas unidas voluntariamente para satisfacer necesidades comunes',
                    'en' => 'Autonomous association of persons united voluntarily to meet common needs',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 30,
            ],

            // Partnerships / Sociedades personalistas
            [
                'code' => 'GENERAL_PARTNERSHIP',
                'name' => [
                    'es' => 'Sociedad Colectiva',
                    'en' => 'General Partnership',
                ],
                'abbreviation' => [
                    'es' => 'S.C.',
                    'en' => 'GP',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Sociedad mercantil donde los socios responden ilimitadamente',
                    'en' => 'Commercial company where partners have unlimited liability',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 40,
            ],
            [
                'code' => 'LIMITED_PARTNERSHIP',
                'name' => [
                    'es' => 'Sociedad Comanditaria Simple',
                    'en' => 'Limited Partnership',
                ],
                'abbreviation' => [
                    'es' => 'S.Com.',
                    'en' => 'LP',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Sociedad con socios colectivos (responsabilidad ilimitada) y comanditarios (responsabilidad limitada)',
                    'en' => 'Partnership with general partners (unlimited) and limited partners',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 41,
            ],
            [
                'code' => 'LIMITED_PARTNERSHIP_SHARES',
                'name' => [
                    'es' => 'Sociedad Comanditaria por Acciones',
                    'en' => 'Publicly Traded Limited Partnership',
                ],
                'abbreviation' => [
                    'es' => 'S.Com.A.',
                    'en' => 'PTLP',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Sociedad comanditaria cuyo capital está dividido en acciones',
                    'en' => 'Limited partnership with capital divided into shares',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 42,
            ],

            // Other legal forms / Otras formas jurídicas
            [
                'code' => 'COMMUNITY_OF_GOODS',
                'name' => [
                    'es' => 'Comunidad de Bienes',
                    'en' => 'Community of Goods / Joint Ownership',
                ],
                'abbreviation' => [
                    'es' => 'C.B.',
                    'en' => 'CoG',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Contrato por el cual la propiedad de una cosa o derecho pertenece pro indiviso a varias personas',
                    'en' => 'Contract where ownership of a thing or right belongs undivided to several persons',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 50,
            ],
            [
                'code' => 'CIVIL_PARTNERSHIP',
                'name' => [
                    'es' => 'Sociedad Civil',
                    'en' => 'Civil Partnership',
                ],
                'abbreviation' => [
                    'es' => 'S.C.',
                    'en' => 'CP',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Contrato por el cual dos o más personas ponen en común dinero, bienes o industria',
                    'en' => 'Contract where two or more persons pool money, goods or industry',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 51,
            ],

            // Non-profit entities / Entidades sin ánimo de lucro
            [
                'code' => 'ASSOCIATION',
                'name' => [
                    'es' => 'Asociación',
                    'en' => 'Association / Non-profit Organization',
                ],
                'abbreviation' => [
                    'es' => 'Asoc.',
                    'en' => 'Assoc.',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Agrupación de personas físicas o jurídicas para un fin común sin ánimo de lucro',
                    'en' => 'Group of natural or legal persons for a common purpose without profit motive',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 60,
            ],
            [
                'code' => 'FOUNDATION',
                'name' => [
                    'es' => 'Fundación',
                    'en' => 'Foundation',
                ],
                'abbreviation' => [
                    'es' => 'Fund.',
                    'en' => 'Found.',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Organización sin ánimo de lucro dotada de patrimonio propio para fines de interés general',
                    'en' => 'Non-profit organization with its own assets for general interest purposes',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 61,
            ],

            // Business groupings / Agrupaciones empresariales
            [
                'code' => 'ECONOMIC_INTEREST_GROUPING',
                'name' => [
                    'es' => 'Agrupación de Interés Económico',
                    'en' => 'Economic Interest Grouping',
                ],
                'abbreviation' => [
                    'es' => 'A.I.E.',
                    'en' => 'EIG',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Entidad para facilitar o desarrollar la actividad económica de sus miembros',
                    'en' => 'Entity to facilitate or develop the economic activity of its members',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 70,
            ],
            [
                'code' => 'JOINT_VENTURE',
                'name' => [
                    'es' => 'Unión Temporal de Empresas',
                    'en' => 'Temporary Business Association / Joint Venture',
                ],
                'abbreviation' => [
                    'es' => 'U.T.E.',
                    'en' => 'JV',
                ],
                'country_code' => 'ES',
                'description'  => [
                    'es' => 'Sistema de colaboración entre empresarios por tiempo cierto para el desarrollo de una obra o servicio',
                    'en' => 'Collaboration system between entrepreneurs for a specific time to develop a work or service',
                ],
                'requires_tax_id' => true,
                'is_company'      => true,
                'is_active'       => true,
                'sort_order'      => 71,
            ],
        ];
    }
}
