<?php

namespace Ai;

use mysqli;
use Exception;

class AiVideoService
{
    private mysqli $db;
    private GeminiClient $geminiClient;
    private GlabsClient $glabsClient;
    private FFmpegVideoService $ffmpegService;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->geminiClient = new GeminiClient();
        $this->glabsClient = new GlabsClient();
        $this->ffmpegService = new FFmpegVideoService();
    }

    /**
     * Get G-Labs configuration from tbl_ai_config.
     */
    public function getGlabsConfig(): array
    {
        $res = $this->db->query("SELECT glabs_host, glabs_port, glabs_api_key FROM tbl_ai_config WHERE id = 1 LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            return [
                'host' => trim($row['glabs_host'] ?? '127.0.0.1'),
                'port' => (int)($row['glabs_port'] ?? 8765),
                'api_key' => trim($row['glabs_api_key'] ?? '')
            ];
        }
        return ['host' => '127.0.0.1', 'port' => 8765, 'api_key' => ''];
    }

    /**
     * Get recipe data by ID from tbl_recipes.
     */
    public function getRecipe(int $recipeId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM tbl_recipes WHERE recipe_id = ? LIMIT 1");
        $stmt->bind_param('i', $recipeId);
        $stmt->execute();
        $res = $stmt->get_result();
        $recipe = $res->fetch_assoc();
        $stmt->close();
        return $recipe;
    }

    /**
     * Parse structured recipe description HTML/text into clean summary, ingredients, and directions.
     * Robust against multiple variations: DIRECTION, DIRECTIONS, METHOD, INSTRUCTIONS, CARA MEMBUAT, etc.
     */
    public function parseRecipeSections(string $rawDesc): array
    {
        $summary = '';
        $ingredients = [];
        $directions = [];

        $html = str_replace(["\r\n", "\r"], "\n", $rawDesc);

        // Pattern for Directions/Instructions/Method header
        $dirPattern = '/(?:<p\b[^>]*>)?\s*<(?:strong|b|h[1-4])\b[^>]*>\s*(?:DIRECTION|DIRECTIONS|INSTRUCTION|INSTRUCTIONS|METHOD|CARA\s+MEMBUAT|LANGKAH[\s\-]+LANGKAH|PREPARATION)\s*:?\s*<\/(?:strong|b|h[1-4])>(?:\s*<\/p>)?/i';

        // Pattern for Ingredients header
        $ingPattern = '/(?:<p\b[^>]*>)?\s*<(?:strong|b|h[1-4])\b[^>]*>\s*(?:INGREDIENT|INGREDIENTS|BAHAN[\s\-]+BAHAN|BAHAN)\s*:?\s*<\/(?:strong|b|h[1-4])>(?:\s*<\/p>)?/i';

        // 1. Extract Ingredients
        if (preg_match($ingPattern, $html, $mIng, PREG_OFFSET_CAPTURE)) {
            $ingOffset = $mIng[0][1] + strlen($mIng[0][0]);
            $ingContent = substr($html, $ingOffset);
            if (preg_match($dirPattern, $ingContent, $mNextDir, PREG_OFFSET_CAPTURE)) {
                $ingContent = substr($ingContent, 0, $mNextDir[0][1]);
            }
            if (preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', $ingContent, $liMatches)) {
                foreach ($liMatches[1] as $item) {
                    $c = trim(strip_tags(html_entity_decode($item, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    if ($c !== '') $ingredients[] = $c;
                }
            }
        }

        // 2. Extract Directions
        if (preg_match($dirPattern, $html, $mDir, PREG_OFFSET_CAPTURE)) {
            $dirOffset = $mDir[0][1] + strlen($mDir[0][0]);
            $dirContent = substr($html, $dirOffset);

            // Cut off if there is a Tips/Notes section
            if (preg_match('/(?:<p\b[^>]*>)?\s*<(?:strong|b|h[1-4])\b[^>]*>\s*(?:TIPS|NOTES?|NUTRITION|CATATAN)\s*:?\s*<\/(?:strong|b|h[1-4])>/i', $dirContent, $mNext, PREG_OFFSET_CAPTURE)) {
                $dirContent = substr($dirContent, 0, $mNext[0][1]);
            }

            // A. List items <li>
            if (preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', $dirContent, $liMatches)) {
                foreach ($liMatches[1] as $step) {
                    $c = trim(strip_tags(html_entity_decode($step, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    if ($c !== '' && mb_strlen($c) > 3) {
                        // Ignore pure serving notes like "Untuk 2 gelas" or "Serves 4"
                        if (preg_match('/^(?:untuk\s+\d+|serves?\s+\d+)/i', $c)) {
                            continue;
                        }
                        $directions[] = $c;
                    }
                }
            }

            // B. Paragraph items <p>
            if (empty($directions)) {
                if (preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $dirContent, $pMatches)) {
                    foreach ($pMatches[1] as $step) {
                        $c = trim(strip_tags(html_entity_decode($step, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                        $c = preg_replace('/^\d+[\.\)]\s*/', '', $c);
                        if ($c !== '' && mb_strlen($c) > 6) {
                            $directions[] = $c;
                        }
                    }
                }
            }
        }

        // 3. Fallback only if both failed
        if (empty($directions)) {
            $jsonParsed = json_decode($rawDesc, true);
            if (is_array($jsonParsed)) {
                $directions = $jsonParsed;
            }
        }

        if (empty($directions)) {
            $directions = [
                'Siapkan seluruh bahan segar di atas talenan.',
                'Masukkan bahan ke dalam keranjang air fryer dan masak hingga matang keemasan.',
                'Sajikan masakan hangat di atas piring saji dengan garnish.'
            ];
        }

        return [
            'summary' => $summary,
            'ingredients' => $ingredients,
            'directions' => $directions
        ];
    }

    /**
     * Detect recipe language ('en' vs 'id') by analyzing title and culinary keywords.
     */
    public function detectRecipeLanguage(string $title, array $directions = []): string
    {
        $sampleText = ' ' . strtolower($title . ' ' . implode(' ', array_slice($directions, 0, 5))) . ' ';

        // Indonesian culinary keywords & common function words
        $idIndicators = [
            ' dan ', ' dengan ', ' hingga ', ' sampai ', ' masukkan ', ' rebus ', ' panggang ',
            ' tiriskan ', ' panaskan ', ' potong ', ' tumis ', ' garam ', ' minyak ', ' wadah ',
            ' panci ', ' lalu ', ' kemudian ', ' sajikan ', ' sendok ', ' bawang ', ' secukupnya '
        ];
        $idScore = 0;
        foreach ($idIndicators as $w) {
            if (strpos($sampleText, $w) !== false) {
                $idScore++;
            }
        }

        // English culinary keywords & common function words
        $enIndicators = [
            ' and ', ' with ', ' until ', ' place ', ' boil ', ' bake ', ' drain ',
            ' heat ', ' cut ', ' chop ', ' stir ', ' salt ', ' oil ', ' bowl ',
            ' pan ', ' toss ', ' preheat ', ' then ', ' serve ', ' spoon ', ' onion ', ' minutes '
        ];
        $enScore = 0;
        foreach ($enIndicators as $w) {
            if (strpos($sampleText, $w) !== false) {
                $enScore++;
            }
        }

        return ($enScore >= $idScore && $enScore > 0) ? 'en' : 'id';
    }

    /**
     * Clean and normalize cooking action subtitles.
     * Strips "Tangan..." / "Hands...", camera jargon, and ensures direct imperative tone.
     */
    public function cleanCookingInstructionCaption(string $text, string $lang = 'auto'): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Strip surrounding quotes
        $text = trim($text, "\"'`");

        // 1. Strip third-person actor prefixes in Indonesian
        // e.g. "Tangan koki mengisi...", "Tangan mengisi...", "Koki memasukkan...", "Seseorang mengaduk...", "Jari menekan..."
        $text = preg_replace('/^(?:(?:tangan|jari)(?:\s+koki|\s+chef)?|koki|chef|seseorang)\s+/iu', '', $text);

        // 2. Strip third-person actor prefixes in English
        // e.g. "Hands add...", "A hand places...", "The chef stirs...", "Someone pours...", "Chef chops...", "Fingers press..."
        $text = preg_replace('/^(?:(?:the|a)\s+)?(?:hands?|fingers?|chef|cook|person|someone)\s+(?:are\s+|is\s+)?/iu', '', $text);

        // 3. Strip camera observation prefixes
        // e.g. "Close up shot of...", "Shot of...", "View of...", "Overhead shot of..."
        $text = preg_replace('/^(?:(?:close[\s-]*up|overhead|macro|wide|cinematic|camera)\s+)?(?:shot|view|angle)\s+of\s+/iu', '', $text);

        // 4. In Indonesian, convert leading active 'me-' verbs into imperative
        $meMap = [
            '/^menekan\b/iu' => 'Tekan',
            '/^menjatuhkan\b/iu' => 'Masukkan',
            '/^mengisi\b/iu' => 'Isi',
            '/^mengaduk\b/iu' => 'Aduk',
            '/^menata\b/iu' => 'Tata',
            '/^memotong\b/iu' => 'Potong',
            '/^menumis\b/iu' => 'Tumis',
            '/^merebus\b/iu' => 'Rebus',
            '/^memanaskan\b/iu' => 'Panaskan',
            '/^menuangkan\b/iu' => 'Tuangkan',
            '/^menuang\b/iu' => 'Tuang',
            '/^mencampurkan\b/iu' => 'Campurkan',
            '/^mencampur\b/iu' => 'Campur',
            '/^memanggang\b/iu' => 'Panggang',
            '/^menambahkan\b/iu' => 'Tambahkan',
            '/^menyajikan\b/iu' => 'Sajikan',
            '/^mengupas\b/iu' => 'Kupas',
            '/^merendam\b/iu' => 'Rendam',
            '/^mengocok\b/iu' => 'Kocok',
            '/^mengoleskan\b/iu' => 'Oleskan',
            '/^mengoles\b/iu' => 'Oles',
            '/^menggiling\b/iu' => 'Giling',
            '/^menaburkan\b/iu' => 'Taburkan',
            '/^menabur\b/iu' => 'Tabur',
            '/^membalik\b/iu' => 'Balik',
            '/^memeras\b/iu' => 'Peras',
            '/^meniriskan\b/iu' => 'Tiriskan',
            '/^mencincang\b/iu' => 'Cincang',
            '/^memarut\b/iu' => 'Parut',
            '/^mengukus\b/iu' => 'Kukus',
            '/^menyiapkan\b/iu' => 'Siapkan',
            '/^mengangkat\b/iu' => 'Angkat',
            '/^mengeringkan\b/iu' => 'Keringkan',
            '/^membumbui\b/iu' => 'Bumbui',
            '/^mendinginkan\b/iu' => 'Dinginkan',
        ];
        foreach ($meMap as $pattern => $replacement) {
            if (preg_match($pattern, $text)) {
                $text = preg_replace($pattern, $replacement, $text);
                break;
            }
        }

        // 5. In English, convert leading present participles (-ing) and 3rd person singular (-s) into imperative
        $ingMap = [
            '/^filling\b/iu' => 'Fill',
            '/^stirring\b/iu' => 'Stir',
            '/^adding\b/iu' => 'Add',
            '/^pouring\b/iu' => 'Pour',
            '/^chopping\b/iu' => 'Chop',
            '/^cutting\b/iu' => 'Cut',
            '/^mixing\b/iu' => 'Mix',
            '/^heating\b/iu' => 'Heat',
            '/^boiling\b/iu' => 'Boil',
            '/^baking\b/iu' => 'Bake',
            '/^roasting\b/iu' => 'Roast',
            '/^frying\b/iu' => 'Fry',
            '/^whisking\b/iu' => 'Whisk',
            '/^placing\b/iu' => 'Place',
            '/^arranging\b/iu' => 'Arrange',
            '/^seasoning\b/iu' => 'Season',
            '/^draining\b/iu' => 'Drain',
            '/^serving\b/iu' => 'Serve',
            '/^tossing\b/iu' => 'Toss',
            '/^dropping\b/iu' => 'Drop',
            '/^preheating\b/iu' => 'Preheat',
            '/^peeling\b/iu' => 'Peel',
            '/^grating\b/iu' => 'Grate',
            '/^slicing\b/iu' => 'Slice',
            '/^garnishing\b/iu' => 'Garnish',
            '/^spreading\b/iu' => 'Spread',
            '/^brushing\b/iu' => 'Brush',
            '/^flipping\b/iu' => 'Flip',
            '/^simmering\b/iu' => 'Simmer',
            '/^adds\b/iu' => 'Add',
            '/^stirs\b/iu' => 'Stir',
            '/^pours\b/iu' => 'Pour',
            '/^places\b/iu' => 'Place',
            '/^drops\b/iu' => 'Drop',
            '/^chops\b/iu' => 'Chop',
            '/^cuts\b/iu' => 'Cut',
            '/^mixes\b/iu' => 'Mix',
            '/^heats\b/iu' => 'Heat',
            '/^boils\b/iu' => 'Boil',
            '/^bakes\b/iu' => 'Bake',
            '/^whisks\b/iu' => 'Whisk',
            '/^arranges\b/iu' => 'Arrange',
            '/^seasons\b/iu' => 'Season',
            '/^serves\b/iu' => 'Serve',
            '/^tosses\b/iu' => 'Toss',
        ];
        foreach ($ingMap as $pattern => $replacement) {
            if (preg_match($pattern, $text)) {
                $text = preg_replace($pattern, $replacement, $text);
                break;
            }
        }

        // Capitalize first character
        $text = mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);

        // Limit length to ~12 words max for clean typewriter badge display
        $words = preg_split('/\s+/', $text);
        if (count($words) > 12) {
            $text = implode(' ', array_slice($words, 0, 12)) . '...';
        }

        return $text;
    }

    /**
     * Create a new video project and generate its storyboard via Gemini.
     */
    public function createProjectWithStoryboard(
        int $recipeId,
        string $kitchenPresetKey = 'Modern Scandinavian Kitchen',
        string $aspectRatio = '9:16',
        string $mode = 'reels',
        array $options = []
    ): array {
        $recipe = $this->getRecipe($recipeId);
        if (!$recipe) {
            return ['success' => false, 'error' => "Resep ID #{$recipeId} tidak ditemukan."];
        }

        // Robust parsing of directions & ingredients
        $parsedSections = $this->parseRecipeSections($recipe['recipe_description'] ?? '');
        $directions = $parsedSections['directions'];
        $ingredients = $parsedSections['ingredients'];

        // Determine language: auto-detect if not explicitly passed
        $targetLang = $options['target_language'] ?? 'auto';
        if ($targetLang === 'auto' || empty($targetLang)) {
            $detectedLang = $this->detectRecipeLanguage($recipe['recipe_title'] ?? '', $directions);
        } else {
            $detectedLang = in_array(strtolower($targetLang), ['en', 'id']) ? strtolower($targetLang) : 'auto';
            if ($detectedLang === 'auto') {
                $detectedLang = $this->detectRecipeLanguage($recipe['recipe_title'] ?? '', $directions);
            }
        }
        $options['target_language'] = $detectedLang;
        $stepPrefix = ($detectedLang === 'en') ? 'Step' : 'Langkah';

        $recipeData = [
            'recipe_title' => $recipe['recipe_title'],
            'directions' => $directions,
            'ingredients' => $ingredients
        ];

        // Load primary model and fallbacks from tbl_ai_config if not explicitly passed
        if (empty($options['primary_model'])) {
            $cfgRes = $this->db->query("SELECT gemini_model, gemini_fallback_models FROM tbl_ai_config WHERE id = 1 LIMIT 1");
            if ($cfgRes && $cfgRow = $cfgRes->fetch_assoc()) {
                if (!empty($cfgRow['gemini_model'])) {
                    $options['primary_model'] = trim($cfgRow['gemini_model']);
                }
                if (!empty($cfgRow['gemini_fallback_models'])) {
                    $dec = json_decode($cfgRow['gemini_fallback_models'], true);
                    if (is_array($dec)) {
                        $options['custom_fallbacks'] = $dec;
                    }
                }
            }
        }

        // Call Gemini Resilient Storyboard Engine
        $storyboardRes = $this->geminiClient->craftRecipeVideoStoryboardResilient(
            $this->db,
            $recipeData,
            $kitchenPresetKey,
            $aspectRatio,
            $mode,
            $options
        );

        if (!$storyboardRes['success']) {
            return $storyboardRes;
        }

        $sbData = $storyboardRes['data'];
        $masterAnchor = $sbData['master_anchor'];
        $rawScenes = $sbData['scenes'] ?? [];

        // In FULL mode: enforce strictly 1:1 deterministic mapping to recipe directions!
        $finalScenes = [];
        if ($mode === 'full' && !empty($directions)) {
            $totalDirs = count($directions);
            for ($i = 0; $i < $totalDirs; $i++) {
                $dirStep = $directions[$i];
                $geminiScene = $rawScenes[$i] ?? null;

                $rawAction = !empty($geminiScene['visual_action']) ? $geminiScene['visual_action'] : '';
                $cleanAction = $this->cleanCookingInstructionCaption($rawAction, $detectedLang);
                if (empty($cleanAction)) {
                    $cleanAction = $this->cleanCookingInstructionCaption($dirStep, $detectedLang);
                    if (empty($cleanAction)) {
                        $cleanAction = "{$stepPrefix} " . ($i + 1);
                    }
                }

                if ($geminiScene) {
                    $finalScenes[] = [
                        'scene_order' => $i + 1,
                        'direction_source' => $dirStep,
                        'visual_action' => $cleanAction,
                        'prompt_t2i' => !empty($geminiScene['prompt_t2i']) ? $geminiScene['prompt_t2i'] : "Cinematic culinary scene of preparing {$recipe['recipe_title']}, {$dirStep}. {$masterAnchor}",
                        'prompt_i2v' => !empty($geminiScene['prompt_i2v']) ? $geminiScene['prompt_i2v'] : "Smooth cinematic camera push in on cooking action, 4k 60fps",
                    ];
                } else {
                    // Backfill if Gemini returned fewer scenes so no step is ever skipped
                    $backfillAction = $this->cleanCookingInstructionCaption($dirStep, $detectedLang);
                    if (empty($backfillAction)) {
                        $backfillAction = "{$stepPrefix} " . ($i + 1);
                    }
                    $finalScenes[] = [
                        'scene_order' => $i + 1,
                        'direction_source' => $dirStep,
                        'visual_action' => $backfillAction,
                        'prompt_t2i' => "Cinematic high-end commercial close-up shot of {$recipe['recipe_title']}, action: {$dirStep}. {$masterAnchor}",
                        'prompt_i2v' => "Slow pan left focusing on dynamic cooking action, soft cinematic lighting",
                    ];
                }
            }
        } else {
            // For reels mode: ensure strictly sequential order 1..N without duplicates
            foreach ($rawScenes as $k => $sc) {
                $cleanAction = $this->cleanCookingInstructionCaption($sc['visual_action'] ?? '', $detectedLang);
                if (empty($cleanAction)) {
                    $cleanAction = "{$stepPrefix} " . ($k + 1);
                }
                $finalScenes[] = [
                    'scene_order' => $k + 1,
                    'direction_source' => $sc['direction_source'] ?? '',
                    'visual_action' => $cleanAction,
                    'prompt_t2i' => $sc['prompt_t2i'] ?? '',
                    'prompt_i2v' => $sc['prompt_i2v'] ?? ''
                ];
            }
        }

        $totalScenes = count($finalScenes);

        // 1. Insert Project
        $projectTitle = "Video: " . $recipe['recipe_title'];
        $stmt = $this->db->prepare("INSERT INTO tbl_ai_video_projects 
            (recipe_id, project_title, kitchen_preset, video_model, aspect_ratio, clip_duration, total_scenes, status, master_anchor_prompt) 
            VALUES (?, ?, ?, 'omni_flash', ?, 4, ?, 'storyboard_ready', ?)");
        $stmt->bind_param('isssis', $recipeId, $projectTitle, $kitchenPresetKey, $aspectRatio, $totalScenes, $masterAnchor);
        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'Gagal menyimpan proyek video: ' . $this->db->error];
        }
        $projectId = $this->db->insert_id;
        $stmt->close();

        // 2. Insert Scenes
        $insertScene = $this->db->prepare("INSERT INTO tbl_ai_video_scenes 
            (project_id, scene_order, direction_source_text, visual_action_desc, prompt_t2i, prompt_i2v, frame_status, video_status) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending')");

        $insertedScenes = [];
        foreach ($finalScenes as $sc) {
            $order = (int)$sc['scene_order'];
            $dirText = $sc['direction_source'];
            $actionDesc = $sc['visual_action'];
            $promptT2i = $sc['prompt_t2i'];
            $promptI2v = $sc['prompt_i2v'];

            $insertScene->bind_param('iissss', $projectId, $order, $dirText, $actionDesc, $promptT2i, $promptI2v);
            $insertScene->execute();
            $sceneId = $this->db->insert_id;

            $insertedScenes[] = [
                'id' => $sceneId,
                'project_id' => $projectId,
                'scene_order' => $order,
                'direction_source_text' => $dirText,
                'visual_action_desc' => $actionDesc,
                'prompt_t2i' => $promptT2i,
                'prompt_i2v' => $promptI2v,
                'frame_status' => 'pending',
                'frame_image_path' => null,
                'video_status' => 'pending',
                'video_clip_path' => null
            ];
        }
        $insertScene->close();

        return [
            'success' => true,
            'project' => $this->getProject($projectId),
            'project_id' => $projectId,
            'recipe_id' => $recipeId,
            'project_title' => $projectTitle,
            'kitchen_preset' => $kitchenPresetKey,
            'aspect_ratio' => $aspectRatio,
            'total_scenes' => $totalScenes,
            'model_used' => $storyboardRes['model_used'] ?? 'Gemini',
            'key_used' => $storyboardRes['key_used'] ?? 'Pool API',
            'scenes' => $insertedScenes
        ];
    }

    /**
     * Get complete project data along with all scenes.
     */
    public function getProject(int $projectId): ?array
    {
        $stmt = $this->db->prepare("SELECT p.*, r.recipe_title, r.recipe_image 
            FROM tbl_ai_video_projects p 
            LEFT JOIN tbl_recipes r ON p.recipe_id = r.recipe_id 
            WHERE p.id = ? LIMIT 1");
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $res = $stmt->get_result();
        $project = $res->fetch_assoc();
        $stmt->close();

        if (!$project) return null;

        $sceneStmt = $this->db->prepare("SELECT * FROM tbl_ai_video_scenes WHERE project_id = ? ORDER BY scene_order ASC, id ASC");
        $sceneStmt->bind_param('i', $projectId);
        $sceneStmt->execute();
        $scenesRes = $sceneStmt->get_result();
        $scenes = [];
        while ($sc = $scenesRes->fetch_assoc()) {
            $scenes[] = $sc;
        }
        $sceneStmt->close();

        $project['scenes'] = $scenes;
        return $project;
    }

    /**
     * Reorder scenes for a project.
     *
     * @param int $projectId
     * @param array $sceneIds Ordered array of scene IDs [id1, id2, ...]
     * @return array
     */
    public function reorderScenes(int $projectId, array $sceneIds): array
    {
        if (empty($sceneIds)) {
            return ['success' => false, 'error' => 'Daftar ID scene kosong.'];
        }

        $stmt = $this->db->prepare("UPDATE tbl_ai_video_scenes SET scene_order = ? WHERE id = ? AND project_id = ?");
        foreach ($sceneIds as $newIdx => $sId) {
            $order = $newIdx + 1;
            $sIdInt = (int)$sId;
            $stmt->bind_param('iii', $order, $sIdInt, $projectId);
            $stmt->execute();
        }
        $stmt->close();

        $updatedProject = $this->getProject($projectId);
        return [
            'success' => true,
            'message' => 'Urutan adegan berhasil diperbarui.',
            'project' => $updatedProject,
            'scenes' => $updatedProject['scenes'] ?? []
        ];
    }

    /**
     * Update text / descriptions for a scene.
     */
    public function updateSceneText(int $sceneId, array $data): array
    {
        $fields = [];
        $params = [];
        $types = '';

        if (isset($data['visual_action_desc'])) {
            $fields[] = "`visual_action_desc` = ?";
            $params[] = trim($data['visual_action_desc']);
            $types .= 's';
        }
        if (isset($data['direction_source_text'])) {
            $fields[] = "`direction_source_text` = ?";
            $params[] = trim($data['direction_source_text']);
            $types .= 's';
        }
        if (isset($data['prompt_t2i'])) {
            $fields[] = "`prompt_t2i` = ?";
            $params[] = trim($data['prompt_t2i']);
            $types .= 's';
        }
        if (isset($data['prompt_i2v'])) {
            $fields[] = "`prompt_i2v` = ?";
            $params[] = trim($data['prompt_i2v']);
            $types .= 's';
        }

        if (empty($fields)) {
            return ['success' => false, 'error' => 'Tidak ada field yang diupdate.'];
        }

        $types .= 'i';
        $params[] = $sceneId;

        $sql = "UPDATE tbl_ai_video_scenes SET " . implode(', ', $fields) . " WHERE id = ? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            return ['success' => false, 'error' => 'Gagal mengupdate scene: ' . $this->db->error];
        }

        return ['success' => true, 'message' => 'Teks scene berhasil disimpan.'];
    }

    /**
     * Submit Start Frame T2I Task to G-Labs for a specific scene.
     */
    public function submitFrameTask(int $sceneId, ?string $customPrompt = null): array
    {
        $stmt = $this->db->prepare("SELECT s.*, p.aspect_ratio FROM tbl_ai_video_scenes s JOIN tbl_ai_video_projects p ON s.project_id = p.id WHERE s.id = ? LIMIT 1");
        $stmt->bind_param('i', $sceneId);
        $stmt->execute();
        $scene = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$scene) {
            return ['success' => false, 'error' => "Scene ID #{$sceneId} tidak ditemukan."];
        }

        $prompt = !empty($customPrompt) ? trim($customPrompt) : trim($scene['prompt_t2i']);
        if (empty($prompt)) {
            return ['success' => false, 'error' => 'Prompt T2I kosong.'];
        }

        $glabsCfg = $this->getGlabsConfig();
        $aspectRatio = $scene['aspect_ratio'] ?: '9:16';

        $submitRes = $this->glabsClient->submitImageTask($glabsCfg['host'], $glabsCfg['port'], $glabsCfg['api_key'], [
            'prompt' => $prompt,
            'model' => 'nano_banana_2',
            'aspect_ratio' => $aspectRatio
        ]);

        if (!$submitRes['success']) {
            $this->db->query("UPDATE tbl_ai_video_scenes SET frame_status = 'failed', error_message = '" . $this->db->real_escape_string($submitRes['error']) . "' WHERE id = {$sceneId}");
            return $submitRes;
        }

        $taskId = $submitRes['task_id'];
        $safePrompt = $this->db->real_escape_string($prompt);
        $this->db->query("UPDATE tbl_ai_video_scenes SET frame_task_id = '{$taskId}', frame_status = 'running', prompt_t2i = '{$safePrompt}', error_message = NULL WHERE id = {$sceneId}");
        $this->db->query("UPDATE tbl_ai_video_projects SET status = 'generating_frames' WHERE id = {$scene['project_id']}");

        return [
            'success' => true,
            'scene_id' => $sceneId,
            'task_id' => $taskId,
            'message' => 'Task Start Frame berhasil diantrekan di G-Labs.'
        ];
    }

    /**
     * Poll status of a Start Frame task and download image upon completion.
     */
    public function pollFrameTask(int $sceneId): array
    {
        $stmt = $this->db->prepare("SELECT s.*, p.id as project_id FROM tbl_ai_video_scenes s JOIN tbl_ai_video_projects p ON s.project_id = p.id WHERE s.id = ? LIMIT 1");
        $stmt->bind_param('i', $sceneId);
        $stmt->execute();
        $scene = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$scene || empty($scene['frame_task_id'])) {
            return ['success' => false, 'error' => 'Scene atau task ID tidak ditemukan.'];
        }

        $glabsCfg = $this->getGlabsConfig();
        $pollRes = $this->glabsClient->getTaskStatus($glabsCfg['host'], $glabsCfg['port'], $glabsCfg['api_key'], $scene['frame_task_id']);

        if (!$pollRes['success']) {
            return $pollRes;
        }

        $status = strtolower($pollRes['status'] ?? 'pending');

        if ($status === 'completed') {
            $results = $pollRes['results'] ?? [];
            $imgUrlOrName = !empty($results[0]) ? $results[0] : '';
            if (empty($imgUrlOrName)) {
                return ['success' => false, 'error' => 'G-Labs melaporkan status completed namun tidak ada file hasil.'];
            }

            $baseDir = dirname(__DIR__);
            $filename = "frame_p{$scene['project_id']}_s{$scene['scene_order']}_" . time() . ".img";
            $destPath = $baseDir . "/upload/ai_video_frames/{$filename}";
            $relPath = "upload/ai_video_frames/{$filename}";

            $dlRes = $this->glabsClient->downloadFile($glabsCfg['host'], $glabsCfg['port'], $imgUrlOrName, $destPath, $glabsCfg['api_key']);
            if (!$dlRes['success']) {
                return $dlRes;
            }

            $imageInfo = @getimagesize($destPath);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = $imageInfo['mime'] ?? '';
            if (!isset($extensions[$mime])) {
                @unlink($destPath);
                return ['success' => false, 'error' => 'G-Labs mengembalikan format Start Frame yang tidak didukung (JPG, PNG, WEBP).'];
            }
            $imagePath = substr($destPath, 0, -4) . '.' . $extensions[$mime];
            if (!@rename($destPath, $imagePath)) {
                return ['success' => false, 'error' => 'Gagal menyimpan Start Frame dengan ekstensi gambar yang sesuai.'];
            }
            $relPath = "upload/ai_video_frames/" . basename($imagePath);

            $safeRel = $this->db->real_escape_string($relPath);
            $this->db->query("UPDATE tbl_ai_video_scenes SET frame_status = 'completed', frame_image_path = '{$safeRel}', error_message = NULL WHERE id = {$sceneId}");

            // Check if all scenes in project are completed
            $checkAll = $this->db->query("SELECT COUNT(*) as remaining FROM tbl_ai_video_scenes WHERE project_id = {$scene['project_id']} AND frame_status != 'completed'");
            if ($checkAll && ($row = $checkAll->fetch_assoc()) && (int)$row['remaining'] === 0) {
                $this->db->query("UPDATE tbl_ai_video_projects SET status = 'frames_completed' WHERE id = {$scene['project_id']}");
            }

            return [
                'success' => true,
                'status' => 'completed',
                'scene_id' => $sceneId,
                'image_path' => $relPath,
                'image_url' => $relPath
            ];
        }

        if ($status === 'failed') {
            $errMsg = $pollRes['error'] ?? 'Task render Start Frame gagal.';
            $safeErr = $this->db->real_escape_string($errMsg);
            $this->db->query("UPDATE tbl_ai_video_scenes SET frame_status = 'failed', error_message = '{$safeErr}' WHERE id = {$sceneId}");
            return ['success' => false, 'status' => 'failed', 'error' => $errMsg];
        }

        return [
            'success' => true,
            'status' => $status,
            'scene_id' => $sceneId
        ];
    }

    /**
     * Submit Video I2V Task to G-Labs for a specific scene using its Start Frame.
     */
    public function submitClipTask(int $sceneId, array $videoConfig = []): array
    {
        $stmt = $this->db->prepare("SELECT s.*, p.aspect_ratio, p.clip_duration, p.video_model FROM tbl_ai_video_scenes s JOIN tbl_ai_video_projects p ON s.project_id = p.id WHERE s.id = ? LIMIT 1");
        $stmt->bind_param('i', $sceneId);
        $stmt->execute();
        $scene = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$scene) {
            return ['success' => false, 'error' => "Scene ID #{$sceneId} tidak ditemukan."];
        }

        if (empty($scene['frame_image_path'])) {
            return ['success' => false, 'error' => 'Start Frame belum digenerate untuk scene ini.'];
        }

        $baseDir = dirname(__DIR__);
        $frameAbsPath = $baseDir . '/' . ltrim($scene['frame_image_path'], '/');
        if (!file_exists($frameAbsPath) || filesize($frameAbsPath) === 0) {
            return ['success' => false, 'error' => 'File gambar Start Frame fisik tidak ditemukan.'];
        }

        $imgData = @file_get_contents($frameAbsPath);
        if (empty($imgData)) {
            return ['success' => false, 'error' => 'Gagal membaca file gambar Start Frame.'];
        }

        $imageInfo = @getimagesizefromstring($imgData);
        $mime = $imageInfo['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['success' => false, 'error' => 'Format Start Frame tidak didukung untuk I2V (JPG, PNG, WEBP).'];
        }
        $base64Img = 'data:' . $mime . ';base64,' . base64_encode($imgData);

        $prompt = !empty($videoConfig['prompt_i2v']) ? trim($videoConfig['prompt_i2v']) : trim($scene['prompt_i2v']);
        $model = $videoConfig['video_model'] ?? ($scene['video_model'] ?: 'omni_flash');
        $duration = (int)($videoConfig['clip_duration'] ?? ($scene['clip_duration'] ?: 4));
        $aspectRatio = $videoConfig['aspect_ratio'] ?? ($scene['aspect_ratio'] ?: '9:16');

        $glabsCfg = $this->getGlabsConfig();

        $submitRes = $this->glabsClient->submitVideoTask($glabsCfg['host'], $glabsCfg['port'], $glabsCfg['api_key'], [
            'prompt' => $prompt,
            'model' => $model,
            'aspect_ratio' => $aspectRatio,
            'mode' => 'start_image',
            'video_length' => $duration,
            'reference_images' => [$base64Img],
            'resolution' => ['720p']
        ]);

        if (!$submitRes['success']) {
            $this->db->query("UPDATE tbl_ai_video_scenes SET video_status = 'failed', error_message = '" . $this->db->real_escape_string($submitRes['error']) . "' WHERE id = {$sceneId}");
            return $submitRes;
        }

        $taskId = $submitRes['task_id'];
        $safePrompt = $this->db->real_escape_string($prompt);
        $this->db->query("UPDATE tbl_ai_video_scenes SET video_task_id = '{$taskId}', video_status = 'running', prompt_i2v = '{$safePrompt}', error_message = NULL WHERE id = {$sceneId}");
        $this->db->query("UPDATE tbl_ai_video_projects SET status = 'generating_videos', clip_duration = {$duration}, video_model = '" . $this->db->real_escape_string($model) . "' WHERE id = {$scene['project_id']}");

        return [
            'success' => true,
            'scene_id' => $sceneId,
            'task_id' => $taskId,
            'message' => 'Task Video I2V berhasil diantrekan di G-Labs.'
        ];
    }

    /**
     * Poll status of an I2V Video Clip task and download .mp4 upon completion.
     */
    public function pollClipTask(int $sceneId): array
    {
        $stmt = $this->db->prepare("SELECT s.*, p.id as project_id FROM tbl_ai_video_scenes s JOIN tbl_ai_video_projects p ON s.project_id = p.id WHERE s.id = ? LIMIT 1");
        $stmt->bind_param('i', $sceneId);
        $stmt->execute();
        $scene = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$scene || empty($scene['video_task_id'])) {
            return ['success' => false, 'error' => 'Scene atau video task ID tidak ditemukan.'];
        }

        $glabsCfg = $this->getGlabsConfig();
        $pollRes = $this->glabsClient->getTaskStatus($glabsCfg['host'], $glabsCfg['port'], $glabsCfg['api_key'], $scene['video_task_id']);

        if (!$pollRes['success']) {
            return $pollRes;
        }

        $status = strtolower($pollRes['status'] ?? 'pending');

        if ($status === 'completed') {
            $results = $pollRes['results'] ?? [];
            $clipUrlOrName = !empty($results[0]) ? $results[0] : '';
            if (empty($clipUrlOrName)) {
                return ['success' => false, 'error' => 'G-Labs melaporkan status completed namun tidak ada file klip video.'];
            }

            $baseDir = dirname(__DIR__);
            $filename = "clip_p{$scene['project_id']}_s{$scene['scene_order']}_" . time() . ".mp4";
            $destPath = $baseDir . "/upload/ai_video_clips/{$filename}";
            $relPath = "upload/ai_video_clips/{$filename}";

            $dlRes = $this->glabsClient->downloadFile($glabsCfg['host'], $glabsCfg['port'], $clipUrlOrName, $destPath, $glabsCfg['api_key']);
            if (!$dlRes['success']) {
                return $dlRes;
            }

            $safeRel = $this->db->real_escape_string($relPath);
            $this->db->query("UPDATE tbl_ai_video_scenes SET video_status = 'completed', video_clip_path = '{$safeRel}', error_message = NULL WHERE id = {$sceneId}");

            // Check if all clips in project are completed
            $checkAll = $this->db->query("SELECT COUNT(*) as remaining FROM tbl_ai_video_scenes WHERE project_id = {$scene['project_id']} AND video_status != 'completed'");
            if ($checkAll && ($row = $checkAll->fetch_assoc()) && (int)$row['remaining'] === 0) {
                $this->db->query("UPDATE tbl_ai_video_projects SET status = 'videos_completed' WHERE id = {$scene['project_id']}");
            }

            return [
                'success' => true,
                'status' => 'completed',
                'scene_id' => $sceneId,
                'video_clip_path' => $relPath,
                'video_url' => $relPath
            ];
        }

        if ($status === 'failed') {
            $errMsg = $pollRes['error'] ?? 'Task render Video Klip gagal.';
            $safeErr = $this->db->real_escape_string($errMsg);
            $this->db->query("UPDATE tbl_ai_video_scenes SET video_status = 'failed', error_message = '{$safeErr}' WHERE id = {$sceneId}");
            return ['success' => false, 'status' => 'failed', 'error' => $errMsg];
        }

        return [
            'success' => true,
            'status' => $status,
            'scene_id' => $sceneId
        ];
    }

    /**
     * Concatenate all video clips in a project using FFmpeg, with optional motion graphics text overlays.
     */
    public function stitchProject(int $projectId, array $options = []): array
    {
        $project = $this->getProject($projectId);
        if (!$project) {
            return ['success' => false, 'error' => "Proyek ID #{$projectId} tidak ditemukan."];
        }

        $scenes = $project['scenes'] ?? [];
        if (empty($scenes)) {
            return ['success' => false, 'error' => 'Proyek tidak memiliki scene adegan.'];
        }

        $baseDir = dirname(__DIR__);
        $enableOverlay = !empty($options['enable_text_overlay']) && (
            $options['enable_text_overlay'] === true || 
            $options['enable_text_overlay'] === '1' || 
            $options['enable_text_overlay'] === 'true'
        );
        $overlayTheme = $options['overlay_theme'] ?? 'dark_glass';
        $overlayPosition = $options['overlay_position'] ?? 'bottom';
        $overlayBrandName = isset($options['overlay_brand_name']) ? trim($options['overlay_brand_name']) : '';
        $overlayWidthRatio = !empty($options['overlay_width_ratio']) ? (float)$options['overlay_width_ratio'] : 0.86;
        $overlayPrefix = trim($options['overlay_step_prefix'] ?? 'Step ');
        if ($overlayPrefix !== '' && !str_ends_with($overlayPrefix, ' ')) {
            $overlayPrefix .= ' ';
        }
        $customTexts = $options['overlay_custom_texts'] ?? [];

        $clipPaths = [];
        $tempAnnotatedClips = [];

        foreach ($scenes as $sc) {
            if (empty($sc['video_clip_path'])) {
                return ['success' => false, 'error' => "Scene #{$sc['scene_order']} belum memiliki klip video yang selesai."];
            }

            $currentClip = $sc['video_clip_path'];

            // Apply motion graphics text overlay if enabled
            if ($enableOverlay) {
                $orderNum = (int)($sc['scene_order'] ?? 1);
                $stepLabel = ($overlayPrefix ?: 'STEP ') . $orderNum;
                $sceneId = (int)$sc['id'];

                $stepText = $customTexts[$sceneId] ?? ($customTexts[(string)$sceneId] ?? ($customTexts[$orderNum] ?? ''));
                if (empty($stepText)) {
                    $stepText = !empty($sc['visual_action_desc']) ? $sc['visual_action_desc'] : $sc['direction_source_text'];
                }

                $annotatedFilename = 'annotated_' . time() . '_' . bin2hex(random_bytes(2)) . '_' . basename($currentClip);
                $annotatedRelPath = 'upload/video/clips/' . $annotatedFilename;

                $overlayRes = $this->ffmpegService->applyTextOverlayToClip($currentClip, $annotatedRelPath, [
                    'step_label' => $stepLabel,
                    'text' => $stepText,
                    'theme' => $overlayTheme,
                    'position' => $overlayPosition,
                    'aspect_ratio' => $project['aspect_ratio'] ?? '9:16',
                    'brand_name' => $overlayBrandName,
                    'width_ratio' => $overlayWidthRatio
                ]);

                if ($overlayRes['success']) {
                    $currentClip = $annotatedRelPath;
                    $tempAnnotatedClips[] = $overlayRes['absolute_path'];
                }
            }

            $clipPaths[] = $currentClip;
        }

        $this->db->query("UPDATE tbl_ai_video_projects SET status = 'stitching' WHERE id = {$projectId}");

        $cleanTitle = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($project['project_title']));
        $cleanTitle = substr($cleanTitle, 0, 30);
        $outputFilename = "recipe_video_{$project['recipe_id']}_{$cleanTitle}_" . time() . ".mp4";
        $relOutputPath = "upload/video/{$outputFilename}";

        $stitchRes = $this->ffmpegService->stitchScenes($clipPaths, $relOutputPath, $options);

        // Cleanup temporary annotated clips
        foreach ($tempAnnotatedClips as $tempFile) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

        if (!$stitchRes['success']) {
            $this->db->query("UPDATE tbl_ai_video_projects SET status = 'failed' WHERE id = {$projectId}");
            return $stitchRes;
        }

        $fileSize = $stitchRes['file_size'] ?? 0;
        $safePath = $this->db->real_escape_string($relOutputPath);
        $this->db->query("UPDATE tbl_ai_video_projects SET status = 'completed', stitched_video_path = '{$safePath}', stitched_file_size = {$fileSize} WHERE id = {$projectId}");

        return [
            'success' => true,
            'project_id' => $projectId,
            'video_path' => $relOutputPath,
            'video_url' => $relOutputPath,
            'file_size' => $fileSize,
            'poster_path' => $stitchRes['poster_path'] ?? null,
            'message' => 'Video resep utuh berhasil disatukan dengan FFmpeg' . ($enableOverlay ? ' (dengan Motion Graphics Teks Mengetik)' : '') . '!'
        ];
    }

    /**
     * Save/apply the final stitched video to the recipe in tbl_recipes.
     */
    public function applyToRecipe(int $projectId): array
    {
        $project = $this->getProject($projectId);
        if (!$project) {
            return ['success' => false, 'error' => "Proyek ID #{$projectId} tidak ditemukan."];
        }

        if (empty($project['stitched_video_path'])) {
            return ['success' => false, 'error' => 'Video utuh belum digabungkan. Silakan jalankan stitch FFmpeg terlebih dahulu.'];
        }

        $recipeId = (int)$project['recipe_id'];
        $videoFilename = basename($project['stitched_video_path']);

        $stmt = $this->db->prepare("UPDATE tbl_recipes SET content_type = 'Upload', video_url = ?, last_update = NOW() WHERE recipe_id = ?");
        $stmt->bind_param('si', $videoFilename, $recipeId);
        $success = $stmt->execute();
        $stmt->close();

        if (!$success) {
            return ['success' => false, 'error' => 'Gagal memperbarui tabel tbl_recipes: ' . $this->db->error];
        }

        return [
            'success' => true,
            'recipe_id' => $recipeId,
            'video_filename' => $videoFilename,
            'video_url' => 'upload/video/' . $videoFilename,
            'message' => 'Video berhasil disimpan dan aktif pada resep!'
        ];
    }
}
