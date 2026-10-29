<?php

namespace Ai;

use mysqli;
use Exception;
use Throwable;

class MiniMaxClient
{
    public const GATEWAY_POOL = [
        'https://api.minimax.io/v1/t2a_v2',       // Primary Global Gateway
        'https://api.minimaxi.chat/v1/t2a_v2',    // Alternative Gateway 1
        'https://api.minimax.chat/v1/t2a_v2',     // Alternative Gateway 2
        'https://api-uw.minimax.io/v1/t2a_v2'     // US-West Fallback Gateway
    ];

    public const VOICES_ID = [
        'Indonesian_energetic_streamer_vv2' => [
            'id' => 'Indonesian_energetic_streamer_vv2',
            'label' => 'Energetic Streamer (Rekomendasi Cepat & Semangat)',
            'gender' => 'female',
            'desc' => 'Sangat cocok untuk video TikTok, Reels, dan Shorts memasak yang bersemangat.'
        ],
        'Indonesian_crisp_reporter_vv2' => [
            'id' => 'Indonesian_crisp_reporter_vv2',
            'label' => 'Crisp Reporter (Artikulasi Jernih & Terstruktur)',
            'gender' => 'female',
            'desc' => 'Artikulasi renyah dan formal, cocok untuk tutorial resep langkah-demi-langkah.'
        ],
        'Indonesian_casual_reporter_vv2' => [
            'id' => 'Indonesian_casual_reporter_vv2',
            'label' => 'Casual Reporter (Santai, Akrab & Bersahabat)',
            'gender' => 'female',
            'desc' => 'Gaya berbicara santai seperti teman sedang berbagi tips memasak di dapur.'
        ],
        'Indonesian_professional_anchor_vv2' => [
            'id' => 'Indonesian_professional_anchor_vv2',
            'label' => 'Professional Anchor (Elegan & Berwibawa)',
            'gender' => 'female',
            'desc' => 'Suara pembawa acara profesional dengan tempo teratur dan percaya diri.'
        ],
        'Indonesian_intellectual_commentator_vv2' => [
            'id' => 'Indonesian_intellectual_commentator_vv2',
            'label' => 'Intellectual Commentator (Tenang & Edukatif)',
            'gender' => 'male',
            'desc' => 'Gaya ulasan ilmiah yang tenang, ideal untuk resep diet, nutrisi, atau teknis airfryer.'
        ],
        'Indonesian_compelling_storyteller_vv2' => [
            'id' => 'Indonesian_compelling_storyteller_vv2',
            'label' => 'Compelling Storyteller (Ekspresif & Menghanyutkan)',
            'gender' => 'male',
            'desc' => 'Dramatis dan ekspresif, bagus untuk narasi cerita di balik resep.'
        ],
        'Indonesian_expressive_podcaster_vv2' => [
            'id' => 'Indonesian_expressive_podcaster_vv2',
            'label' => 'Expressive Podcaster (Hangat & Conversational)',
            'gender' => 'male',
            'desc' => 'Gaya obrolan podcast yang hangat dan penuh emosi positif.'
        ]
    ];

