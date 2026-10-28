<?php

namespace Ai;

use mysqli;
use Throwable;

class AiR2DatabaseMigration
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Run migration idempotently to add Cloudflare R2 settings to tbl_ai_config.
     */
    public function run(): array
    {
        $messages = [];

        try {
            $checkTable = $this->db->query("SHOW TABLES LIKE 'tbl_ai_config'");
            if (!$checkTable || $checkTable->num_rows === 0) {
                return ['tbl_ai_config not found. Run base migration first.'];
            }

            // Columns to add
            $columns = [
                'r2_enabled' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `sync_secret_token`",
                'r2_account_id' => "VARCHAR(100) NULL DEFAULT NULL AFTER `r2_enabled`",
                'r2_access_key_id' => "VARCHAR(100) NULL DEFAULT NULL AFTER `r2_account_id`",
                'r2_secret_access_key' => "VARCHAR(100) NULL DEFAULT NULL AFTER `r2_access_key_id`",
                'r2_bucket_name' => "VARCHAR(100) NOT NULL DEFAULT 'airfry-videos' AFTER `r2_secret_access_key`",
                'r2_public_domain' => "VARCHAR(255) NOT NULL DEFAULT 'https://video.airfry.web.id' AFTER `r2_bucket_name`"
            ];

            foreach ($columns as $columnName => $columnDefinition) {
                $colRes = $this->db->query("SHOW COLUMNS FROM `tbl_ai_config` LIKE '{$columnName}'");
                if ($colRes && $colRes->num_rows === 0) {
                    $alterSql = "ALTER TABLE `tbl_ai_config` ADD COLUMN `{$columnName}` {$columnDefinition}";
                    if ($this->db->query($alterSql)) {
                        $messages[] = "Added column `{$columnName}` to tbl_ai_config.";
                    } else {
                        $messages[] = "Failed to add column `{$columnName}`: " . $this->db->error;
                    }
                }
            }

            if (empty($messages)) {
                $messages[] = "R2 columns already up-to-date in tbl_ai_config.";
            }

            return $messages;
        } catch (Throwable $e) {
            return ["R2 Migration error: " . $e->getMessage()];
        }
    }
}
