<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class CategoryServiceSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $categories = [
            [
                'name' => 'Full Build / General Scope',
                'slug' => 'full-build-general-scope',
                'description' => 'New ground-up construction',
                'sort_order' => 1,
            ],
            [
                'name' => 'Renovation / Interior Fit-out',
                'slug' => 'renovation-interior-fit-out',
                'description' => 'Remodels or tenant improvements',
                'sort_order' => 2,
            ],
            [
                'name' => 'Trade-Specific / Sub-Scope',
                'slug' => 'trade-specific-sub-scope',
                'description' => 'Focused on specific divisions',
                'sort_order' => 3,
            ],
            [
                'name' => 'Civil / Earthwork',
                'slug' => 'civil-earthwork',
                'description' => 'Site prep, grading, utilities',
                'sort_order' => 4,
            ],
        ];

        $services = [
            'Demolition',
            'Building Concrete',
            'Site Concrete',
            'Masonry (CMU Block Walls)',
            'Masonry (Brick Veneer)',
            'Masonry (Stone Veneer)',
            'Metals (Structural Steel Framing)',
            'Metals (Misc. Steels, Railings, Gates etc.)',
            'Lumber',
            'Trusses',
            'Rough Carpentry (Install Only Items,Trims, Sills & Blockings etc)',
            'Finish Carpentry (Millwork & Wood Wall Base)',
            'Doors',
            'Windows',
            'Storefronts',
            'Curtain Walls',
            'Drywall & Ceilings',
            'ACT Ceilings',
            'Wood Ceilings',
            'Metal Ceilings',
            'Baffles',
            'Flooring (LVT, VCT, WOOD, SEALED, POLISHED, EPOXY etc)',
            'Tilings',
            'Wall Bases (Rubber, Vinyl, Epoxy etc)',
            'Wall Coverings',
            'Exterior Finishes (Sidings, Panels, Stucco/EIFS Soffits, Trims & Flashings)',
            'Paintings',
            'Mechanical',
            'Plumbing',
            'Fire Sprinkler',
            'Fire Alarm',
            'Electrical',
            'Audio/Visual',
            'Security',
            'Equipments/Appliances',
            'Furniture',
            'Telecom',
            'Earthwork (Cut/Fill Analysis) & 3D Grading Model',
            '3D Grading Model',
            'Complete Sitework Including Demo & 3D Grading Model',
            'Landscaping',
            'Retaining Walls',
            'Asphalt Pavements',
            'Concrete Pavement, Sidewalks',
            'Curbs',
            'All Trades Including MEP\'s',
            'All Trades Except MEP\'s',
        ];

        $categoryIds = [];
        foreach ($categories as $category) {
            $existing = $this->db->table('categories')
                ->where('slug', $category['slug'])
                ->get()
                ->getRowArray();

            $payload = [
                'name' => $category['name'],
                'slug' => $category['slug'],
                'description' => $category['description'],
                'image' => null,
                'is_active' => 1,
                'sort_order' => $category['sort_order'],
                'updated_at' => $now,
            ];

            if (is_array($existing)) {
                $categoryId = (int) ($existing['id'] ?? 0);
                $this->db->table('categories')->where('id', $categoryId)->update($payload);
            } else {
                $payload['created_at'] = $now;
                $this->db->table('categories')->insert($payload);
                $categoryId = (int) $this->db->insertID();
            }

            if ($categoryId > 0) {
                $categoryIds[] = $categoryId;
            }
        }

        $serviceIds = [];
        foreach ($services as $index => $serviceName) {
            $slug = $this->slugify($serviceName);

            $existing = $this->db->table('services')
                ->where('slug', $slug)
                ->get()
                ->getRowArray();

            $payload = [
                'name' => $serviceName,
                'slug' => $slug,
                'description' => null,
                'icon' => null,
                'is_active' => 1,
                'sort_order' => $index + 1,
                'updated_at' => $now,
            ];

            if (is_array($existing)) {
                $serviceId = (int) ($existing['id'] ?? 0);
                $this->db->table('services')->where('id', $serviceId)->update($payload);
            } else {
                $payload['created_at'] = $now;
                $this->db->table('services')->insert($payload);
                $serviceId = (int) $this->db->insertID();
            }

            if ($serviceId > 0) {
                $serviceIds[] = $serviceId;
            }
        }

        // Ensure every service is linked to all four categories.
        $this->db->table('service_categories')->emptyTable();

        $pivotRows = [];
        foreach ($serviceIds as $serviceId) {
            foreach ($categoryIds as $categoryId) {
                $pivotRows[] = [
                    'service_id' => $serviceId,
                    'category_id' => $categoryId,
                    'created_at' => $now,
                ];
            }
        }

        if ($pivotRows !== []) {
            $this->db->table('service_categories')->insertBatch($pivotRows);
        }
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'service-' . bin2hex(random_bytes(4)) : $slug;
    }
}