    public const VOICES_EN = [
        // Female Voices
        'English_radiant_girl' => ['id' => 'English_radiant_girl', 'label' => 'Radiant Girl (Ceria & Menyenangkan)', 'gender' => 'female'],
        'English_compelling_lady1' => ['id' => 'English_compelling_lady1', 'label' => 'Compelling Lady (Elegan & Memikat)', 'gender' => 'female'],
        'English_captivating_female1' => ['id' => 'English_captivating_female1', 'label' => 'Captivating Female (Menarik & Hangat)', 'gender' => 'female'],
        'English_Upbeat_Woman' => ['id' => 'English_Upbeat_Woman', 'label' => 'Upbeat Woman (Penuh Energi)', 'gender' => 'female'],
        'English_CalmWoman' => ['id' => 'English_CalmWoman', 'label' => 'Calm Woman (Tenang & Lembut)', 'gender' => 'female'],
        'English_Whispering_girl' => ['id' => 'English_Whispering_girl', 'label' => 'Whispering Girl (ASMR & Berbisik)', 'gender' => 'female'],
        'English_Graceful_Lady' => ['id' => 'English_Graceful_Lady', 'label' => 'Graceful Lady (Anggun & Berkelas)', 'gender' => 'female'],
        'English_PlayfulGirl' => ['id' => 'English_PlayfulGirl', 'label' => 'Playful Girl (Lucu & Lincah)', 'gender' => 'female'],
        'English_MaturePartner' => ['id' => 'English_MaturePartner', 'label' => 'Mature Partner (Dewasa & Meyakinkan)', 'gender' => 'female'],
        'English_MatureBoss' => ['id' => 'English_MatureBoss', 'label' => 'Mature Boss (Tegas & Mandiri)', 'gender' => 'female'],
        'English_LovelyGirl' => ['id' => 'English_LovelyGirl', 'label' => 'Lovely Girl (Manis & Ramah)', 'gender' => 'female'],
        'English_Wiselady' => ['id' => 'English_Wiselady', 'label' => 'Wise Lady (Bijak & Mengayomi)', 'gender' => 'female'],
        'English_SentimentalLady' => ['id' => 'English_SentimentalLady', 'label' => 'Sentimental Lady (Emosional & Tulus)', 'gender' => 'female'],
        'English_ImposingManner' => ['id' => 'English_ImposingManner', 'label' => 'Imposing Manner (Megah & Berwibawa)', 'gender' => 'female'],
        'English_Soft-spokenGirl' => ['id' => 'English_Soft-spokenGirl', 'label' => 'Soft-spoken Girl (Suara Lembut & Santun)', 'gender' => 'female'],
        'English_SereneWoman' => ['id' => 'English_SereneWoman', 'label' => 'Serene Woman (Damai & Menyejukkan)', 'gender' => 'female'],
        'English_ConfidentWoman' => ['id' => 'English_ConfidentWoman', 'label' => 'Confident Woman (Percaya Diri)', 'gender' => 'female'],
        'English_BossyLeader' => ['id' => 'English_BossyLeader', 'label' => 'Bossy Leader (Pemimpin Kharismatik)', 'gender' => 'female'],
        'English_UpsetGirl' => ['id' => 'English_UpsetGirl', 'label' => 'Upset Girl (Ekspresi Heran/Kecewa)', 'gender' => 'female'],

        // Male Voices
        'English_expressive_narrator' => ['id' => 'English_expressive_narrator', 'label' => 'Expressive Narrator (Narator Profesional)', 'gender' => 'male'],
        'English_magnetic_voiced_man' => ['id' => 'English_magnetic_voiced_man', 'label' => 'Magnetic Voiced Man (Magnetik & Maskulin)', 'gender' => 'male'],
        'English_Trustworth_Man' => ['id' => 'English_Trustworth_Man', 'label' => 'Trustworthy Man (Dapat Dipercaya)', 'gender' => 'male'],
        'English_Gentle-voiced_man' => ['id' => 'English_Gentle-voiced_man', 'label' => 'Gentle-voiced Man (Halus & Ramah)', 'gender' => 'male'],
        'English_Aussie_Bloke' => ['id' => 'English_Aussie_Bloke', 'label' => 'Aussie Bloke (Aksen Australia Khas)', 'gender' => 'male'],
        'English_Diligent_Man' => ['id' => 'English_Diligent_Man', 'label' => 'Diligent Man (Rajin & Lugas)', 'gender' => 'male'],
        'English_ReservedYoungMan' => ['id' => 'English_ReservedYoungMan', 'label' => 'Reserved Young Man (Pemuda Kalem)', 'gender' => 'male'],
        'English_ManWithDeepVoice' => ['id' => 'English_ManWithDeepVoice', 'label' => 'Man With Deep Voice (Suara Berat Berkarakter)', 'gender' => 'male'],
        'English_FriendlyPerson' => ['id' => 'English_FriendlyPerson', 'label' => 'Friendly Person (Santai & Bersahabat)', 'gender' => 'male'],
        'English_Debator' => ['id' => 'English_Debator', 'label' => 'Debator (Lugasm Argumentatif)', 'gender' => 'male'],
        'English_Steadymentor' => ['id' => 'English_Steadymentor', 'label' => 'Steady Mentor (Mentor Bijaksana)', 'gender' => 'male'],
        'English_Deep-VoicedGentleman' => ['id' => 'English_Deep-VoicedGentleman', 'label' => 'Deep-Voiced Gentleman (Pria Elegan & Berat)', 'gender' => 'male'],
        'English_CaptivatingStoryteller' => ['id' => 'English_CaptivatingStoryteller', 'label' => 'Captivating Storyteller (Pencerita Ulung)', 'gender' => 'male'],
        'English_DecentYoungMan' => ['id' => 'English_DecentYoungMan', 'label' => 'Decent Young Man (Pemuda Sopan)', 'gender' => 'male'],
        'English_PassionateWarrior' => ['id' => 'English_PassionateWarrior', 'label' => 'Passionate Warrior (Gagah & Penuh Semangat)', 'gender' => 'male'],
        'English_WiseScholar' => ['id' => 'English_WiseScholar', 'label' => 'Wise Scholar (Akademisi Tenang)', 'gender' => 'male'],
        'English_PatientMan' => ['id' => 'English_PatientMan', 'label' => 'Patient Man (Sabar & Hati-hati)', 'gender' => 'male'],
        'English_Comedian' => ['id' => 'English_Comedian', 'label' => 'Comedian (Jenaka & Asyik)', 'gender' => 'male'],
        'English_SadTeen' => ['id' => 'English_SadTeen', 'label' => 'Teen Boy (Suara Remaja Natural)', 'gender' => 'male'],

        // Legacy Fallbacks
        'English_Resonant_Man' => ['id' => 'English_Resonant_Man', 'label' => 'Resonant Man (Bergema Dalam)', 'gender' => 'male'],
        'English_causual_narrator_vv1' => ['id' => 'English_causual_narrator_vv1', 'label' => 'Casual Narrator v1', 'gender' => 'male'],
        'English_causual_podcast_vv1' => ['id' => 'English_causual_podcast_vv1', 'label' => 'Casual Podcast v1', 'gender' => 'female'],
        'English_expressive_host__vv1' => ['id' => 'English_expressive_host__vv1', 'label' => 'Expressive Host v1', 'gender' => 'female']
    ];

