<?php

namespace Ai;

use mysqli;
use Exception;

class AiVideoDatabaseMigration
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Check if video migration tables already exist.
     */
    public function isMigrated(): bool
    {
        $res = $this->db->query("SHOW TABLES LIKE 'tbl_ai_video_projects'");
        return ($res && $res->num_rows > 0);
    }

    /**
     * Run migrations to create video generator tables and storage folders.
     */
    public function run(): array
    {
        $results = [];

        // 1. Create tbl_ai_video_projects table
        $sqlProjects = "CREATE TABLE IF NOT EXISTS `tbl_ai_video_projects` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `recipe_id` INT(11) NOT NULL,
            `project_title` VARCHAR(255) NOT NULL,
            `kitchen_preset` VARCHAR(100) NOT NULL DEFAULT 'Modern Scandinavian Kitchen',
            `video_model` VARCHAR(50) NOT NULL DEFAULT 'omni_flash',
            `aspect_ratio` VARCHAR(20) NOT NULL DEFAULT '9:16',
            `clip_duration` INT(11) NOT NULL DEFAULT 4,
            `total_scenes` INT(11) NOT NULL DEFAULT 0,
            `status` ENUM('draft', 'storyboard_ready', 'generating_frames', 'frames_completed', 'generating_videos', 'videos_completed', 'stitching', 'completed', 'failed') NOT NULL DEFAULT 'draft',
            `master_anchor_prompt` TEXT NULL,
            `stitched_video_path` VARCHAR(255) NULL,
            `stitched_file_size` BIGINT(20) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_recipe` (`recipe_id`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        if ($this->db->query($sqlProjects)) {
            $results[] = 'Tabel `tbl_ai_video_projects` siap.';
        } else {
            throw new Exception('Gagal membuat tabel tbl_ai_video_projects: ' . $this->db->error);
        }

        // 2. Create tbl_ai_video_scenes table
        $sqlScenes = "CREATE TABLE IF NOT EXISTS `tbl_ai_video_scenes` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `project_id` INT(11) NOT NULL,
            `scene_order` INT(11) NOT NULL,
            `direction_source_text` TEXT NOT NULL,
            `visual_action_desc` TEXT NOT NULL,
            `prompt_t2i` TEXT NOT NULL,
            `prompt_i2v` TEXT NOT NULL,
            `frame_task_id` VARCHAR(100) NULL,
            `frame_status` ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
            `frame_image_path` VARCHAR(255) NULL,
            `video_task_id` VARCHAR(100) NULL,
            `video_status` ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
            `video_clip_path` VARCHAR(255) NULL,
            `error_message` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_project` (`project_id`),
            INDEX `idx_scene_order` (`scene_order`),
            CONSTRAINT `fk_video_scenes_project` FOREIGN KEY (`project_id`) REFERENCES `tbl_ai_video_projects`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        if ($this->db->query($sqlScenes)) {
            $results[] = 'Tabel `tbl_ai_video_scenes` siap.';
        } else {
            throw new Exception('Gagal membuat tabel tbl_ai_video_scenes: ' . $this->db->error);
        }

        // 3. Ensure required directories exist
        $baseDir = dirname(__DIR__);
        $dirsToCreate = [
            $baseDir . '/upload/ai_video_frames',
            $baseDir . '/upload/ai_video_clips',
            $baseDir . '/upload/video'
        ];

        foreach ($dirsToCreate as $d) {
            if (!is_dir($d)) {
                @mkdir($d, 0777, true);
                $results[] = "Direktori `" . basename($d) . "` berhasil dibuat.";
            }
        }

        return [
            'success' => true,
            'messages' => $results
        ];
    }
}
