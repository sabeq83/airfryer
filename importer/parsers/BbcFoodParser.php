<?php

namespace Importer\Parsers;

use Exception;

class BbcFoodParser implements ParserInterface
{
    public function supports(string $url): bool
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        return str_contains($host, 'bbc.co.uk') || str_contains($host, 'bbc.com');
    }

    public function parse(string $html, string $url): array
    {
        if (empty(trim($html))) {
            throw new Exception('Halaman kosong diterima dari sumber.');
        }

        $recipeData = null;
        $rawPayload = [];

        // 1. Try to extract schema.org/Recipe from ld+json scripts
        if (preg_match_all('/<script[^>]*type=[\"\']application\/ld\+json[\"\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $block) {
                $decoded = json_decode(trim($block), true);
                if (!$decoded) {
                    continue;
                }

                if (isset($decoded['@type']) && $decoded['@type'] === 'Recipe') {
                    $recipeData = $decoded;
                    $rawPayload = $decoded;
                    break;
                }

                if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                    foreach ($decoded['@graph'] as $graphItem) {
                        if (isset($graphItem['@type']) && $graphItem['@type'] === 'Recipe') {
                            $recipeData = $graphItem;
                            $rawPayload = $graphItem;
                            break 2;
                        }
                    }
                }
            }
        }

        // Title
        $title = '';
        if ($recipeData && !empty($recipeData['name'])) {
            $title = html_entity_decode(trim($recipeData['name']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (empty($title)) {
            throw new Exception('Data tidak lengkap: Judul resep tidak ditemukan pada halaman sumber.');
        }

        // Summary / Description
        $summary = '';
        if ($recipeData && !empty($recipeData['description'])) {
            $summary = html_entity_decode(trim($recipeData['description']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('/<meta[^>]*name=[\"\']description[\"\'][^>]*content=[\"\'](.*?)[\"\']/is', $html, $m)) {
            $summary = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Prep Time
        $prepTime = '';
        if ($recipeData && !empty($recipeData['prepTime'])) {
            $prepTime = self::formatIsoDuration($recipeData['prepTime']);
        }

        // Cook Time
        $cookTime = '';
        if ($recipeData && !empty($recipeData['cookTime'])) {
            $cookTime = self::formatIsoDuration($recipeData['cookTime']);
        }

        // Servings / Yield
        $servings = '';
        if ($recipeData && !empty($recipeData['recipeYield'])) {
            if (is_array($recipeData['recipeYield'])) {
                $servings = implode(', ', $recipeData['recipeYield']);
            } else {
                $servings = (string)$recipeData['recipeYield'];
            }
            $servings = html_entity_decode(trim($servings), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Fallback for metadata from HTML if ld+json didn't have times
        if (empty($prepTime) && preg_match('/class=[\"\'][^\"\']*prep-time[^\"\']*[\"\'][^>]*>(.*?)<\/p>/is', $html, $m)) {
            $prepTime = trim(strip_tags($m[1]));
        }
        if (empty($cookTime) && preg_match('/class=[\"\'][^\"\']*cook-time[^\"\']*[\"\'][^>]*>(.*?)<\/p>/is', $html, $m)) {
            $cookTime = trim(strip_tags($m[1]));
        }
        if (empty($servings) && preg_match('/class=[\"\'][^\"\']*serving[^\"\']*[\"\'][^>]*>(.*?)<\/p>/is', $html, $m)) {
            $servings = trim(strip_tags($m[1]));
        }

        // Combined total time
        $totalTime = '';
        if ($prepTime && $cookTime) {
            $totalTime = 'Prep: ' . $prepTime . ' | Cook: ' . $cookTime;
        } elseif ($cookTime) {
            $totalTime = $cookTime;
        } elseif ($prepTime) {
            $totalTime = $prepTime;
        }

        // Ingredients
        $ingredients = [];
        // First try to extract grouped ingredients from HTML for richer grouping
        $groupedFromHtml = self::extractIngredientsFromHtml($html);
        if (!empty($groupedFromHtml)) {
            $ingredients = $groupedFromHtml;
        } elseif ($recipeData && !empty($recipeData['recipeIngredient']) && is_array($recipeData['recipeIngredient'])) {
            $cleanItems = [];
            foreach ($recipeData['recipeIngredient'] as $ing) {
                $cleaned = html_entity_decode(trim(strip_tags($ing)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($cleaned !== '') {
                    $cleanItems[] = $cleaned;
                }
            }
            if (!empty($cleanItems)) {
                $ingredients = [
                    [
                        'group' => 'Ingredients',
                        'items' => $cleanItems,
                    ]
                ];
            }
        }

        if (empty($ingredients)) {
            throw new Exception('Data tidak lengkap: Daftar bahan tidak ditemukan pada halaman sumber.');
        }

        // Instructions
        $instructions = [];
        if ($recipeData && !empty($recipeData['recipeInstructions']) && is_array($recipeData['recipeInstructions'])) {
            foreach ($recipeData['recipeInstructions'] as $step) {
                if (is_string($step)) {
                    $cleaned = html_entity_decode(trim(strip_tags($step)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($cleaned !== '') {
                        $instructions[] = $cleaned;
                    }
                } elseif (is_array($step)) {
                    if (isset($step['text'])) {
                        $cleaned = html_entity_decode(trim(strip_tags($step['text'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if ($cleaned !== '') {
                            $instructions[] = $cleaned;
                        }
                    } elseif (isset($step['itemListElement']) && is_array($step['itemListElement'])) {
                        foreach ($step['itemListElement'] as $sub) {
                            if (isset($sub['text'])) {
                                $cleaned = html_entity_decode(trim(strip_tags($sub['text'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                if ($cleaned !== '') {
                                    $instructions[] = $cleaned;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Fallback for instructions from HTML
        if (empty($instructions)) {
            $instructions = self::extractInstructionsFromHtml($html);
        }

        if (empty($instructions)) {
            throw new Exception('Data tidak lengkap: Langkah-langkah instruksi resep tidak ditemukan pada halaman sumber.');
        }

        // Image Candidates
        $imageCandidates = [];
        if ($recipeData && !empty($recipeData['image'])) {
            if (is_string($recipeData['image'])) {
                $imageCandidates[] = $recipeData['image'];
            } elseif (is_array($recipeData['image'])) {
                if (isset($recipeData['image']['url'])) {
                    $imageCandidates[] = $recipeData['image']['url'];
                } else {
                    foreach ($recipeData['image'] as $img) {
                        if (is_string($img)) {
                            $imageCandidates[] = $img;
                        } elseif (is_array($img) && isset($img['url'])) {
                            $imageCandidates[] = $img['url'];
                        }
                    }
                }
            }
        }

        // Look for og:image
        if (preg_match('/<meta[^>]*property=[\"\']og:image[\"\'][^>]*content=[\"\'](.*?)[\"\']/is', $html, $m)) {
            $ogUrl = trim($m[1]);
            if ($ogUrl && !in_array($ogUrl, $imageCandidates, true)) {
                $imageCandidates[] = $ogUrl;
            }
        }

        // Clean image URLs (remove duplicate schemes, normalize)
        $cleanImages = [];
        foreach ($imageCandidates as $imgUrl) {
            $imgUrl = trim($imgUrl);
            if (preg_match('#^https?://#i', $imgUrl) && !in_array($imgUrl, $cleanImages, true)) {
                $cleanImages[] = $imgUrl;
            }
        }

        return [
            'recipe_title' => $title,
            'prep_time' => $prepTime,
            'cook_time' => $cookTime,
            'total_time' => $totalTime,
            'servings' => $servings,
            'summary' => $summary,
            'ingredients' => $ingredients,
            'instructions' => $instructions,
            'image_candidates' => $cleanImages,
            'raw_payload' => $rawPayload,
        ];
    }

    /**
     * Converts ISO 8601 duration string (e.g. PT30M, PT1H15M) into human readable time.
     */
    public static function formatIsoDuration(string $duration): string
    {
        $duration = trim($duration);
        if (empty($duration)) {
            return '';
        }

        // If it's already a human string like "30 mins" or "10-30 minutes"
        if (!str_starts_with($duration, 'P')) {
            return $duration;
        }

        try {
            $interval = new \DateInterval($duration);
            $parts = [];
            if ($interval->d > 0) {
                $parts[] = $interval->d . ($interval->d > 1 ? ' days' : ' day');
            }
            if ($interval->h > 0) {
                $parts[] = $interval->h . ($interval->h > 1 ? ' hrs' : ' hr');
            }
            if ($interval->i > 0) {
                $parts[] = $interval->i . ' mins';
            }
            if (empty($parts) && $interval->s > 0) {
                $parts[] = $interval->s . ' secs';
            }
            return implode(' ', $parts);
        } catch (\Exception $e) {
            // Regex fallback
            $hours = 0;
            $mins = 0;
            if (preg_match('/(\d+)H/i', $duration, $m)) {
                $hours = (int)$m[1];
            }
            if (preg_match('/(\d+)M/i', $duration, $m)) {
                $mins = (int)$m[1];
            }
            $res = [];
            if ($hours > 0) $res[] = $hours . ' hr' . ($hours > 1 ? 's' : '');
            if ($mins > 0) $res[] = $mins . ' mins';
            return !empty($res) ? implode(' ', $res) : $duration;
        }
    }

    /**
     * Parses ingredient groups from BBC Food HTML structure.
     */
    private static function extractIngredientsFromHtml(string $html): array
    {
        $groups = [];

        // Check if there is an ingredients block
        if (!preg_match('/recipe-ingredients[\"\'\s][^>]*>(.*?)<\/section>/is', $html, $sectionMatch)) {
            // Fallback: search between 'Ingredients</h2>' and method header
            if (!preg_match('/Ingredients<\/h[1-4]>(.*?)<h[1-4][^>]*>(?:Method|Instructions|Preparation)/is', $html, $sectionMatch)) {
                return [];
            }
        }

        $sectionHtml = $sectionMatch[1];

        // Match groups: <h3>Group Header</h3> followed by <ul><li>...</li></ul>
        // or just standalone <ul> lists
        if (preg_match_all('/(?:<h3[^>]*>(.*?)<\/h3>)?\s*<div[^>]*>\s*<ul[^>]*>(.*?)<\/ul>/is', $sectionHtml, $groupMatches, PREG_SET_ORDER)) {
            foreach ($groupMatches as $gm) {
                $groupName = !empty($gm[1]) ? html_entity_decode(trim(strip_tags($gm[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'Ingredients';
                $ulContent = $gm[2];
                $items = [];
                if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $ulContent, $liMatches)) {
                    foreach ($liMatches[1] as $li) {
                        $itemText = html_entity_decode(trim(strip_tags($li)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if ($itemText !== '') {
                            $items[] = $itemText;
                        }
                    }
                }
                if (!empty($items)) {
                    $groups[] = [
                        'group' => $groupName,
                        'items' => $items,
                    ];
                }
            }
        }

        return $groups;
    }

    /**
     * Parses instruction steps from HTML if ld+json is absent.
     */
    private static function extractInstructionsFromHtml(string $html): array
    {
        $steps = [];
        if (preg_match('/(?:recipe-method|Method)<\/h[1-4]>(.*?)<\/section>/is', $html, $m)) {
            $methodHtml = $m[1];
            if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $methodHtml, $liMatches)) {
                foreach ($liMatches[1] as $li) {
                    $stepText = html_entity_decode(trim(strip_tags($li)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($stepText !== '') {
                        $steps[] = $stepText;
                    }
                }
            }
        }
        return $steps;
    }
}
