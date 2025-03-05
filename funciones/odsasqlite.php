<?php
/**
 * ----------------------------------------------------------------------------
 * ODS → SQLite Importer with Basic Formula-to-PHP Generation
 * ----------------------------------------------------------------------------
 *
 * 1) Downloads an ODS from $miurl.
 * 2) Extracts content.xml.
 * 3) Creates a SQLite DB named "$nombrebasededatos.db".
 * 4) Builds tables for each sheet, inserts rows.
 * 5) Detects formula cells (via table:formula).
 * 6) Generates "method" .php files that contain real PHP code to evaluate the formula.
 *
 * Usage:
 *   require 'odsasqlite.php';
 *   odsasqlite($url, $nombreDBWithoutExtension);
 *
 * The importer expects a 'metodos' folder **one level up** from this file's directory
 * (adjust path inside the code if you prefer a different location).
 * Make sure that folder is writable by PHP.
 *
 * ----------------------------------------------------------------------------
 * IMPORTANT: This is a simplified demonstration. It does NOT handle:
 * - Multi-sheet references
 * - Range references like [.B2:.B5]
 * - Column letters beyond "Z"
 * - Many built-in ODS functions
 * - Complex formula error checking
 * - Security concerns with generating/evaluating code
 * ----------------------------------------------------------------------------
 */

/**
 * Convert a raw ODS formula string (e.g. "of:=[.C7]*0.21") to a snippet of PHP code
 * (e.g. "$this->cellVal(\$row['precio']) * 0.21"), given a map of column letters → DB fields.
 *
 * Minimal handling of:
 *   - Removing `of:=`
 *   - Replacing `[.A1]` with `$this->cellVal($row['...'])`
 *   - Basic arithmetic operators (+ - * / ^)
 *   - A few ODS function synonyms (SUM, ROUND, etc.)
 */
function parseOdsFormulaToPhp($odsFormula, $colMap = [])
{
    // Remove "of:=" prefix if present
    $formula = preg_replace('/^of:=/i', '', trim($odsFormula));

    // Replace references `[.Xnn]` or `[.XYnn]` (we only handle A-Z for demo).
    // We'll ignore the row number, focusing on the column letter(s).
    // Regex captures column letters in $matches[1], row number in $matches[2].
    $formula = preg_replace_callback(
        '/\[\.([A-Z]+)(\d+)\]/i', // e.g. "[.C7]" -> "C" "7"
        function ($matches) use ($colMap) {
            $colLetter = strtoupper($matches[1]); // e.g. "C"
            // rowNumber = $matches[2]; // e.g. "7" (ignored in simplistic approach)

            if (isset($colMap[$colLetter])) {
                $fieldName = $colMap[$colLetter];
                // Return a snippet that references $row['<fieldName>'] as a float
                return "\$this->cellVal(\$row['$fieldName'])";
            } else {
                // If no known mapping, fallback to zero or comment
                return "(/*unknown_col_$colLetter*/ 0)";
            }
        },
        $formula
    );

    // Map certain ODS function calls to pseudo-PHP functions.
    // This is incomplete and only handles a few known names.
    $mapFunctions = [
        '/\bSUM\s*\(/i'   => '$this->sum(',
        '/\bROUND\s*\(/i' => '$this->roundVal(',
        // add more if desired
    ];
    foreach ($mapFunctions as $pattern => $replacement) {
        $formula = preg_replace($pattern, $replacement, $formula);
    }

    // The formula may now look like: "$this->cellVal($row['precio']) * 0.21"
    return $formula;
}

/**
 * Convert a cell string (e.g. "34.00 €") to a float.
 * You can expand or adapt as needed.
 */
function cellVal($val)
{
    $num = preg_replace('/[^0-9.\-]+/', '', $val);
    return floatval($num);
}

/**
 * Summation helper for minimal SUM(...) function usage.
 * Example usage: SUM( cellVal($row['col1']), cellVal($row['col2']) )
 */
function sum(...$vals)
{
    $total = 0;
    foreach ($vals as $v) {
        $total += $v;
    }
    return $total;
}

/**
 * Round helper to mimic ROUND(value, precision) usage from ODS
 */
function roundVal($val, $precision = 0)
{
    return round($val, $precision);
}

/**
 * Clean and normalize table column names (from the header row).
 */
function cleanColumnName($name) {
    // Convert accented characters to their ASCII approximation
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    // Replace non-alphanumeric chars with underscores
    $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $normalized);
    // Remove duplicate underscores
    $clean = preg_replace('/_+/', '_', $clean);
    // Trim underscores from beginning/end
    $clean = trim($clean, '_');
    // If the cleaned name starts with a digit, prepend underscore
    if (preg_match('/^\d/', $clean)) {
        $clean = '_' . $clean;
    }
    return ($clean !== '') ? $clean : 'column';
}


