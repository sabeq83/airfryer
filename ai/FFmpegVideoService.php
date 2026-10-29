<?php

namespace Ai;

use Exception;

class FFmpegVideoService
{
    private string $ffmpegBinary;

    public function __construct(?string $binary = null)
    {
        if ($binary !== null && file_exists($binary)) {
            $this->ffmpegBinary = $binary;
        } elseif (file_exists('/opt/homebrew/bin/ffmpeg')) {
            $this->ffmpegBinary = '/opt/homebrew/bin/ffmpeg';
        } elseif (file_exists('/usr/local/bin/ffmpeg')) {
            $this->ffmpegBinary = '/usr/local/bin/ffmpeg';
        } elseif (file_exists('/usr/bin/ffmpeg')) {
            $this->ffmpegBinary = '/usr/bin/ffmpeg';
        } else {
            $this->ffmpegBinary = 'ffmpeg';
        }
    }

    /**
     * Check if FFmpeg binary is available and executable.
     */
    public function checkInstalled(): array
    {
        $cmd = escapeshellcmd($this->ffmpegBinary) . " -version 2>&1";
        $output = [];
        $returnVar = 0;
        @exec($cmd, $output, $returnVar);

        if ($returnVar === 0 && !empty($output)) {
            return [
                'success' => true,
                'binary' => $this->ffmpegBinary,
                'version' => $output[0] ?? 'FFmpeg detected'
            ];
        }

        return [
            'success' => false,
            'binary' => $this->ffmpegBinary,
            'error' => 'FFmpeg tidak ditemukan pada server atau tidak dapat dieksekusi.'
        ];
    }

    /**
     * Locate an available TrueType font file on the server.
     */
    public function findFontFile(): ?string
    {
        $baseDir = dirname(__DIR__);
        $candidates = [
            $baseDir . '/assets/fonts/Inter-SemiBold.ttf',
            $baseDir . '/assets/fonts/Arial.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            '/System/Library/Fonts/Supplemental/Helvetica.ttf',
            '/Library/Fonts/Arial.ttf',
            '/System/Library/Fonts/Geneva.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'
        ];

        foreach ($candidates as $cand) {
            if (file_exists($cand)) {
                return $cand;
            }
        }
        return null;
    }

    /**
     * Helper to draw a filled rounded rectangle in GD.
     */
    private function drawFilledRoundedRectangle($img, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        $radius = max(1, min($radius, (int)(($x2 - $x1) / 2), (int)(($y2 - $y1) / 2)));
        imagefilledellipse($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($img, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($img, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    }

    /**
     * Helper to wrap text into max 2 lines for video overlay.
     */
    private function wrapTextToWidth(string $text, int $fontSize, string $fontFile, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', trim($text));
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $testLine = ($currentLine === '') ? $word : ($currentLine . ' ' . $word);
            $box = imagettfbbox($fontSize, 0, $fontFile, $testLine);
            $w = abs($box[4] - $box[0]);
            if ($w > $maxWidth && $currentLine !== '') {
                $lines[] = $currentLine;
                $currentLine = $word;
            } else {
                $currentLine = $testLine;
            }
        }
        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }
        return array_slice($lines, 0, 2);
    }

    /**
     * Get video width and height using ffprobe.
     * Defaults to [720, 1280] for 9:16 or [1280, 720] for 16:9 if probe fails.
     */
    public function getVideoDimensions(string $videoPath, string $aspectRatio = '9:16'): array
    {
        $default = str_contains($aspectRatio, '16:9') ? [1280, 720] : [720, 1280];

        $baseDir = dirname(__DIR__);
        $absPath = (str_starts_with($videoPath, '/') || (strlen($videoPath) > 1 && $videoPath[1] === ':'))
            ? $videoPath
            : $baseDir . '/' . ltrim($videoPath, '/');

        if (!file_exists($absPath)) {
            return $default;
        }

        $ffprobeBin = str_replace('ffmpeg', 'ffprobe', $this->ffmpegBinary);
        if (!file_exists($ffprobeBin) && !shell_exec("which ffprobe")) {
            $ffprobeBin = 'ffprobe';
        }

        $cmd = escapeshellcmd($ffprobeBin) . " -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 " . escapeshellarg($absPath) . " 2>&1";
        $output = @shell_exec($cmd);
        if ($output && preg_match('/(\d+)x(\d+)/', trim($output), $m)) {
            $w = (int)$m[1];
            $h = (int)$m[2];
            if ($w > 0 && $h > 0) {
                return [$w, $h];
            }
        }

        return $default;
    }

    /**
     * Render a sequence of transparent PNG frames for typing text animation.
     */
    public function renderTypingOverlayFrames(string $outputDir, string $stepLabel, string $text, array $options = []): array
    {
        if (!function_exists('imagecreatetruecolor')) {
            return ['success' => false, 'error' => 'Ekstensi PHP GD tidak tersedia di server.'];
        }

        $fontFile = $this->findFontFile();
        if (!$fontFile) {
            return ['success' => false, 'error' => 'File font TrueType (Arial/Inter) tidak ditemukan di server.'];
        }

        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0777, true);
        }