    /**
     * Get list of voices for a given language code.
     */
    public static function getVoicesByLanguage(string $lang = 'id-ID'): array
    {
        return ($lang === 'en-US' || str_starts_with(strtolower($lang), 'en')) ? self::VOICES_EN : self::VOICES_ID;
    }

    /**
     * Read MiniMax configuration from tbl_ai_config.
     */
    public static function getConfig(mysqli $db): array
    {
        $default = [
            'minimax_api_key' => '',
            'minimax_group_id' => '',
            'minimax_model' => 'speech-2.8-turbo',
            'minimax_default_language' => 'id-ID',
            'minimax_default_voice' => 'Indonesian_energetic_streamer_vv2',
            'minimax_default_speed' => 1.00,
            'minimax_default_volume' => 1.00
        ];

        try {
            $res = $db->query("SELECT minimax_api_key, minimax_group_id, minimax_model, minimax_default_language, minimax_default_voice, minimax_default_speed, minimax_default_volume FROM tbl_ai_config LIMIT 1");
            if ($res && $row = $res->fetch_assoc()) {
                return array_merge($default, array_filter($row, fn($v) => $v !== null));
            }
        } catch (Throwable $e) {
            error_log("[MiniMaxClient::getConfig] " . $e->getMessage());
        }

        return $default;
    }

