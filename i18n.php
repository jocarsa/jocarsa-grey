<?php
// i18n.php

/**
 * Loads translations from translations.csv into a global array.
 * Returns an associative array: [ 'key' => [ 'en' => '...', 'es' => '...', ...], ... ]
 */
function loadTranslations() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $file = __DIR__ . '/translations.csv';
    if (!file_exists($file)) {
        // fallback: no translations
        $cached = [];
        return $cached;
    }

    $translations = [];
    if (($handle = fopen($file, 'r')) !== false) {
        // First row is the header
        $header = fgetcsv($handle);
        // e.g. $header = ['key','en','es','fr','de','it','ja','ko','zh']
        while (($row = fgetcsv($handle)) !== false) {
            $rowData = array_combine($header, $row);
            $key = $rowData['key'];
            // remove 'key' from row data
            unset($rowData['key']);
            $translations[$key] = $rowData;
        }
        fclose($handle);
    }

    $cached = $translations;
    return $cached;
}

/**
 * t($key) returns the translation string according to $_SESSION['lang'], or fallback to 'en'.
 */
function t($key) {
    $all = loadTranslations();
    $lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'en'; // default to English
    if (isset($all[$key])) {
        // If there's a translation for the chosen language, use it; else fallback to English
        return $all[$key][$lang] ?? $all[$key]['en'];
    }
    // if key not found, just return the key for debugging
    return $key;
}

