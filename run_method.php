<?php
// run_method.php

// Security note: In a real app, ensure you sanitize/validate these GET params
$table  = $_GET['table']  ?? '';
$column = $_GET['column'] ?? '';
$id     = $_GET['id']     ?? '';

$path = __DIR__ . "/metodos/$table/$column.php";
if (!file_exists($path)) {
    die("Method file not found for $table / $column");
}

// You might want to fetch the row from the DB to pass into the method:
require 'config.php'; // so we know $config['db_name']
$db = new SQLite3($config['db_name']);
$sql    = "SELECT * FROM \"$table\" WHERE id = :id LIMIT 1";
$stmt   = $db->prepare($sql);
$stmt->bindValue(':id', $id, SQLITE3_TEXT);
$result = $stmt->execute();
$row    = $result->fetchArray(SQLITE3_ASSOC);

// Potentially pass $row to the method file. For demonstration, let's do:
echo "<h3>Running method for Table: $table, Column: $column, ID: $id</h3>";
echo "<p>Row data:</p><pre>";
print_r($row);
echo "</pre>";

// Now let's include the method file. 
// That file might expect a $row variable, or do something else.
echo "<hr>";
echo "<h4>Method Output:</h4>";
include $path;

?>

