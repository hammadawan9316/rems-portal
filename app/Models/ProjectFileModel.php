<?php

namespace App\Models;

use CodeIgniter\Model;

class ProjectFileModel extends Model
{
    protected $table = 'project_files';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'project_id',
        'original_name',
        'stored_name',
        'mime_type',
        'size_kb',
        'relative_path',
        'full_path',
        'public_token',
        'access_password_hash',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
