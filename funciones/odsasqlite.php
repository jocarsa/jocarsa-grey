<?php
/**
 * ----------------------------------------------------------------------------
 * ODS → SQLite Importer with Basic Formula-to-PHP Generation and Extended Foreign Key Support
 * ----------------------------------------------------------------------------
 *
 * This importer:
 *  1) Downloads an ODS file from a URL.
 *  2) Extracts content.xml.
 *  3) Creates a SQLite database named "$nombrebasededatos.db".
 *  4) Creates a table for each sheet.
 *  5) Inserts rows from the sheet.
 *  6) Detects formula cells and generates PHP method files.
 *  7) Implements extended foreign key conversion:
 *     - If a header cell is named following the pattern: 
 *         [ReferencedTable]_[DisplayCol1] or
 *         [ReferencedTable]_[DisplayCol1]_[DisplayCol2] or more,
 *       then:
 *         a) The column in the main table is created as INTEGER.
 *         b) The referenced table is created with the specified display columns.
 *         c) During import, display values (e.g. "Juan" or "Juan Garcia") are replaced by the referenced record’s ID.
 *
 * Usage:
 *   require 'odsasqlite.php';
 *   odsasqlite($miurl, $nombrebasededatos);
 *
 * ----------------------------------------------------------------------------
 * NOTE: This is a simplified demonstration and does not cover all edge cases.
 * ----------------------------------------------------------------------------
 */
include "parseFormula.php";
/**
 * Clean and normalize a column name.
 */
function cleanColumnName($name) {
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $normalized);
    $clean = preg_replace('/_+/', '_', $clean);
    $clean = trim($clean, '_');
    if (preg_match('/^\d/', $clean)) {
        $clean = '_' . $clean;
    }
    return ($clean !== '') ? $clean : 'column';
}

/**
 * Detect if a column name follows the foreign key pattern.
 * Now, if the name contains at least one underscore (i.e. at least two parts),
 * we consider it a foreign key field.
 */
function detectForeignKey($colName) {
    $parts = explode('_', $colName);
    if (count($parts) >= 2) {
        return [
            'referenced_table' => $parts[0],
            'display_columns'  => array_slice($parts, 1),
            'original_name'    => $colName
        ];
    }
    return false;
}

/**
 * Main importer function.
 */