        $videoWidth = (int)($options['video_width'] ?? 720);
        $videoHeight = (int)($options['video_height'] ?? 1280);
        $widthRatio = (float)($options['width_ratio'] ?? 0.86);
        $theme = $options['theme'] ?? 'dark_glass';
        $brandName = isset($options['brand_name']) ? trim($options['brand_name']) : '';

        // Calculate dynamic badge width relative to actual video width (centered!)
        $badgeW = (int)round($videoWidth * $widthRatio);
        if ($badgeW % 2 !== 0) $badgeW++; // FFmpeg prefers even widths

        // Calculate height and font size based on theme & resolution
        $isFloatingPill = ($theme === 'floating_pill');
        if ($isFloatingPill) {
            $badgeH = (int)round($videoHeight * 0.058); // ~74px on 720p
            $fontSize = (int)round($videoWidth * 0.024); // ~17pt on 720p
            $badgeRadius = (int)round($badgeH / 2);
        } else {
            $badgeH = (int)round($videoHeight * 0.105); // ~134px on 720p
            $fontSize = (int)round($videoWidth * 0.026); // ~19pt on 720p
            $badgeRadius = (int)round($videoWidth * 0.022); // ~16px
        }
        if ($badgeH % 2 !== 0) $badgeH++;

        $maxTextW = $badgeW - (int)round($badgeW * 0.12);

        // Color Presets
        $hasBorder = false;
        $borderR = 0; $borderG = 0; $borderB = 0; $borderAlpha = 127;

        switch ($theme) {
            case 'minimal_white':
                $bgR = 255; $bgG = 255; $bgB = 255; $bgAlpha = 15;
                $hasBorder = true; $borderR = 255; $borderG = 255; $borderB = 255; $borderAlpha = 50;
                $tagR = 15; $tagG = 23; $tagB = 42;
                $tagTextR = 255; $tagTextG = 255; $tagTextB = 255;
                $textR = 15; $textG = 23; $textB = 42;
                $metaR = 100; $metaG = 116; $metaB = 139;
                break;
            case 'amber':
                $bgR = 26; $bgG = 16; $bgB = 8; $bgAlpha = 23;
                $hasBorder = true; $borderR = 245; $borderG = 158; $borderB = 11; $borderAlpha = 80;
                $tagR = 245; $tagG = 158; $tagB = 11;
                $tagTextR = 41; $tagTextG = 23; $tagTextB = 10;
                $textR = 255; $textG = 251; $textB = 235;
                $metaR = 253; $metaG = 230; $metaB = 138;
                break;
            case 'neon':
                $bgR = 10; $bgG = 15; $bgB = 30; $bgAlpha = 18;
                $hasBorder = true; $borderR = 6; $borderG = 182; $borderB = 212; $borderAlpha = 40;
                $tagR = 6; $tagG = 182; $tagB = 212;
                $tagTextR = 0; $tagTextG = 0; $tagTextB = 0;
                $textR = 255; $textG = 255; $textB = 255;
                $metaR = 103; $metaG = 232; $metaB = 249;
                break;
            case 'floating_pill':
                $bgR = 15; $bgG = 23; $bgB = 42; $bgAlpha = 15;
                $hasBorder = true; $borderR = 255; $borderG = 255; $borderB = 255; $borderAlpha = 100;
                $tagR = 16; $tagG = 185; $tagB = 129;
                $tagTextR = 255; $tagTextG = 255; $tagTextB = 255;
                $textR = 255; $textG = 255; $textB = 255;
                $metaR = 203; $metaG = 213; $metaB = 225;
                break;
            case 'dark_glass':
            default:
                $bgR = 15; $bgG = 23; $bgB = 42; $bgAlpha = 25;
                $hasBorder = true; $borderR = 255; $borderG = 255; $borderB = 255; $borderAlpha = 110;
                $tagR = 16; $tagG = 185; $tagB = 129;
                $tagTextR = 255; $tagTextG = 255; $tagTextB = 255;
                $textR = 255; $textG = 255; $textB = 255;
                $metaR = 167; $metaG = 243; $metaB = 208;
                break;
        }

