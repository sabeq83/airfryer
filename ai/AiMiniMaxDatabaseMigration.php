<?php

namespace Ai;

use mysqli;
use Throwable;

class AiMiniMaxDatabaseMigration
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Run migration idempotently to add MiniMax TTS settings and scene columns.
     */
    public function run(): array
    {
        $messages = [];

        try {
            // 1. Update tbl_ai_config for MiniMax settings
            $checkConfig = $this->db->query("SHOW TABLES LIKE 'tbl_ai_config'");
            if ($checkConfig && $checkConfig->num_rows > 0) {
                $configCols = [
                    'minimax_api_key' => "VARCHAR(255) NULL DEFAULT NULL AFTER `r2_public_domain`",
                    'minimax_group_id' => "VARCHAR(100) NULL DEFAULT NULL AFTER `minimax_api_key`",
                    'minimax_model' => "VARCHAR(50) NOT NULL DEFAULT 'speech-2.8-turbo' AFTER `minimax_group_id`",
                    'minimax_default_language' => "VARCHAR(10) NOT NULL DEFAULT 'id-ID' AFTER `minimax_model`",
                    'minimax_default_voice' => "VARCHAR(100) NOT NULL DEFAULT 'Indonesian_energetic_streamer_vv2' AFTER `minimax_default_language`",
                    'minimax_default_speed' => "DECIMAL(3,2) NOT NULL DEFAULT 1.00 AFTER `minimax_default_voice`",
                    'minimax_default_volume' => "DECIMAL(3,2) NOT NULL DEFAULT 1.00 AFTER `minimax_default_speed`"
                ];

                foreach ($configCols as $col => $def) {
                    $res = $this->db->query("SHOW COLUMNS FROM `tbl_ai_config` LIKE '{$col}'");
                    if ($res && $res->num_rows === 0) {
                        $alterSql = "ALTER TABLE `tbl_ai_config` ADD COLUMN `{$col}` {$def}";
                        if ($this->db->query($alterSql)) {
                            $messages[] = "Added column `{$col}` to tbl_ai_config.";
                        } else {
                            $messages[] = "Failed to add column `{$col}` to tbl_ai_config: " . $this->db->error;
                        }
                    }
                }
            }

            // 2. Update tbl_ai_video_projects for TTS settings
            $checkProjects = $this->db->query("SHOW TABLES LIKE 'tbl_ai_video_projects'");
            if ($checkProjects && $checkProjects->num_rows > 0) {
                $projCols = [
                    'tts_enabled' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER `stitched_file_size`",
                    'tts_language' => "VARCHAR(10) NOT NULL DEFAULT 'id-ID' AFTER `tts_enabled`",
                    'tts_voice_persona' => "VARCHAR(100) NOT NULL DEFAULT 'Indonesian_energetic_streamer_vv2' AFTER `tts_language`",
                    'tts_speed' => "DECIMAL(3,2) NOT NULL DEFAULT 1.00 AFTER `tts_voice_persona`",
                    'tts_volume' => "DECIMAL(3,2) NOT NULL DEFAULT 1.00 AFTER `tts_speed`",
                    'tts_enable_bgm' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER `tts_volume`",
                    'tts_bgm_volume' => "DECIMAL(3,2) NOT NULL DEFAULT 0.18 AFTER `tts_enable_bgm`",
                    'tts_full_audio_path' => "VARCHAR(255) NULL DEFAULT NULL AFTER `tts_bgm_volume`"
                ];

                foreach ($projCols as $col => $def) {
                    $res = $this->db->query("SHOW COLUMNS FROM `tbl_ai_video_projects` LIKE '{$col}'");
                    if ($res && $res->num_rows === 0) {
                        $alterSql = "ALTER TABLE `tbl_ai_video_projects` ADD COLUMN `{$col}` {$def}";
                        if ($this->db->query($alterSql)) {
                            $messages[] = "Added column `{$col}` to tbl_ai_video_projects.";
                        } else {
                            $messages[] = "Failed to add column `{$col}` to tbl_ai_video_projects: " . $this->db->error;
                        }
                    }
                }
            }

            // 3. Update tbl_ai_video_scenes for TTS audio columns
            $checkScenes = $this->db->query("SHOW TABLES LIKE 'tbl_ai_video_scenes'");
            if ($checkScenes && $checkScenes->num_rows > 0) {
                $sceneCols = [
                    'tts_text' => "TEXT NULL DEFAULT NULL AFTER `prompt_i2v`",
                    'tts_audio_path' => "VARCHAR(255) NULL DEFAULT NULL AFTER `video_clip_path`",
                    'tts_duration' => "DECIMAL(6,2) NULL DEFAULT NULL AFTER `tts_audio_path`",
                    'tts_status' => "ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending' AFTER `tts_duration`"
                ];

                foreach ($sceneCols as $col => $def) {
                    $res = $this->db->query("SHOW COLUMNS FROM `tbl_ai_video_scenes` LIKE '{$col}'");
                    if ($res && $res->num_rows === 0) {
                        $alterSql = "ALTER TABLE `tbl_ai_video_scenes` ADD COLUMN `{$col}` {$def}";
                        if ($this->db->query($alterSql)) {
                            $messages[] = "Added column `{$col}` to tbl_ai_video_scenes.";
                        } else {
                            $messages[] = "Failed to add column `{$col}` to tbl_ai_video_scenes: " . $this->db->error;
                        }
                    }
                }
            }

            // 4. Ensure upload directory for TTS audio exists
            $baseDir = dirname(__DIR__);
            $ttsDir = $baseDir . '/upload/ai_tts_audio';
            if (!is_dir($ttsDir)) {
                @mkdir($ttsDir, 0777, true);
                $messages[] = "Directory `upload/ai_tts_audio` created.";
            }

            if (empty($messages)) {
                $messages[] = "MiniMax TTS database columns already up-to-date.";
            }

            return $messages;
        } catch (Throwable $e) {
            return ["MiniMax Migration error: " . $e->getMessage()];
        }
    }
}