    /**
     * Save MiniMax configuration to tbl_ai_config.
     */
    public static function saveConfig(mysqli $db, array $data): bool
    {
        try {
            $apiKey = trim($data['minimax_api_key'] ?? '');
            $groupId = trim($data['minimax_group_id'] ?? '');
            $model = trim($data['minimax_model'] ?? 'speech-2.8-turbo');
            $lang = trim($data['minimax_default_language'] ?? 'id-ID');
            $voice = trim($data['minimax_default_voice'] ?? 'Indonesian_energetic_streamer_vv2');
            $speed = (float)($data['minimax_default_speed'] ?? 1.00);
            $volume = (float)($data['minimax_default_volume'] ?? 1.00);

            $stmt = $db->prepare("UPDATE tbl_ai_config SET 
                minimax_api_key = ?, 
                minimax_group_id = ?, 
                minimax_model = ?, 
                minimax_default_language = ?, 
                minimax_default_voice = ?, 
                minimax_default_speed = ?, 
                minimax_default_volume = ? 
                LIMIT 1");

            if ($stmt) {
                $stmt->bind_param('sssssdd', $apiKey, $groupId, $model, $lang, $voice, $speed, $volume);
                $res = $stmt->execute();
                $stmt->close();
                return $res;
            }
            return false;
        } catch (Throwable $e) {
            error_log("[MiniMaxClient::saveConfig] " . $e->getMessage());
            return false;
        }
    }

    /**
     * Synthesize speech using MiniMax t2a_v2 API.
     * Writes MP3 directly to $outputPath.
     */
    public function generateVoiceover(string $text, string $voicePersona, string $outputPath, array $options = []): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['success' => false, 'error' => 'Naskah teks untuk narasi TTS kosong.'];
        }