        // Clean & wrap text
        $cleanText = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
        $wrappedLines = $this->wrapTextToWidth($cleanText, $fontSize, $fontFile, $maxTextW);
        $fullFormattedText = implode("\n", $wrappedLines);

        $chars = mb_str_split($fullFormattedText);
        $charCount = count($chars);
        $typingSpeed = (int)($options['fps'] ?? 20);
        $maxTypingFrames = min(35, max(12, $charCount));
        $holdFrames = 6;
        $totalFrames = $maxTypingFrames + $holdFrames;

        for ($frameIdx = 1; $frameIdx <= $totalFrames; $frameIdx++) {
            $img = imagecreatetruecolor($badgeW, $badgeH);
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $trans = imagecolorallocatealpha($img, 0, 0, 0, 127);
            imagefilledrectangle($img, 0, 0, $badgeW, $badgeH, $trans);
            imagealphablending($img, true);

            // 1. Draw Container (Glassmorphism rounded rectangle)
            $bgColor = imagecolorallocatealpha($img, $bgR, $bgG, $bgB, $bgAlpha);
            $this->drawFilledRoundedRectangle($img, 0, 0, $badgeW - 1, $badgeH - 1, $badgeRadius, $bgColor);

            // Subtle border if enabled
            if ($hasBorder) {
                $bCol = imagecolorallocatealpha($img, $borderR, $borderG, $borderB, $borderAlpha);
                // Draw 1px rounded outline
                $this->drawFilledRoundedRectangle($img, 0, 0, $badgeW - 1, $badgeH - 1, $badgeRadius, $bCol);
                $this->drawFilledRoundedRectangle($img, 1, 1, $badgeW - 2, $badgeH - 2, max(1, $badgeRadius - 1), $bgColor);
            }

            // 2. Render Header (Step Capsule & Brand Meta)
            $stepTag = strtoupper($stepLabel ?: 'STEP');
            $tagFontSize = max(10, (int)round($fontSize * 0.6));
            $tagBox = imagettfbbox($tagFontSize, 0, $fontFile, $stepTag);
            $tagTextW = abs($tagBox[4] - $tagBox[0]);
            $tagPaddingX = (int)round($badgeW * 0.035);
            $tagW = $tagTextW + ($tagPaddingX * 2);

            $tagMarginLeft = (int)round($badgeW * 0.05);
            $tagMarginTop = $isFloatingPill ? (int)round(($badgeH - ($tagFontSize + 16)) / 2) : (int)round($badgeH * 0.12);
            $tagH = $tagFontSize + 14;

            $tagColor = imagecolorallocate($img, $tagR, $tagG, $tagB);
            $this->drawFilledRoundedRectangle($img, $tagMarginLeft, $tagMarginTop, $tagMarginLeft + $tagW, $tagMarginTop + $tagH, 8, $tagColor);

            $tagTextColor = imagecolorallocate($img, $tagTextR, $tagTextG, $tagTextB);
            imagettftext($img, $tagFontSize, 0, $tagMarginLeft + $tagPaddingX, $tagMarginTop + $tagFontSize + 3, $tagTextColor, $fontFile, $stepTag);

            // 3. Render Brand Watermark (if not empty and not in compact floating pill mode)
            if (!$isFloatingPill && $brandName !== '') {
                $metaColor = imagecolorallocatealpha($img, $metaR, $metaG, $metaB, 40);
                $brandFontSize = max(10, (int)round($fontSize * 0.58));
                imagettftext($img, $brandFontSize, 0, $tagMarginLeft + $tagW + 14, $tagMarginTop + $tagFontSize + 3, $metaColor, $fontFile, $brandName);
            }

            // 4. Typewriter Instruction Text
            $sliceRatio = min(1.0, $frameIdx / $maxTypingFrames);
            $visibleCount = (int)ceil($sliceRatio * $charCount);
            $visibleText = implode('', array_slice($chars, 0, $visibleCount));

            $isTyping = ($frameIdx <= $maxTypingFrames);
            $showCursor = $isTyping && (($frameIdx % 4) < 2);

            $lines = explode("\n", $visibleText);
            $textColor = imagecolorallocate($img, $textR, $textG, $textB);

            if ($isFloatingPill) {
                // In floating pill mode, text is placed horizontally next to tag
                $textX = $tagMarginLeft + $tagW + 14;
                $textY = (int)round($badgeH * 0.62);
                $renderedLine = ($lines[0] ?? '') . ($showCursor ? ' |' : '');
                if ($renderedLine !== '') {
                    imagettftext($img, $fontSize, 0, $textX, $textY, $textColor, $fontFile, $renderedLine);
                }
            } else {
                // In standard badge mode, text is placed below the tag
                $textX = $tagMarginLeft;
                $textY = $tagMarginTop + $tagH + $fontSize + 10;
                foreach ($lines as $lIdx => $lineText) {
                    $isLastLine = ($lIdx === count($lines) - 1);
                    $renderedLine = $lineText . ($isLastLine && $showCursor ? ' |' : '');
                    if ($renderedLine !== '') {
                        imagettftext($img, $fontSize, 0, $textX, $textY, $textColor, $fontFile, $renderedLine);
                    }
                    $textY += ($fontSize + 12);
                }
            }

            $framePath = sprintf('%s/f_%03d.png', $outputDir, $frameIdx);
            imagepng($img, $framePath);
            unset($img);
        }

