<?php

namespace Importer\Parsers;

interface ParserInterface
{
    /**
     * Determines if this parser handles the given domain or URL.
     */
    public function supports(string $url): bool;

    /**
     * Parses the HTML body and returns extracted recipe data or throws an Exception with clear error.
     *
     * Expected return structure:
     * [
     *   'recipe_title' => string,
     *   'prep_time' => string,
     *   'cook_time' => string,
     *   'total_time' => string,
     *   'servings' => string,
     *   'summary' => string,
     *   'ingredients' => array, // list of items or list of ['group' => string, 'items' => array]
     *   'instructions' => array, // list of strings
     *   'image_candidates' => array, // list of image URLs
     *   'raw_payload' => array
     * ]
     */
    public function parse(string $html, string $url): array;
}