function odsasqlite($miurl, $nombrebasededatos)
{
    // 1) Download the ODS file to a temporary location
    $tempFile = tempnam(sys_get_temp_dir(), 'ods');
    file_put_contents($tempFile, file_get_contents($miurl));

    // 2) Extract content.xml from the ODS file
    $zip = new ZipArchive;
    if ($zip->open($tempFile) === TRUE) {
        $xmlContent = $zip->getFromName('content.xml');
        $zip->close();
    } else {
        die("Error opening the ODS file.");
    }

    // 3) Load XML and retrieve namespaces
    $xml = simplexml_load_string($xmlContent);
    $namespaces = $xml->getNamespaces(true);

    // 4) Navigate to the <office:spreadsheet> element
    $office = $xml->children($namespaces['office']);
    $spreadsheet = $office->body->spreadsheet;

    // 5) Create/Open SQLite DB using PDO
    try {
        $db = new PDO('sqlite:' . $nombrebasededatos . '.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        die("SQLite Connection failed: " . $e->getMessage());
    }

    // Keep track of formulas: $columnFormulas[tableName][columnIndex] = <formula>
    $columnFormulas = [];

    // 6) Process each sheet
    $tables = $spreadsheet->children($namespaces['table'])->table;
    if ($tables) {
        foreach ($tables as $table) {
            // 6a) Get sheet name and clean it
            $tableAttrs = $table->attributes($namespaces['table']);
            $sheetName = (string)$tableAttrs['name'];
            $sheetNameClean = preg_replace('/[^a-zA-Z0-9_]/', '_', $sheetName);
            if (preg_match('/^\d/', $sheetNameClean)) {
                $sheetNameClean = '_' . $sheetNameClean;
            }

            // Initialize foreign key mapping for this sheet (column index => meta data)
            $fkMapping = [];

            // Initialize formulas for this sheet
            $columnFormulas[$sheetNameClean] = [];

            // 6b) Process header row: build column names and detect foreign keys
            $headerRow = $table->children($namespaces['table'])->{'table-row'}[0];
            $headerCells = $headerRow->children($namespaces['table'])->{'table-cell'};
            $columns = [];
            $colMapping = [];
            $pos = 0;
            foreach ($headerCells as $cell) {
                $cellAttrs = $cell->attributes($namespaces['table']);
                $repeat = isset($cellAttrs['number-columns-repeated']) ? (int)$cellAttrs['number-columns-repeated'] : 1;
                $text = $cell->children($namespaces['text'])->{'p'};
                $cellValue = trim((string)$text);
                for ($i = 0; $i < $repeat; $i++) {
                    if ($cellValue !== '') {
                        $colNameClean = cleanColumnName($cellValue);
                        // Check for foreign key pattern (supporting one or more display columns)
                        $fkData = detectForeignKey($colNameClean);
                        if ($fkData) {
                            $fkMapping[count($columns)] = $fkData;
                        }
                        // Avoid duplicate column names
                        $original = $colNameClean;
                        $suffix = 1;
                        while (in_array($colNameClean, $columns)) {
                            $colNameClean = $original . '_' . $suffix;
                            $suffix++;
                        }
                        $columns[] = $colNameClean;
                        $colMapping[$pos] = count($columns) - 1;
                    }
                    $pos++;
                }
            }
            if (empty($columns)) {
                continue;
            }

            // 6c) Create the main table – use INTEGER for foreign key columns, TEXT otherwise
            $createSQL = "CREATE TABLE IF NOT EXISTS \"$sheetNameClean\" (id INTEGER PRIMARY KEY AUTOINCREMENT";
            foreach ($columns as $idx => $col) {
                if (isset($fkMapping[$idx])) {
                    $createSQL .= ", \"$col\" INTEGER";
                } else {
                    $createSQL .= ", \"$col\" TEXT";
                }
            }
            $createSQL .= ")";
            $db->exec($createSQL);

            // 6d) Ensure referenced (foreign) tables exist
            if (!empty($fkMapping)) {
                foreach ($fkMapping as $meta) {
                    $refTable = $meta['referenced_table'];
                    $displayCols = $meta['display_columns'];
                    $fkTableSQL = "CREATE TABLE IF NOT EXISTS \"$refTable\" (id INTEGER PRIMARY KEY AUTOINCREMENT";
                    foreach ($displayCols as $col) {
                        $fkTableSQL .= ", \"$col\" TEXT";
                    }
                    $fkTableSQL .= ")";
                    $db->exec($fkTableSQL);
                }
            }

            // 6e) Prepare INSERT statement for the main table
            $colList = implode(', ', array_map(fn($c) => "\"$c\"", $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $insertSQL = "INSERT INTO \"$sheetNameClean\" ($colList) VALUES ($placeholders)";
            $stmtInsert = $db->prepare($insertSQL);

            // 6f) Process data rows (skip header)
            $rows = $table->children($namespaces['table'])->{'table-row'};
            for ($r = 1; $r < count($rows); $r++) {
                $rowElem = $rows[$r];
                $cells = $rowElem->children($namespaces['table'])->{'table-cell'};
                $rowDataAll = [];
                $pos = 0;
                foreach ($cells as $cell) {
                    $cellAttrs = $cell->attributes($namespaces['table']);
                    $repeat = isset($cellAttrs['number-columns-repeated']) ? (int)$cellAttrs['number-columns-repeated'] : 1;
                    // Detect formula cells
                    if (isset($cellAttrs['formula'])) {
                        $cellFormula = (string)$cellAttrs['formula'];
                        for ($rep = 0; $rep < $repeat; $rep++) {
                            if (isset($colMapping[$pos + $rep])) {
                                $colIndex = $colMapping[$pos + $rep];
                                $columnFormulas[$sheetNameClean][$colIndex] = $cellFormula;
                            }
                        }
                    }
                    $text = $cell->children($namespaces['text'])->{'p'};
                    $cellValue = trim((string)$text);
                    for ($rep = 0; $rep < $repeat; $rep++) {
                        $rowDataAll[$pos] = $cellValue;
                        $pos++;
                    }
                }
                $finalRow = array_fill(0, count($columns), '');
                foreach ($colMapping as $cellPos => $colIndex) {
                    $finalRow[$colIndex] = $rowDataAll[$cellPos] ?? '';
                }
                // Skip entirely empty rows
                $allEmpty = true;
                foreach ($finalRow as $val) {
                    if ($val !== '') { $allEmpty = false; break; }
                }
                if ($allEmpty) { continue; }

                // 6g) Process foreign key columns: convert display text into the referenced record’s id
                if (!empty($fkMapping)) {
                    foreach ($fkMapping as $colIndex => $fkMeta) {
                        $cellValue = $finalRow[$colIndex];
                        if (trim($cellValue) === '') continue;
                        $refTable = $fkMeta['referenced_table'];
                        $displayColumns = $fkMeta['display_columns'];
                        // Split the display value using whitespace (adjust if needed)
                        $parts = preg_split('/\s+/', trim($cellValue));
                        while (count($parts) < count($displayColumns)) {
                            $parts[] = '';
                        }
                        $conditions = [];
                        $params = [];
                        foreach ($displayColumns as $i => $colName) {
                            $conditions[] = "\"$colName\" = ?";
                            $params[] = $parts[$i];
                        }
                        $whereClause = implode(" AND ", $conditions);
                        $stmt = $db->prepare("SELECT id FROM \"$refTable\" WHERE $whereClause LIMIT 1");
                        $stmt->execute($params);
                        $result = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($result && isset($result['id'])) {
                            $finalRow[$colIndex] = $result['id'];
                        } else {
                            $colsList = implode(', ', array_map(fn($col) => "\"$col\"", $displayColumns));
                            $placeholdersFK = implode(', ', array_fill(0, count($displayColumns), '?'));
                            $insertStmt = $db->prepare("INSERT INTO \"$refTable\" ($colsList) VALUES ($placeholdersFK)");
                            $insertStmt->execute($params);
                            $finalRow[$colIndex] = $db->lastInsertId();
                        }
                    }
                }

                // 6h) Insert the processed row into the main table
                $stmtInsert->execute($finalRow);
            }

            // 6i) Generate method files for columns that had formulas
            if (!empty($columnFormulas[$sheetNameClean])) {
                $methodsRoot = __DIR__ . '/../metodos';
                if (!is_dir($methodsRoot)) {
                    mkdir($methodsRoot, 0777, true);
                }
                $tableMethodsDir = $methodsRoot . "/$sheetNameClean";
                if (!is_dir($tableMethodsDir)) {
                    mkdir($tableMethodsDir, 0777, true);
                }
                $alphabet = range('A','Z');
                $colMap = [];
                foreach ($columns as $idx => $colName) {
                    if (isset($alphabet[$idx])) {
                        $colMap[$alphabet[$idx]] = $colName;
                    }
                }
                foreach ($columnFormulas[$sheetNameClean] as $colIdx => $rawFormula) {
                    $colName = $columns[$colIdx];
                    $phpExpr = parseOdsFormulaToPhp($rawFormula, $colMap);
                    $stub  = "<?php\n";
                    $stub .= "/**\n";
                    $stub .= " * Auto-generated method for column '$colName' (ODS formula: $rawFormula)\n";
                    $stub .= " * This file replicates the formula in PHP.\n";
                    $stub .= " * \$row is provided by run_method.php.\n";
                    $stub .= " */\n\n";
                    $stub .= "class FormulaRunner {\n";
                    $stub .= "    public function cellVal(\$val) {\n";
                    $stub .= "        \$num = preg_replace('/[^0-9.\\-]+/', '', \$val);\n";
                    $stub .= "        return floatval(\$num);\n";
                    $stub .= "    }\n\n";
                    $stub .= "    public function sum(...\$vals) {\n";
                    $stub .= "        \$total = 0;\n";
                    $stub .= "        foreach (\$vals as \$v) { \$total += \$v; }\n";
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

