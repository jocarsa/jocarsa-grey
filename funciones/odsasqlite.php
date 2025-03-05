<?php
/**
 * Helper function to clean and normalize column names.
 * It converts accented characters to non-accented ones,
 * replaces non-alphanumeric characters with underscores,
 * removes extra underscores, and ensures the name doesn't start with a digit.
 */
function cleanColumnName($name) {
    // Convert accented characters to their non-accented equivalent
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    // Replace any character that is not a letter, digit, or underscore with an underscore
    $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $normalized);
    // Remove duplicate underscores
    $clean = preg_replace('/_+/', '_', $clean);
    // Trim underscores from beginning and end
    $clean = trim($clean, '_');
    // If the cleaned name starts with a digit, prepend an underscore
    if (preg_match('/^\d/', $clean)) {
        $clean = '_' . $clean;
    }
    // If the result is empty, assign a default name
    return ($clean !== '') ? $clean : 'column';
}
function odsasqlite($miurl,$nombrebasededatos){
	// 1. Download the ODS file from the URL
	$url = $miurl;
	$tempFile = tempnam(sys_get_temp_dir(), 'ods');
	file_put_contents($tempFile, file_get_contents($url));

	// 2. Extract the content.xml from the ODS archive
	$xmlPath = 'content.xml';
	$zip = new ZipArchive;
	if ($zip->open($tempFile) === TRUE) {
		 $xmlContent = $zip->getFromName($xmlPath);
		 $zip->close();
	} else {
		 die("Error opening the ODS file.");
	}

	// 3. Load the XML and retrieve namespaces
	$xml = simplexml_load_string($xmlContent);
	$namespaces = $xml->getNamespaces(true);

	// 4. Navigate to the spreadsheet element
	$office = $xml->children($namespaces['office']);
	$spreadsheet = $office->body->spreadsheet;

	// 5. Create (or open) a SQLite database (data.db in current directory)
	try {
		 $db = new PDO('sqlite:'.$nombrebasededatos.'.db');
		 $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	} catch (PDOException $e) {
		 die("SQLite Connection failed: " . $e->getMessage());
	}

	// 6. Process each sheet (each <table:table> element)
	$tables = $spreadsheet->children($namespaces['table'])->table;
	if ($tables) {
		 foreach ($tables as $table) {
		     // Get sheet name and clean it for SQLite usage
		     $tableAttributes = $table->attributes($namespaces['table']);
		     $sheetName = (string)$tableAttributes['name'];
		     $sheetNameClean = preg_replace('/[^a-zA-Z0-9_]/', '_', $sheetName);
		     if (preg_match('/^\d/', $sheetNameClean)) {
		         $sheetNameClean = '_' . $sheetNameClean;
		     }
		     
		     // Get all rows from this sheet (<table:table-row> elements)
		     $rows = $table->children($namespaces['table'])->{'table-row'};
		     if (!count($rows)) {
		         continue; // Skip sheet with no rows
		     }
		     
		     // 6a. Process header row: read each cell and only keep non-empty headers.
		     $headerRow = $rows[0];
		     $headerCells = $headerRow->children($namespaces['table'])->{'table-cell'};
		     $columns = array();      // List of cleaned column names
		     $colMapping = array();   // Maps overall cell positions to our columns array index
		     $currentPos = 0;
		     foreach ($headerCells as $cell) {
		         $cellAttributes = $cell->attributes($namespaces['table']);
		         $repeat = isset($cellAttributes['number-columns-repeated']) ? (int)$cellAttributes['number-columns-repeated'] : 1;
		         $text = $cell->children($namespaces['text'])->{'p'};
		         $cellValue = trim((string)$text);
		         for ($i = 0; $i < $repeat; $i++) {
		             if ($cellValue !== '') { // Only include non-empty header cells
		                 $colNameClean = cleanColumnName($cellValue);
		                 // Avoid duplicate column names by appending a suffix if needed
		                 $original = $colNameClean;
		                 $suffix = 1;
		                 while (in_array($colNameClean, $columns)) {
		                     $colNameClean = $original . '_' . $suffix;
		                     $suffix++;
		                 }
		                 $columns[] = $colNameClean;
		                 $colMapping[$currentPos] = count($columns) - 1;
		             }
		             $currentPos++;
		         }
		     }
		     
		     // Skip this sheet if no header columns are found
		     if (empty($columns)) {
		         continue;
		     }
		     
		     // 6b. Create the table with an auto-increment "id" column and one TEXT column per header cell
		     $createSQL = "CREATE TABLE IF NOT EXISTS \"$sheetNameClean\" (id INTEGER PRIMARY KEY AUTOINCREMENT";
		     foreach ($columns as $col) {
		         $createSQL .= ", \"$col\" TEXT";
		     }
		     $createSQL .= ")";
		     $db->exec($createSQL);
		     
		     // Prepare the INSERT statement for data rows (columns are based on non-empty headers)
		     $colList = implode(', ', array_map(function($col) {
		         return "\"$col\"";
		     }, $columns));
		     $placeholders = implode(', ', array_fill(0, count($columns), '?'));
		     $insertSQL = "INSERT INTO \"$sheetNameClean\" ($colList) VALUES ($placeholders)";
		     $stmt = $db->prepare($insertSQL);
		     
		     // 7. Process each data row (skip header row, index 0)
		     for ($i = 1; $i < count($rows); $i++) {
		         $row = $rows[$i];
		         // Build an array representing the entire row using all cell positions
		         $cells = $row->children($namespaces['table'])->{'table-cell'};
		         $rowDataAll = array();
		         $currentPos = 0;
		         foreach ($cells as $cell) {
		             $cellAttributes = $cell->attributes($namespaces['table']);
		             $repeat = isset($cellAttributes['number-columns-repeated']) ? (int)$cellAttributes['number-columns-repeated'] : 1;
		             $text = $cell->children($namespaces['text'])->{'p'};
		             $cellValue = trim((string)$text);
		             for ($j = 0; $j < $repeat; $j++) {
		                 $rowDataAll[$currentPos] = $cellValue;
		                 $currentPos++;
		             }
		         }
		         // Build filtered row data only for columns that had non-empty headers
		         $finalRow = array_fill(0, count($columns), '');
		         foreach ($colMapping as $pos => $colIndex) {
		             $finalRow[$colIndex] = isset($rowDataAll[$pos]) ? $rowDataAll[$pos] : '';
		         }
		         
		         // Skip the row if all values are empty
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
		         
		         // Insert the row into the SQLite table
		         $stmt->execute($finalRow);
		     }
		     
		     echo "Processed sheet: $sheetName\n";
		 }
	} else {
		 echo "No sheets found in the document.";
	}
	 unlink($tempFile);
}
?>
