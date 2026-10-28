<?php

namespace Ai;

use mysqli;

class AiDatabaseMigration
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Checks if all AI tables exist in the current database.
     */
    public function isMigrated(): bool
    {
        $res1 = $this->db->query("SHOW TABLES LIKE 'tbl_ai_config'");
        $hasConfig = ($res1 && $res1->num_rows > 0);

        $res2 = $this->db->query("SHOW TABLES LIKE 'tbl_ai_generations'");
        $hasGenerations = ($res2 && $res2->num_rows > 0);

        $res3 = $this->db->query("SHOW TABLES LIKE 'tbl_ai_keys'");
        $hasKeys = ($res3 && $res3->num_rows > 0);

        return ($hasConfig && $hasGenerations && $hasKeys);
    }

    /**
     * Runs migration to create tbl_ai_config, tbl_ai_generations, and tbl_ai_keys.
     */
    public function runMigration(): array
    {
        // 1. Table tbl_ai_config
        $sqlConfig = "CREATE TABLE IF NOT EXISTS `tbl_ai_config` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `gemini_api_key` VARCHAR(255) DEFAULT NULL,
            `glabs_host` VARCHAR(255) NOT NULL DEFAULT '127.0.0.1',
            `glabs_port` INT(11) NOT NULL DEFAULT 8765,
            `glabs_api_key` VARCHAR(255) DEFAULT NULL,
            `glabs_model` VARCHAR(100) NOT NULL DEFAULT 'nano_banana_2',
            `gemini_model` VARCHAR(100) NOT NULL DEFAULT 'gemini-3.7-flash',
            `gemini_fallback_models` TEXT NULL DEFAULT NULL,
            `gemini_max_retries` INT NOT NULL DEFAULT 2,
            `gemini_retry_delay_ms` INT NOT NULL DEFAULT 2000,
            `default_aspect_ratio` VARCHAR(20) NOT NULL DEFAULT '1:1',
            `default_style` VARCHAR(100) NOT NULL DEFAULT 'Rustic Kitchen Table',
            `negative_prompts` TEXT DEFAULT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

        if (!$this->db->query($sqlConfig)) {
            return [
                'success' => false,
                'error' => 'Gagal membuat tabel tbl_ai_config: ' . $this->db->error
            ];
        }

        // Add columns if tbl_ai_config was created earlier without fallback columns
        $columnsToCheck = [
            'gemini_fallback_models' => "TEXT NULL DEFAULT NULL",
            'gemini_max_retries' => "INT NOT NULL DEFAULT 2",
            'gemini_retry_delay_ms' => "INT NOT NULL DEFAULT 2000"
        ];
        foreach ($columnsToCheck as $col => $definition) {
            $checkCol = $this->db->query("SHOW COLUMNS FROM `tbl_ai_config` LIKE '{$col}'");
            if ($checkCol && $checkCol->num_rows === 0) {
                $this->db->query("ALTER TABLE `tbl_ai_config` ADD COLUMN `{$col}` {$definition};");
            }
        }

        // Seed default config row if empty
        $checkSeed = $this->db->query("SELECT id FROM `tbl_ai_config` WHERE id = 1");
        if ($checkSeed && $checkSeed->num_rows === 0) {
            $defaultNegative = 'blurry, out of focus, distorted food, artificial plastic look, bad lighting, watermark, text, raw meat, unappetizing colors, oversaturated neon, extra forks, cartoon, 3d render';
            $stmt = $this->db->prepare("INSERT INTO `tbl_ai_config` (id, glabs_host, glabs_port, glabs_model, gemini_model, default_aspect_ratio, default_style, negative_prompts) VALUES (1, '127.0.0.1', 8765, 'nano_banana_2', 'gemini-3.7-flash', '1:1', 'Rustic Kitchen Table', ?)");
            if ($stmt) {
                $stmt->bind_param('s', $defaultNegative);
                $stmt->execute();
                $stmt->close();
            }
        }

        // 2. Table tbl_ai_generations
        $sqlGenerations = "CREATE TABLE IF NOT EXISTS `tbl_ai_generations` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `recipe_id` INT(11) DEFAULT NULL,
            `task_id` VARCHAR(64) DEFAULT NULL,
            `prompt` TEXT NOT NULL,
            `style_preset` VARCHAR(100) DEFAULT NULL,
            `model_used` VARCHAR(100) NOT NULL DEFAULT 'nano_banana_2',
            `image_filename` VARCHAR(255) DEFAULT NULL,
            `previous_image` VARCHAR(255) DEFAULT NULL,
            `aspect_ratio` VARCHAR(20) NOT NULL DEFAULT '1:1',
            `status` ENUM('pending', 'running', 'completed', 'applied', 'discarded', 'reverted', 'failed') NOT NULL DEFAULT 'pending',
            `error_message` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_recipe_id` (`recipe_id`),
            KEY `idx_status` (`status`),
            KEY `idx_task_id` (`task_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

        if (!$this->db->query($sqlGenerations)) {
            return [
                'success' => false,
                'error' => 'Gagal membuat tabel tbl_ai_generations: ' . $this->db->error
            ];
        }

        // 3. Table tbl_ai_keys
        $sqlKeys = "CREATE TABLE IF NOT EXISTS `tbl_ai_keys` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `key_name` VARCHAR(100) NOT NULL,
            `api_key` VARCHAR(255) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `daily_limit` INT(11) NOT NULL DEFAULT 1500,
            `used_today` INT(11) NOT NULL DEFAULT 0,
            `last_used_date` DATE DEFAULT NULL,
            `last_status` VARCHAR(50) NOT NULL DEFAULT 'ACTIVE',
            `cooldown_until` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ai_api_key` (`api_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

        if (!$this->db->query($sqlKeys)) {
            return [
                'success' => false,
                'error' => 'Gagal membuat tabel tbl_ai_keys: ' . $this->db->error
            ];
        }

        // Migrate single key from config if present
        $configRes = $this->db->query("SELECT gemini_api_key FROM `tbl_ai_config` WHERE id = 1 LIMIT 1");
        if ($configRes && $row = $configRes->fetch_assoc()) {
            $existingKey = trim($row['gemini_api_key'] ?? '');
            if (!empty($existingKey)) {
                $checkExisting = $this->db->prepare("SELECT id FROM `tbl_ai_keys` WHERE `api_key` = ?");
                if ($checkExisting) {
                    $checkExisting->bind_param('s', $existingKey);
                    $checkExisting->execute();
                    $checkExisting->store_result();
                    if ($checkExisting->num_rows === 0) {
                        $keyName = 'Google AI Studio Utama';
                        $insertKey = $this->db->prepare("INSERT INTO `tbl_ai_keys` (`key_name`, `api_key`, `is_active`, `daily_limit`) VALUES (?, ?, 1, 1500)");
                        if ($insertKey) {
                            $insertKey->bind_param('ss', $keyName, $existingKey);
                            $insertKey->execute();
                            $insertKey->close();
                        }
                    }
                    $checkExisting->close();
                }
            }
        }

        return [
            'success' => true,
            'message' => 'Migrasi database AI Image Generator berhasil.'
        ];
    }
}