        $apiKey = trim($options['api_key'] ?? '');
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'MiniMax API Key belum dikonfigurasi. Silakan isi di halaman Konfigurasi AI.'];
        }

        $model = $options['model'] ?? 'speech-2.8-turbo';
        $speed = (float)($options['speed'] ?? 1.00);
        $volume = (float)($options['volume'] ?? 1.00);
        $pitch = (int)($options['pitch'] ?? 0);
        $language = $options['language'] ?? 'id-ID';
        $isEn = ($language === 'en-US' || str_starts_with(strtolower($language), 'en'));

        // Normalize voicePersona
        $voiceId = trim($voicePersona);
        if ($voiceId === '') {
            $voiceId = $isEn ? 'English_radiant_girl' : 'Indonesian_energetic_streamer_vv2';
        }

        $payload = [
            'model' => $model,
            'text' => $text,
            'stream' => false,
            'voice_setting' => [
                'voice_id' => $voiceId,
                'speed' => $speed,
                'vol' => $volume,
                'pitch' => $pitch
            ],
            'audio_setting' => [
                'sample_rate' => 32000,
                'bitrate' => 128000,
                'format' => 'mp3',
                'channel' => 1
            ],
            'language_boost' => $isEn ? 'English' : 'Indonesian',
            'output_format' => 'hex'
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $gateways = self::GATEWAY_POOL;
        $lastError = null;
        $responseBody = null;
        $httpCode = 0;
        $usedGateway = '';

        foreach ($gateways as $idx => $gwUrl) {
            $usedGateway = $gwUrl;
            $ch = curl_init($gwUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payloadJson,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                $lastError = "Koneksi gateway {$gwUrl} gagal: {$curlErr}";
                if ($idx < count($gateways) - 1) {
                    usleep(500000); // 0.5s pause before failover
                    continue;
                }
                break;
            }

            if ($httpCode >= 500 && $idx < count($gateways) - 1) {
                $lastError = "Gateway {$gwUrl} mengembalikan HTTP {$httpCode}";
                usleep(500000);
                continue;
            }

            if ($httpCode !== 200) {
                $errMsg = "MiniMax API HTTP {$httpCode}";
                $parsed = json_decode($responseBody, true);
                if (isset($parsed['base_resp']['status_msg'])) {
                    $errMsg .= ": " . $parsed['base_resp']['status_msg'];
                } elseif (!empty($responseBody)) {
                    $errMsg .= ": " . substr(strip_tags($responseBody), 0, 150);
                }
                return ['success' => false, 'error' => $errMsg, 'gateway' => $usedGateway];
            }

            // Success response received
            break;
        }

        if (empty($responseBody) || $httpCode !== 200) {
            return ['success' => false, 'error' => $lastError ?: 'Gagal menghubungi seluruh gateway MiniMax TTS.'];
        }

        $result = json_decode($responseBody, true);
        if (!$result) {
            return ['success' => false, 'error' => 'Respons MiniMax tidak valid (bukan JSON).'];
        }

        if (isset($result['base_resp']['status_code']) && $result['base_resp']['status_code'] !== 0) {
            $code = $result['base_resp']['status_code'];
            $msg = $result['base_resp']['status_msg'] ?? 'Unknown error';
            return ['success' => false, 'error' => "MiniMax API error [{$code}]: {$msg}"];
        }

        $audioHex = $result['data']['audio'] ?? '';
        if (empty($audioHex)) {
            return ['success' => false, 'error' => 'MiniMax API tidak menyertakan data audio pada respons.'];
        }

        // Convert hex string into binary MP3 buffer
        $audioBinary = @hex2bin($audioHex);
        if ($audioBinary === false) {
            return ['success' => false, 'error' => 'Gagal mengonversi format audio hex MiniMax ke binary MP3.'];
        }

        // Ensure output directory exists
        $targetDir = dirname($outputPath);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }

        if (@file_put_contents($outputPath, $audioBinary) === false) {
            return ['success' => false, 'error' => "Gagal menulis file audio ke path: {$outputPath}"];
        }

        $fileSize = filesize($outputPath);
        $duration = $this->calculateAudioDuration($outputPath);

        return [
            'success' => true,
            'output_path' => $outputPath,
            'file_size' => $fileSize,
            'duration' => $duration,
            'model' => $model,
            'voice_persona' => $voiceId,
            'gateway' => $usedGateway
        ];
    }

    /**
     * Calculate audio duration in seconds using ffprobe or estimate by MP3 bitrate.
     */
    public function calculateAudioDuration(string $filePath): float
    {
        if (!file_exists($filePath)) {
            return 0.0;
        }

        // Try ffprobe first
        $ffprobeBins = ['/opt/homebrew/bin/ffprobe', '/usr/local/bin/ffprobe', '/usr/bin/ffprobe', 'ffprobe'];
        foreach ($ffprobeBins as $bin) {
            $cmd = escapeshellcmd($bin) . " -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($filePath) . " 2>&1";
            $output = @shell_exec($cmd);
            if ($output !== null && is_numeric(trim($output))) {
                return round((float)trim($output), 2);
            }
        }

        // Fallback: estimate based on 128kbps MP3 (16,000 bytes per second)
        $bytes = filesize($filePath);
        if ($bytes > 0) {
            return round($bytes / 16000, 2);
        }

        return 0.0;
    }

    /**
     * Test connection to MiniMax API by generating a short test speech clip.
     */
    public function testConnection(string $apiKey, string $voicePersona = '', string $sampleText = ''): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'API Key tidak boleh kosong.'];
        }

        $baseDir = dirname(__DIR__);
        $testFileName = 'test_tts_' . time() . '.mp3';
        $testFilePath = $baseDir . '/upload/ai_tts_audio/' . $testFileName;
        $relPath = 'upload/ai_tts_audio/' . $testFileName;

        $text = trim($sampleText);
        if ($text === '') {
            $text = "Halo, koneksi suara MiniMax AI pada webadmin Airfry berhasil terhubung dan siap digunakan!";
        }

        $voice = trim($voicePersona);
        if ($voice === '') {
            $voice = 'Indonesian_energetic_streamer_vv2';
        }

        $t0 = microtime(true);
        $res = $this->generateVoiceover($text, $voice, $testFilePath, [
            'api_key' => $apiKey,
            'model' => 'speech-2.8-turbo',
            'speed' => 1.0,
            'volume' => 1.0,
            'language' => 'id-ID'
        ]);
        $latencyMs = round((microtime(true) - $t0) * 1000);

        if (!$res['success']) {
            return [
                'success' => false,
                'latency_ms' => $latencyMs,
                'error' => $res['error']
            ];
        }

        return [
            'success' => true,
            'latency_ms' => $latencyMs,
            'audio_url' => $relPath,
            'duration' => $res['duration'] ?? 0,
            'voice_persona' => $voice,
            'message' => "Koneksi MiniMax AI berhasil! Latensi: {$latencyMs}ms."
        ];
    }
}
