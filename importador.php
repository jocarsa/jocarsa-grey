<?php
// importador.php
require 'funciones/odsasqlite.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = $_POST['url'];
    $nombre = trim($_POST['nombre']);
    if(empty($url) || empty($nombre)) {
        $error = "Both URL and Database Name are required.";
    } else {
        // Import the ODS file into a new SQLite database named "$nombre.db"
        odsasqlite($url, $nombre);
        
        // Create a configuration file with the database name
        $configContent = "<?php\n\$config = [\n    'db_name' => '" . addslashes($nombre) . ".db'\n];\n";
        file_put_contents("config.php", $configContent);
        
        // Open the newly created database and create the users table if it does not exist
        $db = new SQLite3($nombre . ".db");
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            email TEXT,
            username TEXT,
            password TEXT
        )");
        // Insert the initial user if not already present
        $check = $db->query("SELECT COUNT(*) as count FROM users WHERE username = 'jocarsa'");
        $row = $check->fetchArray(SQLITE3_ASSOC);
        if ($row['count'] == 0) {
            $db->exec("INSERT INTO users (name, email, username, password) VALUES (
                'Jose Vicente Carratala',
                'info@josevicentecarratala.com',
                'jocarsa',
                'jocarsa'
            )");
        }
        
        $success = "Import process completed. Config file created.";
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Importador - jocarsa | grey</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="header">
        <div id="corporativo">
            <img src="grey.png" alt="Logo">
            <h1>jocarsa | grey - Importador</h1>
        </div>
    </div>
    <div class="container">
        <div class="main" style="width:100%;">
            <?php if(isset($error)): ?>
                <p class="message" style="color:red;"><?php echo $error; ?></p>
            <?php endif; ?>
            <?php if(isset($success)): ?>
                <p class="message"><?php echo $success; ?></p>
                <p><a href="index.php" class="btn">Go to Dashboard</a></p>
            <?php else: ?>
                <form action="importador.php" method="POST">
                    <h3>jocarsa | grey Importador</h3>
                    <p>Import data from a Google Sheets ODS file into the application</p>
                    <p>Enter the URL of the Google Sheets ODS file:</p>
                    <input type="url" name="url" required>
                    <p>Enter the name for the new SQLite database:</p>
                    <input type="text" name="nombre" required>
                    <input type="submit" value="Import">
                </form>
                <p>Example URL: https://docs.google.com/spreadsheets/d/e/2PACX-1vR5W8_7qaMmfZvzyO-j8xEOfQC369gfOc7m--vCT5yB1jCtFbzTS3daOq3XyY0gpvuMSZM195uFEZHK/pub?output=ods</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

