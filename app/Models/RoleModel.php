<?php

namespace App\Models;

use CodeIgniter\Model;

class RoleModel extends Model
{
    protected $table = 'roles';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';

    protected $allowedFields = [
        'name',
        'slug',
        'description',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Find role by slug
     */
    public function findBySlug(string $slug): ?array
    {
        $role = $this->where('slug', trim($slug))->first();

        return is_array($role) ? $role : null;
    }

    /**
     * Get or create role
     */
    public function getOrCreate(string $name, string $slug, string $description = ''): ?int
    {
        $existing = $this->findBySlug($slug);
        if (is_array($existing)) {
            return $existing['id'];
        }

        $this->insert([
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
        ]);

        return $this->getInsertID();
    }
}
