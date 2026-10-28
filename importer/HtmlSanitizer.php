<?php

namespace Importer;

class HtmlSanitizer
{
    /**
     * Allowed tags for sanitized rich-text fields.
     */
    private static string $allowedTags = '<p><br><hr><b><strong><i><em><u><ul><ol><li><h3><h4><span>';

    /**
     * Strips disallowed HTML tags, scripts, styles, and dangerous attributes.
     */
    public static function clean(string $html): string
    {
        // 1. Strip script, style, iframe, object, embed tags and their contents
        $html = preg_replace('/<(script|style|iframe|object|embed|applet)[^>]*>.*?<\/\\1>/is', '', $html);

        // 2. Strip disallowed tags
        $clean = strip_tags($html, self::$allowedTags);

        // 3. Remove event handler attributes (onload, onerror, onclick, etc.) and javascript: links
        $clean = preg_replace('/\s*on[a-zA-Z]+\s*=\s*(["\']).*?\\1/is', '', $clean);
        $clean = preg_replace('/\s*on[a-zA-Z]+\s*=[^\s>]+/is', '', $clean);
        $clean = preg_replace('/href\s*=\s*(["\'])\s*javascript:.*?\\1/is', 'href="#"', $clean);

        return trim($clean);
    }

    /**
     * Formats structured recipe data into standard recipe_description HTML matching tbl_recipes convention.
     * Compatible with Android app Tools.displayPostDescription (WebView).
     *
     * @param string $summary
     * @param array $ingredients Array of ['group' => string, 'items' => array] or list of strings
     * @param array $instructions List of string steps
     * @return string
     */
    public static function buildRecipeDescription(string $summary, array $ingredients, array $instructions): string
    {
        $parts = [];

        // 1. SUMMARY section
        if (!empty(trim($summary))) {
            $parts[] = "<p><strong>SUMMARY :</strong></p>";
            $parts[] = "<p>" . nl2br(htmlspecialchars(trim($summary), ENT_QUOTES | ENT_HTML5, 'UTF-8')) . "</p>";
        }

        // 2. INGREDIENTS section
        if (!empty($ingredients)) {
            if (!empty($parts)) {
                $parts[] = "<hr />";
            }
            $parts[] = "<p><strong>INGREDIENTS :</strong></p>";

            // Check if ingredients are grouped or flat
            $isGrouped = false;
            foreach ($ingredients as $item) {
                if (is_array($item) && isset($item['group'], $item['items'])) {
                    $isGrouped = true;
                    break;
                }
            }

            if ($isGrouped) {
                foreach ($ingredients as $g) {
                    $groupName = trim($g['group'] ?? 'Ingredients');
                    $items = $g['items'] ?? [];
                    if (empty($items)) {
                        continue;
                    }
                    if ($groupName !== '' && strtolower($groupName) !== 'ingredients') {
                        $parts[] = "<p><strong>" . htmlspecialchars($groupName, ENT_QUOTES | ENT_HTML5, 'UTF-8') . " :</strong></p>";
                    }
                    $parts[] = "<ul>";
                    foreach ($items as $ing) {
                        $cleanedIng = htmlspecialchars(trim((string)$ing), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if ($cleanedIng !== '') {
                            $parts[] = "\t<li>" . $cleanedIng . "</li>";
                        }
                    }
                    $parts[] = "</ul>";
                }
            } else {
                $parts[] = "<ul>";
                foreach ($ingredients as $ing) {
                    $cleanedIng = htmlspecialchars(trim((string)$ing), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($cleanedIng !== '') {
                        $parts[] = "\t<li>" . $cleanedIng . "</li>";
                    }
                }
                $parts[] = "</ul>";
            }
        }

        // 3. DIRECTION / INSTRUCTIONS section
        if (!empty($instructions)) {
            if (!empty($parts)) {
                $parts[] = "<hr />";
            }
            $parts[] = "<p><strong>DIRECTION :</strong></p>";
            $parts[] = "<ol>";
            foreach ($instructions as $step) {
                $cleanedStep = htmlspecialchars(trim((string)$step), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($cleanedStep !== '') {
                    $parts[] = "\t<li>" . $cleanedStep . "</li>";
                }
            }
            $parts[] = "</ol>";
        }

        return implode("\r\n\r\n", $parts) . "\r\n";
    }
}
