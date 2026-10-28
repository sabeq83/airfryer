<?php

namespace Importer;

use mysqli;

class DatabaseMigration
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Checks if tbl_recipe_imports exists in the current database.
     */
    public function isMigrated(): bool
    {
        $sql = "SHOW TABLES LIKE 'tbl_recipe_imports'";
        $result = $this->db->query($sql);
        return ($result && $result->num_rows > 0);
    }

    /**
     * Runs migration to create or update tbl_recipe_imports.
     */
    public function runMigration(): array
    {
        $sql = "CREATE TABLE IF NOT EXISTS `tbl_recipe_imports` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `source_url` VARCHAR(1000) NOT NULL,
          `normalized_url` VARCHAR(500) NOT NULL,
          `source_domain` VARCHAR(100) NOT NULL,
          `recipe_title` VARCHAR(255) DEFAULT NULL,
          `prep_time` VARCHAR(100) DEFAULT NULL,
          `cook_time` VARCHAR(100) DEFAULT NULL,
          `total_time` VARCHAR(100) DEFAULT NULL,
          `servings` VARCHAR(100) DEFAULT NULL,
          `summary` TEXT DEFAULT NULL,
          `ingredients_json` LONGTEXT DEFAULT NULL,
          `instructions_json` LONGTEXT DEFAULT NULL,
          `image_candidates_json` LONGTEXT DEFAULT NULL,
          `selected_image_url` TEXT DEFAULT NULL,
          `cat_id` INT(11) DEFAULT NULL,
          `status` ENUM('draft', 'published', 'rejected', 'failed') NOT NULL DEFAULT 'draft',
          `failure_reason` TEXT DEFAULT NULL,
          `processing_time_ms` INT(11) NOT NULL DEFAULT 0,
          `recipe_id` INT(11) DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          `published_at` DATETIME DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_normalized_url` (`normalized_url`),
          KEY `idx_status` (`status`),
          KEY `idx_recipe_id` (`recipe_id`),
          KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

        if ($this->db->query($sql) === true) {
            return [
                'success' => true,
                'message' => 'Tabel tbl_recipe_imports berhasil dibuat / diverifikasi.',
            ];
        }

        return [
            'success' => false,
            'message' => 'Gagal menjalankan migrasi: ' . $this->db->error,
        ];
    }
}
