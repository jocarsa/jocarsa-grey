<?php
session_start();

// If config.php doesn't exist, redirect to importer
if (!file_exists('config.php')) {
    header("Location: importador.php");
    exit;
}
include 'config.php';

// Handle logout if requested
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// If not logged in, process login submission and/or show login form
if (!isset($_SESSION['loggedin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $db = new SQLite3($config['db_name']);
        // NOTE: For a production system, use password hashing!
        $stmt = $db->prepare("SELECT * FROM users WHERE username = :username AND password = :password");
        $stmt->bindValue(':username', $_POST['username'], SQLITE3_TEXT);
        $stmt->bindValue(':password', $_POST['password'], SQLITE3_TEXT);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);
        if ($user) {
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $user['username'];
        } else {
            $login_error = "Invalid username or password.";
        }
    }
    if (!isset($_SESSION['loggedin'])) {
        // Show login form and exit
        ?>
        <!doctype html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Login - jocarsa | grey</title>
            <link rel="stylesheet" href="style.css">
        </head>
        <body>
            <div class="header">
                <div id="corporativo">
                    <img src="grey.png" alt="Logo">
                    <h1>jocarsa | grey - Login</h1>
                </div>
            </div>
            <div class="container">
                <div class="main" style="width:100%;">
                    <h2>Login</h2>
                    <?php if(isset($login_error)): ?>
                        <p class="message" style="color:red;"><?php echo $login_error; ?></p>
                    <?php endif; ?>
                    <form method="post" action="index.php">
                        <label>Username:</label>
                        <input type="text" name="username" required>
                        <label>Password:</label>
                        <input type="password" name="password" required>
                        <input type="submit" name="login" value="Login">
                    </form>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Now the user is logged in. Connect to the SQLite database.
$db = new SQLite3($config['db_name']);

// Retrieve all table names from the SQLite database (ignoring SQLite internal tables)
$result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
$tables = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $tables[] = $row['name'];
}

// Get selected table from URL parameter (if any)
$selected_table = isset($_GET['table']) ? $_GET['table'] : null;

// Determine action: 'list' (default), 'create', or 'edit'
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$crud_message = '';

// Verify that the selected table exists
if ($selected_table && !in_array($selected_table, $tables)) {
    die("Invalid table selected.");
}

// Process CRUD actions if a table is selected
if ($selected_table && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // CREATE new record
    if (isset($_POST['create'])) {
        $colsQuery = $db->query("PRAGMA table_info('$selected_table')");
        $fields = [];
        $values = [];
        while ($col = $colsQuery->fetchArray(SQLITE3_ASSOC)) {
            if ($col['name'] == 'id') continue;
            if (isset($_POST[$col['name']])) {
                $fields[] = $col['name'];
                $values[] = "'" . SQLite3::escapeString($_POST[$col['name']]) . "'";
            }
        }
        if ($fields) {
            $sql = "INSERT INTO $selected_table (" . implode(',', $fields) . ") VALUES (" . implode(',', $values) . ")";
            $db->exec($sql);
            $crud_message = "Record created.";
            $action = 'list';
        }
    }
    // UPDATE record
    elseif (isset($_POST['update'])) {
        $id = $_POST['id'];
        $colsQuery = $db->query("PRAGMA table_info('$selected_table')");
        $setParts = [];
        while ($col = $colsQuery->fetchArray(SQLITE3_ASSOC)) {
            if ($col['name'] == 'id') continue;
            if (isset($_POST[$col['name']])) {
                $setParts[] = $col['name'] . " = '" . SQLite3::escapeString($_POST[$col['name']]) . "'";
            }
        }
        if ($setParts) {
            $sql = "UPDATE $selected_table SET " . implode(',', $setParts) . " WHERE id = '" . SQLite3::escapeString($id) . "'";
            $db->exec($sql);
            $crud_message = "Record updated.";
            $action = 'list';
        }
    }
    // DELETE record
    elseif (isset($_POST['delete'])) {
        $id = $_POST['id'];
        $sql = "DELETE FROM $selected_table WHERE id = '" . SQLite3::escapeString($id) . "'";
        $db->exec($sql);
        $crud_message = "Record deleted.";
        $action = 'list';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Empresa Admin Panel - jocarsa | grey</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="header">
        <div id="corporativo">
            <img src="grey.png" alt="Logo">
            <h1>jocarsa | grey - Dashboard</h1>
        </div>
        <div style="position:absolute; top:15px; right:20px;">
            Logged in as <?php echo htmlspecialchars($_SESSION['username']); ?> | <a href="?action=logout">Logout</a>
        </div>
    </div>
    <div class="container">
        <div class="nav">
            <h3>Tables</h3>
            <?php foreach ($tables as $table): ?>
                <a href="?table=<?php echo urlencode($table); ?>" class="<?php echo ($selected_table === $table) ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($table); ?>
                </a>
            <?php endforeach; ?>
            <hr>
            <!-- Relaunch importer action -->
            <a href="importador.php" class="btn">Re-launch Importer</a>
        </div>
        <div class="main">
            <?php if ($selected_table): ?>
                <h2><?php echo htmlspecialchars($selected_table); ?> Table</h2>
                <?php if ($crud_message): ?>
                    <p class="message"><?php echo $crud_message; ?></p>
                <?php endif; ?>

                <?php
                    // Get column info for the selected table
                    $colsQuery = $db->query("PRAGMA table_info('$selected_table')");
                    $columns = [];
                    while ($col = $colsQuery->fetchArray(SQLITE3_ASSOC)) {
                        $columns[] = $col;
                    }
                ?>

                <?php if ($action == 'create'): ?>
                    <h3>Create New Record</h3>
                    <form method="post">
                        <?php foreach ($columns as $col): 
                            if ($col['name'] == 'id') continue;
                        ?>
                            <label><?php echo htmlspecialchars($col['name']); ?>:</label>
                            <input type="text" name="<?php echo htmlspecialchars($col['name']); ?>">
                        <?php endforeach; ?>
                        <input type="submit" name="create" value="Create">
                        <a href="?table=<?php echo urlencode($selected_table); ?>&action=list" class="btn">Cancel</a>
                    </form>
                <?php elseif ($action == 'edit' && isset($_GET['id'])): 
                        $id = $_GET['id'];
                        $stmt = $db->prepare("SELECT * FROM $selected_table WHERE id = :id");
                        $stmt->bindValue(':id', $id, SQLITE3_TEXT);
                        $record = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                        if ($record):
                ?>
                    <h3>Edit Record</h3>
                    <form method="post">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($record['id']); ?>">
                        <?php foreach ($columns as $col): 
                            if ($col['name'] == 'id') continue;
                        ?>
                            <label><?php echo htmlspecialchars($col['name']); ?>:</label>
                            <input type="text" name="<?php echo htmlspecialchars($col['name']); ?>" value="<?php echo htmlspecialchars($record[$col['name']]); ?>">
                        <?php endforeach; ?>
                        <input type="submit" name="update" value="Update">
                        <a href="?table=<?php echo urlencode($selected_table); ?>&action=list" class="btn">Cancel</a>
                    </form>
                <?php 
                        endif;
                    else:
                ?>
                    <div class="actions">
                        <a href="?table=<?php echo urlencode($selected_table); ?>&action=create" class="btn">Create New Record</a>
                    </div>
                    <h3>Records</h3>
                    <table>
                        <tr>
                            <?php foreach ($columns as $col): ?>
                                <th><?php echo htmlspecialchars($col['name']); ?></th>
                            <?php endforeach; ?>
                            <th>Actions</th>
                        </tr>
                        <?php
                            $records = $db->query("SELECT * FROM $selected_table");
                            while ($row = $records->fetchArray(SQLITE3_ASSOC)) {
                                echo "<tr>";
                                foreach ($columns as $col) {
                                    $colname = $col['name'];
                                    echo "<td>" . htmlspecialchars($row[$colname]) . "</td>";
                                }
                                echo "<td>
                                        <a href='?table=$selected_table&action=edit&id=" . urlencode($row['id']) . "'>Edit</a> | 
                                        <form method='post' class='inline' onsubmit='return confirm(\"Delete record?\")'>
                                            <input type='hidden' name='id' value='" . htmlspecialchars($row['id']) . "'>
                                            <input type='submit' name='delete' value='Delete'>
                                        </form>
                                      </td>";
                                echo "</tr>";
                            }
                        ?>
                    </table>
                <?php endif; ?>
            <?php else: ?>
                <p>Select a table from the left to manage its records.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