/**
 * Main function: Download an ODS, parse content, build a SQLite DB,
 * detect formula cells, and generate "method" files.
 */
function odsasqlite($miurl, $nombrebasededatos)
{
    // 1) Download the ODS file to a temp location
    $url      = $miurl;
    $tempFile = tempnam(sys_get_temp_dir(), 'ods');
    file_put_contents($tempFile, file_get_contents($url));

    // 2) Extract content.xml from the ODS
    $zip = new ZipArchive;
    if ($zip->open($tempFile) === TRUE) {
        $xmlContent = $zip->getFromName('content.xml');
        $zip->close();
    } else {
        die("Error opening the ODS file.");
    }

    // 3) Load the XML and retrieve namespaces
    $xml        = simplexml_load_string($xmlContent);
    $namespaces = $xml->getNamespaces(true);

    // 4) Navigate to the <office:spreadsheet> element
    $office      = $xml->children($namespaces['office']);
    $spreadsheet = $office->body->spreadsheet;

    // 5) Create/Open SQLite DB
    try {
        $db = new PDO('sqlite:'.$nombrebasededatos.'.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        die("SQLite Connection failed: " . $e->getMessage());
    }

    // Keep track of formulas found: $columnFormulas[tableName][columnIndex] = <formula>
    $columnFormulas = [];

    // 6) Process each sheet (table:table)
    $tables = $spreadsheet->children($namespaces['table'])->table;
    if ($tables) {
        foreach ($tables as $table) {
            // 6a) Get sheet name & clean it
            $tableAttrs      = $table->attributes($namespaces['table']);
            $sheetName       = (string)$tableAttrs['name']; // e.g. "Hoja1"
            $sheetNameClean  = preg_replace('/[^a-zA-Z0-9_]/', '_', $sheetName);
            if (preg_match('/^\d/', $sheetNameClean)) {
                $sheetNameClean = '_'.$sheetNameClean;
            }

            // Initialize array for this sheet
            $columnFormulas[$sheetNameClean] = [];

            // 6b) Get all rows
            $rows = $table->children($namespaces['table'])->{'table-row'};
            if (!count($rows)) {
                continue; // skip if no rows
            }

            // 6c) Process header row: build column names
            $headerRow   = $rows[0];
            $headerCells = $headerRow->children($namespaces['table'])->{'table-cell'};
            $columns     = [];      // final array of cleaned column names
            $colMapping  = [];      // position in row → index in $columns
            $pos         = 0;

            foreach ($headerCells as $cell) {
                $cellAttrs = $cell->attributes($namespaces['table']);
                // may contain "number-columns-repeated"
                $repeat = isset($cellAttrs['number-columns-repeated']) 
                          ? (int)$cellAttrs['number-columns-repeated']
                          : 1;

                $text      = $cell->children($namespaces['text'])->{'p'};
                $cellValue = trim((string)$text);

                for ($i = 0; $i < $repeat; $i++) {
                    if ($cellValue !== '') {
                        $colNameClean = cleanColumnName($cellValue);
                        // avoid duplicates
                        $original = $colNameClean;
                        $suffix   = 1;
                        while (in_array($colNameClean, $columns)) {
                            $colNameClean = $original.'_'.$suffix;
                            $suffix++;
                        }
                        $columns[]        = $colNameClean;
                        $colMapping[$pos] = count($columns) - 1;
                    }
                    $pos++;
                }
            }

            // Skip if no columns
            if (empty($columns)) {
                continue;
            }

            // 6d) Create the table (if not exists)
            $createSQL = "CREATE TABLE IF NOT EXISTS \"$sheetNameClean\" (id INTEGER PRIMARY KEY AUTOINCREMENT";
            foreach ($columns as $col) {
                $createSQL .= ", \"$col\" TEXT";
            }
            $createSQL .= ")";
            $db->exec($createSQL);

            // Prepare the INSERT
            $colList      = implode(', ', array_map(fn($c)=>"\"$c\"", $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $insertSQL    = "INSERT INTO \"$sheetNameClean\" ($colList) VALUES ($placeholders)";
            $stmtInsert   = $db->prepare($insertSQL);

            // 6e) Process data rows (skip row[0], the header)
            for ($r = 1; $r < count($rows); $r++) {
                $rowElem = $rows[$r];
                $cells   = $rowElem->children($namespaces['table'])->{'table-cell'};
                $rowDataAll = [];
                $pos       = 0;

                foreach ($cells as $cell) {
                    $cellAttrs = $cell->attributes($namespaces['table']);
                    $repeat    = isset($cellAttrs['number-columns-repeated']) 
                                 ? (int)$cellAttrs['number-columns-repeated'] 
                                 : 1;

                    // Detect formula
                    if (isset($cellAttrs['formula'])) {
                        $cellFormula = (string)$cellAttrs['formula'];
                        // For each repeated column position, note that formula
                        for ($rep = 0; $rep < $repeat; $rep++) {
                            // If that position maps to a known column index...
                            if (isset($colMapping[$pos + $rep])) {
                                $colIndex = $colMapping[$pos + $rep];
                                // record this formula for the column
                                $columnFormulas[$sheetNameClean][$colIndex] = $cellFormula;
                            }
                        }
                    }

                    // Get the text
                    $text      = $cell->children($namespaces['text'])->{'p'};
                    $cellValue = trim((string)$text);

                    // Store repeated times
                    for ($rep = 0; $rep < $repeat; $rep++) {
                        $rowDataAll[$pos] = $cellValue;
                        $pos++;
                    }
                }

                // Build final row data for columns
                $finalRow = array_fill(0, count($columns), '');
                foreach ($colMapping as $cellPos => $colIndex) {
                    $finalRow[$colIndex] = $rowDataAll[$cellPos] ?? '';
                }

                // Skip if entire row is empty
                $allEmpty = true;
                foreach ($finalRow as $val) {
                    if ($val !== '') {
                        $allEmpty = false;
                        break;
                    }
                }
                if ($allEmpty) {
                    continue;
                }

                // Insert
                $stmtInsert->execute($finalRow);
            }

            // Now we've inserted all rows for this sheet,
            // let's generate "method" files for columns that had at least one formula
            if (!empty($columnFormulas[$sheetNameClean])) {
                // We'll place them in ../metodos/<TableName> relative to this file
                // Adjust path if needed
                $methodsRoot = __DIR__ . '/../metodos';
                if (!is_dir($methodsRoot)) {
                    mkdir($methodsRoot, 0777, true);
                }

                $tableMethodsDir = $methodsRoot . "/$sheetNameClean";
                if (!is_dir($tableMethodsDir)) {
                    mkdir($tableMethodsDir, 0777, true);
                }

                // Create a naive map A->columns[0], B->columns[1], etc. (only up to 26)
                // If your sheet has more than 26 columns, you'd need a bigger approach
                $alphabet = range('A','Z');
                $colMap   = [];
                foreach ($columns as $idx => $colName) {
                    if (isset($alphabet[$idx])) {
                        $colMap[$alphabet[$idx]] = $colName;
                    }
                }

                // For each formula column, generate the method file with real code
                foreach ($columnFormulas[$sheetNameClean] as $colIdx => $rawFormula) {
                    $colName   = $columns[$colIdx];
                    $phpExpr   = parseOdsFormulaToPhp($rawFormula, $colMap);

                    $stub  = "<?php\n";
                    $stub .= "/**\n";
                    $stub .= " * Auto-generated method for column '$colName' (ODS formula: $rawFormula)\n";
                    $stub .= " * This file attempts to replicate the formula in PHP.\n";
                    $stub .= " * \n";
                    $stub .= " * \$row is provided by run_method.php.\n";
                    $stub .= " */\n\n";

                    // We define a small class or trait context so we can call $this->cellVal, etc.
                    $stub .= "class FormulaRunner {\n";
                    $stub .= "    public function cellVal(\$val) {\n";
                    $stub .= "        \$num = preg_replace('/[^0-9.\\-]+/', '', \$val);\n";
                    $stub .= "        return floatval(\$num);\n";
                    $stub .= "    }\n\n";
                    $stub .= "    public function sum(...\$vals) {\n";
                    $stub .= "        \$total = 0;\n";
                    $stub .= "        foreach (\$vals as \$v) {\n";
                    $stub .= "            \$total += \$v;\n";
                    $stub .= "        }\n";
                    $stub .= "        return \$total;\n";
                    $stub .= "    }\n\n";
                    $stub .= "    public function roundVal(\$val, \$precision=0) {\n";
                    $stub .= "        return round(\$val, \$precision);\n";
                    $stub .= "    }\n\n";
                    $stub .= "    public function run(\$row) {\n";
                    $stub .= "        \$result = $phpExpr;\n";
                    $stub .= "        echo \"<p>Formula: " . addslashes($rawFormula) . "</p>\";\n";
                    $stub .= "        echo \"<p>Resultado: {\$result}</p>\";\n";
                    $stub .= "    }\n";
                    $stub .= "}\n\n";

                    // Instantiate and run
                    $stub .= "\$runner = new FormulaRunner();\n";
                    $stub .= "\$runner->run(\$row);\n";

                    file_put_contents($tableMethodsDir . "/$colName.php", $stub);
                }
            }

            echo "Processed sheet: $sheetName\n";
        }
    } else {
        echo "No sheets found in the document.\n";
    }

    unlink($tempFile);
}