        return [
            'success' => true,
            'frame_count' => $totalFrames,
            'fps' => $typingSpeed,
            'pattern' => $outputDir . '/f_%03d.png',
            'badge_width' => $badgeW,
            'badge_height' => $badgeH
        ];
    }

    /**
     * Apply typing text overlay to a single video clip.
     */
    public function applyTextOverlayToClip(string $inputClipPath, string $outputClipPath, array $overlayConfig = []): array
    {
        $baseDir = dirname(__DIR__);
        $absInput = (str_starts_with($inputClipPath, '/') || (strlen($inputClipPath) > 1 && $inputClipPath[1] === ':'))
            ? $inputClipPath
            : $baseDir . '/' . ltrim($inputClipPath, '/');

        if (!file_exists($absInput) || filesize($absInput) === 0) {
            return ['success' => false, 'error' => "File klip input tidak ditemukan: " . basename($absInput)];
        }

        $absOutput = (str_starts_with($outputClipPath, '/') || (strlen($outputClipPath) > 1 && $outputClipPath[1] === ':'))
            ? $outputClipPath
            : $baseDir . '/' . ltrim($outputClipPath, '/');

        $outDir = dirname($absOutput);
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0777, true);
        }

        $stepLabel = $overlayConfig['step_label'] ?? 'STEP 1';
        $text = $overlayConfig['text'] ?? '';
        $aspectRatio = $overlayConfig['aspect_ratio'] ?? '9:16';
        $position = $overlayConfig['position'] ?? 'bottom';
        $brandName = isset($overlayConfig['brand_name']) ? trim($overlayConfig['brand_name']) : '';
        $widthRatio = (float)($overlayConfig['width_ratio'] ?? 0.86);

        // Probe real input video resolution for exact responsive scaling
        [$videoWidth, $videoHeight] = $this->getVideoDimensions($absInput, $aspectRatio);

        $tempFramesDir = $outDir . '/temp_overlay_' . time() . '_' . bin2hex(random_bytes(3));
        $renderRes = $this->renderTypingOverlayFrames($tempFramesDir, $stepLabel, $text, [
            'aspect_ratio' => $aspectRatio,
            'video_width' => $videoWidth,
            'video_height' => $videoHeight,
            'width_ratio' => $widthRatio,
            'brand_name' => $brandName,
            'theme' => $overlayConfig['theme'] ?? 'dark_glass',
            'fps' => 20
        ]);

        if (!$renderRes['success']) {
            return $renderRes;
        }

        // Calculate safe vertical position (avoiding TikTok/Reels UI)
        if ($position === 'top') {
            $topOffset = (int)round($videoHeight * 0.07); // ~90px on 720x1280
            $posY = (string)$topOffset;
        } elseif ($position === 'center') {
            $posY = "(H-h)/2";
        } else {
            // Bottom safe zone above captions & action icons
            $bottomOffset = (int)round($videoHeight * 0.11); // ~140px on 720x1280
            $posY = "H-h-{$bottomOffset}";
        }

        $fps = $renderRes['fps'];
        $pattern = $renderRes['pattern'];

        // Overlay with (W-w)/2: always centered with equal margins!
        $cmd = escapeshellcmd($this->ffmpegBinary) . " -y"
            . " -i " . escapeshellarg($absInput)
            . " -framerate " . (int)$fps . " -i " . escapeshellarg($pattern)
            . " -filter_complex \"[0:v][1:v]overlay=(W-w)/2:{$posY}:eof_action=repeat\""
            . " -c:v libx264 -preset fast -crf 21 -pix_fmt yuv420p"
            . " -movflags +faststart " . escapeshellarg($absOutput) . " 2>&1";

        $output = [];
        $returnVar = 0;
        @exec($cmd, $output, $returnVar);

        // Cleanup temporary frames
        if (is_dir($tempFramesDir)) {
            $files = glob($tempFramesDir . '/*.png');
            if ($files) {
                foreach ($files as $f) @unlink($f);
            }
            @rmdir($tempFramesDir);
        }

        if ($returnVar !== 0 || !file_exists($absOutput) || filesize($absOutput) === 0) {
            $errDetail = implode("\n", array_slice($output, -6));
            return [
                'success' => false,
                'error' => "FFmpeg gagal menambahkan overlay teks (Code {$returnVar}): " . ($errDetail ?: 'Error')
            ];
        }

        return [
            'success' => true,
            'output_path' => $outputClipPath,
            'absolute_path' => $absOutput,
            'file_size' => filesize($absOutput)
        ];
    }

    /**
     * Get exact duration of a video clip in seconds using ffprobe.
     */
    public function getVideoDuration(string $videoPath): float
    {
        $baseDir = dirname(__DIR__);
        $absPath = (str_starts_with($videoPath, '/') || (strlen($videoPath) > 1 && $videoPath[1] === ':'))
            ? $videoPath
            : $baseDir . '/' . ltrim($videoPath, '/');

        if (!file_exists($absPath)) {
            return 4.0;
        }

        $ffprobeBin = str_replace('ffmpeg', 'ffprobe', $this->ffmpegBinary);
        if (!file_exists($ffprobeBin) && !shell_exec("which ffprobe")) {
            $ffprobeBin = 'ffprobe';
        }

        $cmd = escapeshellcmd($ffprobeBin) . " -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($absPath) . " 2>&1";
        $output = @shell_exec($cmd);
        if ($output !== null && is_numeric(trim($output))) {
            return (float)trim($output);
        }
        return 4.0;
    }

    /**
     * Prepare a synchronized narration audio track for all scenes.
     * Each scene's audio is padded to match the corresponding video clip duration,
     * ensuring zero audio drift across scenes.
     */
    public function prepareSynchronizedNarrationTrack(array $audioScenes, array $videoClipPaths, string $targetDir, array $options = []): ?string
    {
        $baseDir = dirname(__DIR__);
        $tempPaddedClips = [];
        $hasAnyAudio = false;

        $microDelayMs = (int)($options['micro_delay_ms'] ?? 300); // 0.3s natural delay

        foreach ($videoClipPaths as $idx => $vPath) {
            $clipDuration = $this->getVideoDuration($vPath);
            if ($clipDuration <= 0.1) {
                $clipDuration = (float)($options['clip_duration'] ?? 4.0);
            }

            $sceneAudioRel = $audioScenes[$idx]['audio_path'] ?? null;
            $audioAbsPath = null;
            if (!empty($sceneAudioRel)) {
                $audioAbsPath = (str_starts_with($sceneAudioRel, '/') || (strlen($sceneAudioRel) > 1 && $sceneAudioRel[1] === ':'))
                    ? $sceneAudioRel
                    : $baseDir . '/' . ltrim($sceneAudioRel, '/');
            }

            $paddedClipFile = $targetDir . '/padded_audio_' . time() . '_' . $idx . '_' . bin2hex(random_bytes(2)) . '.mp3';

            if ($audioAbsPath && file_exists($audioAbsPath) && filesize($audioAbsPath) > 0) {
                $hasAnyAudio = true;
                // Add micro-delay at start, then pad silence until $clipDuration
                $filter = "adelay={$microDelayMs}|{$microDelayMs},apad=whole_dur={$clipDuration}";
                $cmd = escapeshellcmd($this->ffmpegBinary) . " -y -i " . escapeshellarg($audioAbsPath)
                    . " -af " . escapeshellarg($filter)
                    . " -t " . escapeshellarg((string)$clipDuration)
                    . " -ar 32000 -c:a libmp3lame -b:a 128k " . escapeshellarg($paddedClipFile) . " 2>&1";
                @exec($cmd);
            } else {
                // Generate silent audio of exact clip duration
                $cmd = escapeshellcmd($this->ffmpegBinary) . " -y -f lavfi -i anullsrc=r=32000:cl=mono"
                    . " -t " . escapeshellarg((string)$clipDuration)
                    . " -c:a libmp3lame -b:a 128k " . escapeshellarg($paddedClipFile) . " 2>&1";
                @exec($cmd);
            }

            if (file_exists($paddedClipFile) && filesize($paddedClipFile) > 0) {
                $tempPaddedClips[] = $paddedClipFile;
            }
        }

        if (!$hasAnyAudio || empty($tempPaddedClips)) {
            // Clean up temporary files
            foreach ($tempPaddedClips as $f) {
                if (file_exists($f)) @unlink($f);
            }
            return null;
        }

        // Concatenate all padded audio clips
        $tempAudioConcatFile = $targetDir . '/concat_audio_' . time() . '_' . bin2hex(random_bytes(3)) . '.txt';
        $concatContent = '';
        foreach ($tempPaddedClips as $pf) {
            $safePath = str_replace("'", "'\\''", $pf);
            $concatContent .= "file '{$safePath}'\n";
        }
        @file_put_contents($tempAudioConcatFile, $concatContent);

        $mergedNarrationFile = $targetDir . '/full_narration_' . time() . '_' . bin2hex(random_bytes(3)) . '.mp3';
        $mergeCmd = escapeshellcmd($this->ffmpegBinary) . " -y -f concat -safe 0 -i " . escapeshellarg($tempAudioConcatFile)
            . " -c:a copy " . escapeshellarg($mergedNarrationFile) . " 2>&1";
        @exec($mergeCmd);

        // Cleanup temporary padded clips
        @unlink($tempAudioConcatFile);
        foreach ($tempPaddedClips as $pf) {
            if (file_exists($pf)) @unlink($pf);
        }

        if (file_exists($mergedNarrationFile) && filesize($mergedNarrationFile) > 0) {
            return $mergedNarrationFile;
        }

        return null;
    }

    /**
     * Stitch multiple video scene clips (.mp4) into a single cohesive video,
     * with optional synchronized voiceover narration (MiniMax TTS) and background music (BGM).
     *
     * @param array $clipPaths Array of relative or absolute file paths to .mp4 clips
     * @param string $outputPath Target file path for the final stitched video
     * @param array $options Additional options (preset, crf, fps, enable_text_overlay, audio_scenes, enable_bgm, etc.)
     * @return array
     */
    public function stitchScenes(array $clipPaths, string $outputPath, array $options = []): array
    {
        if (empty($clipPaths)) {
            return ['success' => false, 'error' => 'Tidak ada klip video yang diberikan untuk digabungkan.'];
        }

        $baseDir = dirname(__DIR__);
        $validClips = [];

        foreach ($clipPaths as $clip) {
            $absPath = (str_starts_with($clip, '/') || (strlen($clip) > 1 && $clip[1] === ':'))
                ? $clip
                : $baseDir . '/' . ltrim($clip, '/');

            if (!file_exists($absPath) || filesize($absPath) === 0) {
                return ['success' => false, 'error' => "File klip video tidak ditemukan atau kosong: " . basename($absPath)];
            }
            $validClips[] = $absPath;
        }

        // Target directory verification
        $targetAbsPath = (str_starts_with($outputPath, '/') || (strlen($outputPath) > 1 && $outputPath[1] === ':'))
            ? $outputPath
            : $baseDir . '/' . ltrim($outputPath, '/');

        $targetDir = dirname($targetAbsPath);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }

        // Temporary concat file for videos
        $tempConcatFile = $targetDir . '/concat_' . time() . '_' . bin2hex(random_bytes(4)) . '.txt';
        $fileContent = '';
        foreach ($validClips as $vc) {
            $safePath = str_replace("'", "'\\''", $vc);
            $fileContent .= "file '{$safePath}'\n";
        }

        if (@file_put_contents($tempConcatFile, $fileContent) === false) {
            return ['success' => false, 'error' => 'Gagal membuat file daftar concat video sementara.'];
        }

        $preset = $options['preset'] ?? 'fast';
        $crf = (int)($options['crf'] ?? 22);

        // Prepare synchronized narration audio track if audio scenes are provided
        $audioScenes = $options['audio_scenes'] ?? [];
        $mergedNarrationPath = null;
        if (!empty($audioScenes) && is_array($audioScenes)) {
            $mergedNarrationPath = $this->prepareSynchronizedNarrationTrack($audioScenes, $validClips, $targetDir, $options);
        }

        // Check BGM (Background Music)
        $enableBgm = !empty($options['enable_bgm']);
        $bgmVolume = (float)($options['bgm_volume'] ?? 0.18);
        $bgmFilePath = null;
        if ($enableBgm) {
            $candidateBgm = [
                $baseDir . '/assets/audio/upbeat_promo.mp3',
                $baseDir . '/assets/audio/smooth_commercial.mp3'
            ];
            foreach ($candidateBgm as $cand) {
                if (file_exists($cand) && filesize($cand) > 0) {
                    $bgmFilePath = $cand;
                    break;
                }
            }
        }

        // Construct FFmpeg command based on available audio streams
        $output = [];
        $returnVar = 0;

        if ($mergedNarrationPath && $bgmFilePath) {
            // Case A: Narration Voiceover + Background Music Mix
            $cmd = escapeshellcmd($this->ffmpegBinary) . " -y"
                 . " -f concat -safe 0 -i " . escapeshellarg($tempConcatFile)
                 . " -i " . escapeshellarg($mergedNarrationPath)
                 . " -stream_loop -1 -i " . escapeshellarg($bgmFilePath)
                 . " -filter_complex " . escapeshellarg("[1:a]volume=1.0[a_voc];[2:a]volume={$bgmVolume}[a_bgm];[a_voc][a_bgm]amix=inputs=2:duration=first:dropout_transition=2[a_mix]")
                 . " -map 0:v:0 -map '[a_mix]'"
                 . " -c:v libx264 -preset " . escapeshellarg($preset)
                 . " -crf " . $crf
                 . " -pix_fmt yuv420p"
                 . " -c:a aac -b:a 192k"
                 . " -movflags +faststart"
                 . " " . escapeshellarg($targetAbsPath) . " 2>&1";
        } elseif ($mergedNarrationPath) {
            // Case B: Narration Voiceover Only
            $cmd = escapeshellcmd($this->ffmpegBinary) . " -y"
                 . " -f concat -safe 0 -i " . escapeshellarg($tempConcatFile)
                 . " -i " . escapeshellarg($mergedNarrationPath)
                 . " -map 0:v:0 -map 1:a:0"
                 . " -c:v libx264 -preset " . escapeshellarg($preset)
                 . " -crf " . $crf
                 . " -pix_fmt yuv420p"
                 . " -c:a aac -b:a 192k"
                 . " -movflags +faststart"
                 . " " . escapeshellarg($targetAbsPath) . " 2>&1";
        } elseif ($bgmFilePath) {
            // Case C: Background Music Only
            $cmd = escapeshellcmd($this->ffmpegBinary) . " -y"
                 . " -f concat -safe 0 -i " . escapeshellarg($tempConcatFile)
                 . " -stream_loop -1 -i " . escapeshellarg($bgmFilePath)
                 . " -map 0:v:0 -map 1:a:0"
                 . " -c:v libx264 -preset " . escapeshellarg($preset)
                 . " -crf " . $crf
                 . " -pix_fmt yuv420p"
                 . " -c:a aac -b:a 192k"
                 . " -shortest"
                 . " -movflags +faststart"
                 . " " . escapeshellarg($targetAbsPath) . " 2>&1";
        } else {
            // Case D: Pure Video (Silent)
            $cmd = escapeshellcmd($this->ffmpegBinary) . " -y"
                 . " -f concat -safe 0 -i " . escapeshellarg($tempConcatFile)
                 . " -c:v libx264 -preset " . escapeshellarg($preset)
                 . " -crf " . $crf
                 . " -pix_fmt yuv420p"
                 . " -movflags +faststart"
                 . " " . escapeshellarg($targetAbsPath) . " 2>&1";
        }

        @exec($cmd, $output, $returnVar);

        // Cleanup temporary files
        @unlink($tempConcatFile);
        if ($mergedNarrationPath && file_exists($mergedNarrationPath)) {
            @unlink($mergedNarrationPath);
        }

        if ($returnVar !== 0 || !file_exists($targetAbsPath) || filesize($targetAbsPath) === 0) {
            $errDetail = implode("\n", array_slice($output, -10));
            return [
                'success' => false,
                'error' => "FFmpeg gagal menggabungkan video (Exit code {$returnVar}): " . ($errDetail ?: 'Unknown error')
            ];
        }

        $finalSize = filesize($targetAbsPath);

        // Optional: generate poster thumbnail image from frame 1
        $posterPath = preg_replace('/\.mp4$/i', '_thumb.jpg', $targetAbsPath);
        $posterCmd = escapeshellcmd($this->ffmpegBinary) . " -y -ss 00:00:01 -i " . escapeshellarg($targetAbsPath) . " -vframes 1 -q:v 2 " . escapeshellarg($posterPath) . " 2>&1";
        @exec($posterCmd);

        return [
            'success' => true,
            'message' => 'Seluruh adegan berhasil disatukan menjadi video utuh dengan sinkronisasi audio narasi!',
            'output_path' => $outputPath,
            'absolute_path' => $targetAbsPath,
            'file_size' => $finalSize,
            'poster_path' => file_exists($posterPath) ? preg_replace('/\.mp4$/i', '_thumb.jpg', $outputPath) : null
        ];
    }
}


