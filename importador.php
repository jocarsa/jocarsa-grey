<?php
// importador.php
session_start();
require_once 'i18n.php';  // load translations
require 'funciones/odsasqlite.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = $_POST['url'];
    $nombre = trim($_POST['nombre']);
    if(empty($url) || empty($nombre)) {
        $error = t("error_both_required");
    } else {
        // Import the ODS file
        odsasqlite($url, $nombre);
        
        // Create a config file
        $configContent = "<?php\n\$config = [\n    'db_name' => '" . addslashes($nombre) . ".db'\n];\n";
        file_put_contents("config.php", $configContent);
        
        // Open the newly created database and create the users table if not exists
        $db = new SQLite3($nombre . ".db");
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            email TEXT,
            username TEXT,
            password TEXT
        )");
        // Insert the initial user if not present
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
        
        $success = t("import_success");
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo t("importer_title"); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="header">
        <div id="corporativo">
            <img src="grey.png" alt="Logo">
            <h1><?php echo t("importer_title"); ?></h1>
        </div>
    </div>
    <div class="importador-container">
        <?php if(isset($error)): ?>
            <p class="message-error"><?php echo $error; ?></p>
        <?php endif; ?>
        <?php if(isset($success)): ?>
            <p class="message-success"><?php echo $success; ?></p>
            <p><a href="index.php" class="btn"><?php echo t("go_dashboard"); ?></a></p>
        <?php else: ?>
            <form action="importador.php" method="POST">
                <h3><?php echo t("importer_heading"); ?></h3>
                <p class="importador-description"><?php echo t("importer_description"); ?></p>

                <div class="form-group">
                    <label><?php echo t("enter_ods_url"); ?></label>
                    <input type="url" name="url" required>
                </div>
                <div class="form-group">
                    <label><?php echo t("enter_db_name"); ?></label>
                    <input type="text" name="nombre" required>
                </div>
                <input type="submit" value="Import" class="btn-submit">
            </form>
            <p class="example-url">
                Example URL: https://docs.google.com/spreadsheets/d/e/2PACX-1vR5W8_7qaMmfZvzyO-j8xEOfQC369gfOc7m--vCT5yB1jCtFbzTS3daOq3XyY0gpvuMSZM195uFEZHK/pub?output=ods
            </p>
        <?php endif; ?>
    </div>
</body>
</html>

