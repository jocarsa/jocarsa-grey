<?php
// importador.php
session_start();
require_once 'i18n.php';  // load translations
require 'funciones/odsasqlite.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_step']) && $_POST['import_step'] === '1') {
    // ------------------- STEP 1: PROCESS ODS IMPORT -------------------
    $url = $_POST['url'];
    $nombre = trim($_POST['nombre']);
    if (empty($url) || empty($nombre)) {
        $error = t("error_both_required");
    } else {
        // 1) Import the ODS file
        odsasqlite($url, $nombre);

        // 2) Create a config file
        $configContent = "<?php\n\$config = [\n    'db_name' => '" . addslashes($nombre) . ".db'\n];\n";
        file_put_contents("config.php", $configContent);

        // 3) Create initial 'users' table and seed a default user
        $db = new SQLite3($nombre . ".db");
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            email TEXT,
            username TEXT,
            password TEXT
        )");
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

        // 4) Create DEPARTMENTS table + DEPARTMENT_TABLES relationship table
        $db->exec("CREATE TABLE IF NOT EXISTS departments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS department_tables (
            department_id INTEGER,
            table_name TEXT
        )");

        // Move to step 2 of the installer (department creation)
        $_SESSION['installer_dbname'] = $nombre . ".db";
        header("Location: importador.php?step=2");
        exit;
    }
}

// ------------------- STEP 2: CREATE DEPARTMENTS & LINK TABLES -------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_step']) && $_POST['import_step'] === '2') {
    $dbName = $_SESSION['installer_dbname'] ?? null;
    if (!$dbName) {
        header("Location: importador.php");
        exit;
    }
    $db = new SQLite3($dbName);

    // 1) Insert the new department
    $departmentName = trim($_POST['department_name']);
    if (!empty($departmentName)) {
        $stmt = $db->prepare("INSERT INTO departments (name) VALUES (:name)");
        $stmt->bindValue(':name', $departmentName, SQLITE3_TEXT);
        $stmt->execute();
        $departmentId = $db->lastInsertRowID();

        // 2) Insert checkboxes for the selected tables
        if (isset($_POST['tables']) && is_array($_POST['tables'])) {
            foreach ($_POST['tables'] as $tableName) {
                $stmt2 = $db->prepare("INSERT INTO department_tables (department_id, table_name) VALUES (:depId, :tbl)");
                $stmt2->bindValue(':depId', $departmentId, SQLITE3_INTEGER);
                $stmt2->bindValue(':tbl', $tableName, SQLITE3_TEXT);
                $stmt2->execute();
            }
        }
        $success = t("Department created successfully!");
    }
}

// Now show the form based on ?step=2 or the result
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

<?php if (!isset($_GET['step'])): ?>
    <!-- ================= STEP 1: ODS IMPORT FORM ================== -->
    <div class="importador-container">
        <?php if(isset($error)): ?>
            <p class="message-error"><?php echo $error; ?></p>
        <?php endif; ?>
        <form action="importador.php" method="POST">
            <input type="hidden" name="import_step" value="1">
            <h3><?php echo t("importer_heading"); ?></h3>
            <p class="importador-description">
                <?php 
                    // Inform user about extended foreign key support
                    echo t("importer_description") . " " . "Note: Foreign keys now support multi-column display.";
                ?>
            </p>
            <div class="form-group">
                <label><?php echo t("enter_ods_url"); ?></label>
                <input type="url" name="url" required>
            </div>
            <div class="form-group">
                <label><?php echo t("enter_db_name"); ?></label>
                <input type="text" name="nombre" required>
            </div>
            <input type="submit" value="Import" class="btn-submit">
            <p class="example-url">
                Example URL: https://docs.google.com/spreadsheets/...
            </p>
        </form>
    </div>

<?php elseif ($_GET['step'] == 2): ?>
    <!-- ============= STEP 2: DEPARTMENTS CREATION FORM ============= -->
    <?php
    $dbName = $_SESSION['installer_dbname'] ?? null;
    if ($dbName) {
        $db = new SQLite3($dbName);

        // Get a list of the tables from the DB
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $tables = [];
        while($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Exclude 'departments', 'department_tables', and 'users' from the checkboxes:
            if (!in_array($row['name'], ['departments','department_tables','users'])) {
                $tables[] = $row['name'];
            }
        }
    }
    ?>
    <div class="importador-container">
        <?php if(isset($success)): ?>
            <p class="message-success"><?php echo $success; ?></p>
        <?php endif; ?>
        <h3>Create a Department</h3>
        <form action="importador.php?step=2" method="POST">
            <input type="hidden" name="import_step" value="2">
            <div class="form-group">
                <label>Department Name</label>
                <input type="text" name="department_name" required>
            </div>
            <div class="form-group">
                <label>Select the tables that belong to this Department:</label><br>
                <?php foreach ($tables as $tbl): ?>
                    <input type="checkbox" name="tables[]" value="<?php echo htmlspecialchars($tbl); ?>"> 
                    <?php echo htmlspecialchars($tbl); ?><br>
                <?php endforeach; ?>
            </div>
            <input type="submit" value="Add Department" class="btn-submit">
        </form>

        <hr>
        <p>When you’re done creating departments, <a href="index.php" class="btn">Go to Dashboard</a></p>
    </div>
<?php endif; ?>
</body>
</html>

