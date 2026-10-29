<?php

namespace Ai;

require_once __DIR__ . '/AiDatabaseMigration.php';
require_once __DIR__ . '/AiDatabaseMigrationV2.php';
require_once __DIR__ . '/AiVideoDatabaseMigration.php';
require_once __DIR__ . '/AiR2DatabaseMigration.php';
require_once __DIR__ . '/AiMiniMaxDatabaseMigration.php';

use mysqli;
use Throwable;

class AiBootstrap
{
    /**
     * Checks if base AI tables exist.
     */
    public static function isMigrated(mysqli $db): bool
    {
        try {
            $requiredTables = [
                'tbl_ai_config',
                'tbl_ai_generations',
                'tbl_ai_keys',
                'tbl_ai_video_projects'
            ];

            foreach ($requiredTables as $table) {
                $res = $db->query("SHOW TABLES LIKE '{$table}'");
                if (!$res || $res->num_rows === 0) {
                    return false;
                }
            }

            return true;
        } catch (Throwable $e) {
            error_log("[AiBootstrap::isMigrated] " . $e->getMessage());
            return false;
        }
    }

    /**
     * Run all migrations idempotently.
     */
    public static function runAllMigrations(mysqli $db): array
    {
        $messages = [];
        $hasError = false;

        // 1. Run Base AI Migration (Config, Generations, Keys)
        try {
            $mig1 = new AiDatabaseMigration($db);
            $res1 = $mig1->runMigration();
            if (isset($res1['success']) && !$res1['success']) {
                $hasError = true;
                $messages[] = "Error Base Migration: " . ($res1['error'] ?? 'Unknown');
            } else {
                $messages[] = "Base AI tables ready.";
            }
        } catch (Throwable $e) {
            $hasError = true;
            $messages[] = "Exception Base Migration: " . $e->getMessage();
        }

        // 2. Run V2 Migration (Keys table updates, fallback models)
        try {
            $mig2 = new AiDatabaseMigrationV2($db);
            $res2 = $mig2->run();
            $messages = array_merge($messages, $res2);
        } catch (Throwable $e) {
            $hasError = true;
            $messages[] = "Exception V2 Migration: " . $e->getMessage();
        }

        // 3. Run Video Migration
        try {
            $mig3 = new AiVideoDatabaseMigration($db);
            $res3 = $mig3->run();
            if (is_array($res3)) {
                foreach ($res3 as $k => $v) {
                    $messages[] = is_array($v) ? json_encode($v) : (string)$v;
                }
            }
        } catch (Throwable $e) {
            $hasError = true;
            $messages[] = "Exception Video Migration: " . $e->getMessage();
        }

        // 4. Run Cloudflare R2 Migration
        try {
            $mig4 = new AiR2DatabaseMigration($db);
            $res4 = $mig4->run();
            if (is_array($res4)) {
                foreach ($res4 as $k => $v) {
                    $messages[] = is_array($v) ? json_encode($v) : (string)$v;
                }
            }
        } catch (Throwable $e) {
            $hasError = true;
            $messages[] = "Exception R2 Migration: " . $e->getMessage();
        }

        // 5. Run MiniMax TTS Migration
        try {
            $mig5 = new AiMiniMaxDatabaseMigration($db);
            $res5 = $mig5->run();
            if (is_array($res5)) {
                foreach ($res5 as $k => $v) {
                    $messages[] = is_array($v) ? json_encode($v) : (string)$v;
                }
            }
        } catch (Throwable $e) {
            $hasError = true;
            $messages[] = "Exception MiniMax Migration: " . $e->getMessage();
        }

        return [
            'success' => !$hasError,
            'messages' => $messages
        ];
    }
}
