<?php

namespace Ai;

use mysqli;
use Exception;

class AiDatabaseMigrationV2
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Run V2 database migrations: tbl_ai_keys and config additions.
     */
    public function run(): array
    {
        $results = [];

        // 1. Create tbl_ai_keys table
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        if ($this->db->query($sqlKeys)) {
            $results[] = 'Tabel `tbl_ai_keys` siap / berhasil dibuat.';
        } else {
            throw new Exception('Gagal membuat tabel tbl_ai_keys: ' . $this->db->error);
        }

        // 2. Add columns to tbl_ai_config if not exists
        $columnsToCheck = [
            'gemini_fallback_models' => "TEXT NULL DEFAULT NULL",
            'gemini_max_retries' => "INT NOT NULL DEFAULT 2",
            'gemini_retry_delay_ms' => "INT NOT NULL DEFAULT 2000"
        ];

        foreach ($columnsToCheck as $col => $definition) {
            $checkCol = $this->db->query("SHOW COLUMNS FROM `tbl_ai_config` LIKE '{$col}'");
            if ($checkCol && $checkCol->num_rows === 0) {
                $alterSql = "ALTER TABLE `tbl_ai_config` ADD COLUMN `{$col}` {$definition};";
                if ($this->db->query($alterSql)) {
                    $results[] = "Kolom `{$col}` berhasil ditambahkan ke `tbl_ai_config`.";
                } else {
                    $results[] = "Peringatan ALTER kolom `{$col}`: " . $this->db->error;
                }
            } else {
                $results[] = "Kolom `{$col}` sudah ada di `tbl_ai_config`.";
            }
        }

        // 3. Migrate existing gemini_api_key from tbl_ai_config into tbl_ai_keys if not present
        $configRes = $this->db->query("SELECT gemini_api_key FROM `tbl_ai_config` WHERE id = 1 LIMIT 1");
        if ($configRes && $row = $configRes->fetch_assoc()) {
            $existingKey = trim($row['gemini_api_key'] ?? '');
            if (!empty($existingKey)) {
                $checkExisting = $this->db->prepare("SELECT id FROM `tbl_ai_keys` WHERE `api_key` = ?");
                $checkExisting->bind_param('s', $existingKey);
                $checkExisting->execute();
                $checkExisting->store_result();
                if ($checkExisting->num_rows === 0) {
                    $keyName = 'Google AI Studio Utama';
                    $insertKey = $this->db->prepare("INSERT INTO `tbl_ai_keys` (`key_name`, `api_key`, `is_active`, `daily_limit`) VALUES (?, ?, 1, 1500)");
                    $insertKey->bind_param('ss', $keyName, $existingKey);
                    $insertKey->execute();
                    $insertKey->close();
                    $results[] = "Kunci aktif saat ini berhasil dimigrasikan ke pool `tbl_ai_keys`.";
                } else {
                    $results[] = "Kunci aktif sudah terdaftar di pool `tbl_ai_keys`.";
                }
                $checkExisting->close();
            }
        }

        return [
            'success' => true,
            'messages' => $results
        ];
    }
}
