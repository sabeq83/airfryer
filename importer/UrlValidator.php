<?php

namespace Importer;

class UrlValidator
{
    /**
     * Supported domain suffixes or hostnames for v1
     */
    private static array $supportedDomains = [
        'bbc.co.uk',
        'bbc.com',
        'www.bbc.co.uk',
        'www.bbc.com',
    ];

    /**
     * Normalizes a URL for consistent storage, deduplication, and fetching.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        if (($scheme === 'http' && $port === ':80') || ($scheme === 'https' && $port === ':443')) {
            $port = '';
        }

        $path = $parts['path'] ?? '/';
        // Normalize multiple slashes and trim trailing slash if path is longer than '/'
        $path = preg_replace('#/+#', '/', $path);
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            // Filter out tracking query params
            $cleanedParams = [];
            foreach ($queryParams as $k => $v) {
                $kLower = strtolower($k);
                if (
                    str_starts_with($kLower, 'utm_') ||
                    in_array($kLower, ['ref', 'source', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'], true)
                ) {
                    continue;
                }
                $cleanedParams[$k] = $v;
            }
            if (!empty($cleanedParams)) {
                ksort($cleanedParams);
                $query = '?' . http_build_query($cleanedParams);
            }
        }

        return $scheme . '://' . $host . $port . $path . $query;
    }

    /**
     * Extracts canonical source domain.
     */
    public static function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ? strtolower($host) : '';
    }

    /**
     * Checks if domain is supported.
     */
    public static function isDomainSupported(string $url): bool
    {
        $host = self::extractDomain($url);
        if (!$host) {
            return false;
        }

        foreach (self::$supportedDomains as $supported) {
            if ($host === $supported || str_ends_with($host, '.' . $supported)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates URL against SSRF and unsupported protocols.
     * Returns ['valid' => bool, 'error' => string|null, 'ip' => string|null].
     */
    public static function validateUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return ['valid' => false, 'error' => 'Format URL tidak valid.', 'ip' => null];
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['valid' => false, 'error' => 'Protokol tidak diizinkan. Hanya http dan https yang didukung.', 'ip' => null];
        }

        $host = strtolower($parts['host']);

        // Check for disallowed hostnames
        if (
            $host === 'localhost' ||
            str_ends_with($host, '.local') ||
            str_ends_with($host, '.internal') ||
            str_ends_with($host, '.lan') ||
            str_ends_with($host, '.home') ||
            str_ends_with($host, '.test')
        ) {
            return ['valid' => false, 'error' => 'Akses ke domain internal server ditolak (SSRF protection).', 'ip' => null];
        }

        // Domain whitelist check
        if (!self::isDomainSupported($url)) {
            return [
                'valid' => false,
                'error' => 'Domain "' . htmlspecialchars($host, ENT_QUOTES, 'UTF-8') . '" belum didukung. Saat ini hanya resep BBC Food (bbc.co.uk / bbc.com) yang didukung.',
                'ip' => null
            ];
        }

        // Resolve DNS to IP addresses
        $ips = gethostbynamel($host);
        if (!$ips || empty($ips)) {
            // Try IPv6 or single gethostbyname
            $singleIp = gethostbyname($host);
            if ($singleIp && $singleIp !== $host) {
                $ips = [$singleIp];
            } else {
                return ['valid' => false, 'error' => 'Gagal menyelesaikan nama domain (DNS resolution failed).', 'ip' => null];
            }
        }

        foreach ($ips as $ip) {
            if (self::isPrivateOrReservedIp($ip)) {
                return [
                    'valid' => false,
                    'error' => 'Alamat IP tujuan (' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . ') merupakan jaringan internal/privat yang diblokir (SSRF protection).',
                    'ip' => $ip
                ];
            }
        }

        return ['valid' => true, 'error' => null, 'ip' => $ips[0]];
    }

    /**
     * Checks if an IP is private, reserved, loopback, or link-local.
     */
    public static function isPrivateOrReservedIp(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE: fails on private networks like 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
        // FILTER_FLAG_NO_RES_RANGE: fails on reserved ranges like 127.0.0.0/8, 169.254.0.0/16, 0.0.0.0/8
        $isValidPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isValidPublic === false) {
            return true;
        }

        // Additional safety check for loopback and link-local IPv4
        $long = ip2long($ip);
        if ($long !== false) {
            // 127.0.0.0/8
            if (($long & 0xFF000000) === 0x7F000000) return true;
            // 10.0.0.0/8
            if (($long & 0xFF000000) === 0x0A000000) return true;
            // 172.16.0.0/12
            if (($long & 0xFFF00000) === 0xAC100000) return true;
            // 192.168.0.0/16
            if (($long & 0xFFFF0000) === 0xC0A80000) return true;
            // 169.254.0.0/16
            if (($long & 0xFFFF0000) === 0xA9FE0000) return true;
            // 0.0.0.0/8
            if (($long & 0xFF000000) === 0x00000000) return true;
        }

        return false;
    }

    /**
     * Safely fetches a URL with redirect validation, timeout, and max size limit.
     * Returns array with 'status', 'body', 'effective_url', 'error'.
     */
    public static function safeFetch(string $url, int $timeout = 15, int $maxBytes = 5242880): array
    {
        $currentUrl = $url;
        $maxRedirects = 5;
        $redirectCount = 0;

        while ($redirectCount <= $maxRedirects) {
            $validation = self::validateUrl($currentUrl);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'http_code' => 0,
                    'body' => null,
                    'effective_url' => $currentUrl,
                    'error' => $validation['error']
                ];
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $currentUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false, // We handle redirects manually for SSRF security
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 (RecipeImporter/1.0)',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9,id;q=0.8',
                    'Cache-Control: no-cache',
                ],
            ]);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }

            if ($response === false) {
                return [
                    'success' => false,
                    'http_code' => $httpCode,
                    'body' => null,
                    'effective_url' => $currentUrl,
                    'error' => 'Koneksi gagal atau waktu habis: ' . $curlError
                ];
            }

            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);
            if (is_string($body) && function_exists('mb_convert_encoding')) {
                $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
            }

            // Handle HTTP redirects (301, 302, 303, 307, 308)
            if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
                if (preg_match('/^Location:\s*(.+)$/im', $headers, $matches)) {
                    $nextUrl = trim($matches[1]);
                    // Handle relative redirect
                    if (!preg_match('#^https?://#i', $nextUrl)) {
                        $parts = parse_url($currentUrl);
                        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
                        if (str_starts_with($nextUrl, '/')) {
                            $nextUrl = $base . $nextUrl;
                        } else {
                            $path = dirname($parts['path'] ?? '/');
                            $nextUrl = $base . rtrim($path, '/') . '/' . $nextUrl;
                        }
                    }
                    $currentUrl = $nextUrl;
                    $redirectCount++;
                    continue;
                }
            }

            // Check size
            if (strlen($body) > $maxBytes) {
                return [
                    'success' => false,
                    'http_code' => $httpCode,
                    'body' => null,
                    'effective_url' => $currentUrl,
                    'error' => 'Ukuran respons melebihi batas maksimum (' . round($maxBytes / 1048576, 1) . ' MB).'
                ];
            }

            // Check HTTP status code
            if ($httpCode === 403) {
                return [
                    'success' => false,
                    'http_code' => 403,
                    'body' => null,
                    'effective_url' => $currentUrl,
                    'error' => 'Sumber menolak akses (HTTP 403 Forbidden). Situs membatasi permintaan otomatis.'
                ];
            }

            if ($httpCode === 404) {
                return [
                    'success' => false,
                    'http_code' => 404,
                    'body' => null,
                    'effective_url' => $currentUrl,
                    'error' => 'Halaman resep tidak ditemukan di server sumber (HTTP 404 Not Found).'
                ];
            }

            if ($httpCode === 429) {
                return [
                    'success' => false,
                    'http_code' => 429,
                    'body' => null,
                    'effective_url' => $currentUrl,
                    'error' => 'Terlalu banyak permintaan ke situs sumber (HTTP 429 Too Many Requests).'
                ];
            }

            if ($httpCode >= 400) {
                return [
                    'success' => false,
                    'http_code' => $httpCode,
                    'body' => null,
                    'effective_url' => $currentUrl,
                    'error' => 'Sumber mengembalikan status error HTTP ' . $httpCode . '.'
                ];
            }

            return [
                'success' => true,
                'http_code' => $httpCode,
                'body' => $body,
                'effective_url' => $currentUrl,
                'error' => null
            ];
        }

        return [
            'success' => false,
            'http_code' => 0,
            'body' => null,
            'effective_url' => $currentUrl,
            'error' => 'Terlalu banyak pengalihan (lebih dari ' . $maxRedirects . ' redirect).'
        ];
    }
}
