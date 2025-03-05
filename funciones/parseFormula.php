<?php

/**
 * Convert a raw ODS formula (e.g. "of:=[.C7]*0.21" or "of:=SUM([.B2:.B5])")
 * into a PHP expression string.
 *
 * @param string $odsFormula The formula text as found in the ODS ("of:=[.C7]*0.21")
 * @param array  $colMap     Associative array that maps ODS cell references to row field names.
 *                           E.g. ['C' => 'precio', 'B' => 'cantidad'].
 *
 * Returns a string of valid PHP code, e.g. "$this->cellVal($row['precio']) * 0.21"
 *
 * WARNING: This is a simplified example that only handles:
 * - Single-cell references like `[.C7]` (ignores the row number, just uses the column letter).
 * - Basic arithmetic operators (+ - * / ^).
 * - Possibly a couple built-in ODS function synonyms: SUM, etc. (very incomplete).
 * - Does not handle multiple sheets, range references, arrays, etc.
 */
function parseOdsFormulaToPhp($odsFormula, $colMap = [])
{
    // Remove any 'of:=' prefix
    $formula = preg_replace('/^of:=/i', '', trim($odsFormula));

    // We want to transform any `[.Xnn]` or `[.XYnn]` references to something in $colMap
    // For example, if we see `[.C7]`, we parse out the column "C".
    // The row number "7" is ignored in this simplistic approach, since we only do "same row" logic.
    //
    // We'll do this via a regex:
    //   \[\.[A-Z]+[0-9]+\]
    //   capturing the column part ([A-Z]+).
    $formula = preg_replace_callback(
        '/\[\.([A-Z]+)(\d+)\]/i', // e.g. "[.C7]" -> column "C", row "7"
        function ($matches) use ($colMap) {
            $colLetter = strtoupper($matches[1]); // e.g. "C"
            // rowNumber = $matches[2]; // e.g. "7" but we ignore it here
            if (isset($colMap[$colLetter])) {
                $fieldName = $colMap[$colLetter];
                // Return a snippet that references $row['fieldName'] 
                // possibly wrapped in a function that converts to float
                return "\$this->cellVal(\$row['$fieldName'])";
            } else {
                // If we don't have a known mapping, fallback 
                return "/* unknown_col_$colLetter */ 0";
            }
        },
        $formula
    );

    // Let's handle ODS functions like `SUM(...)`, `ROUND(...)`, etc., in a trivial manner.
    // We can map them to small helper methods in PHP. E.g. "SUM" -> "$this->sum(...)"
    // This can be done by a simple str_replace or a small regex:
    $mapFunctions = [
        '/\bSUM\s*\(/i'   => '$this->sum(',
        '/\bROUND\s*\(/i' => '$this->round(',
        // add others as needed
    ];
    foreach ($mapFunctions as $pattern => $replace) {
        $formula = preg_replace($pattern, $replace, $formula);
    }

    // Now $formula might look like: "$this->cellVal($row['precio']) * 0.21"
    // We'll return that string for embedding in a PHP function.
    return $formula;
}

/**
 * Example helper to safely convert a string like "34.00 €" to a float.
 */
function cellVal($val) {
    // Remove all but digits, dot, minus sign
    $num = preg_replace('/[^0-9.\-]+/', '', $val);
    return floatval($num);
}

/**
 * Example helper to sum an array or list of values.
 * In real usage, you'd parse all references inside SUM(...) into separate calls, etc.
 */
function sum(...$vals) {
    $total = 0;
    foreach ($vals as $v) {
        $total += $v;
    }
    return $total;
}

/**
 * Example helper to mimic ROUND(...) from ODS
 */
function roundVal($val, $precision = 0) {
    return round($val, $precision);
}

