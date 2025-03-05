<?php
session_start();
require_once 'i18n.php';

// If config does not exist, redirect to the importer
if (!file_exists('config.php')) {
    header("Location: importador.php");
    exit;
}
require 'config.php';

// Handle language selection
if (isset($_POST['lang']) && !empty($_POST['lang'])) {
    $_SESSION['lang'] = $_POST['lang'];
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// 1) Handle login if not already logged in
if (!isset($_SESSION['loggedin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $db = new SQLite3($config['db_name']);
        $stmt = $db->prepare("SELECT * FROM users WHERE username = :username AND password = :password");
        $stmt->bindValue(':username', $_POST['username'], SQLITE3_TEXT);
        $stmt->bindValue(':password', $_POST['password'], SQLITE3_TEXT);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);
        if ($user) {
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $user['username'];
        } else {
            $login_error = t("invalid_credentials");
        }
    }
    if (!isset($_SESSION['loggedin'])) {
        // Show the login form and exit
        ?>
        <!doctype html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php echo t("login_title"); ?></title>
            <link rel="stylesheet" href="style.css">
        </head>
        <body>
            <div class="header">
                <div id="corporativo">
                    <img src="grey.png" alt="Logo">
                    <h1>jocarsa | grey - <?php echo t("login_title"); ?></h1>
                </div>
            </div>
            <div class="container contenedorlogin">
                <div class="main" style="width:100%;">
                    <h2><?php echo t("login_title"); ?></h2>
                    <?php if(isset($login_error)): ?>
                        <p class="message" style="color:red;"><?php echo $login_error; ?></p>
                    <?php endif; ?>
                    <form method="post" action="index.php">
                        <label><?php echo t("username"); ?>:</label>
                        <input type="text" name="username" required>
                        
                        <label><?php echo t("password"); ?>:</label>
                        <input type="password" name="password" required>
                        
                        <label>Language:</label>
                        <select name="lang">
                            <option value="en">English</option>
                            <option value="es">Español</option>
                            <option value="fr">Français</option>
                            <option value="de">Deutsch</option>
                            <option value="it">Italiano</option>
                            <option value="ja">日本語</option>
                            <option value="ko">한국어</option>
                            <option value="zh">中文</option>
                        </select>
                        
                        <input type="submit" name="login" value="<?php echo t("login_button"); ?>">
                    </form>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// 2) If we reach here, user is logged in
$db = new SQLite3($config['db_name']);

// 3) Department selection: if department not chosen yet, show “pre-dashboard”
if (!isset($_SESSION['department_id']) && !isset($_POST['department_id'])) {
    // Fetch all departments
    $res = $db->query("SELECT id, name FROM departments");
    $departments = [];
    while($dRow = $res->fetchArray(SQLITE3_ASSOC)) {
        $departments[] = $dRow;
    }

    // Determine how many columns to use
    $deptCount = count($departments);
    $columns = 3; // default
    if ($deptCount == 1) {
        $columns = 1;
    } elseif ($deptCount == 2) {
        $columns = 2;
    } elseif ($deptCount == 3) {
        $columns = 3;
    } elseif ($deptCount == 4) {
        // 2 columns, so 2×2
        $columns = 2;
    } elseif ($deptCount == 5) {
        // 3 columns (the 5th will wrap to next row)
        $columns = 3;
    } elseif ($deptCount == 6) {
        // 3 columns, 2 rows (3×2)
        $columns = 3;
    } else {
        // For more than 6, also do 3 columns
        $columns = 3;
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Select Department</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
    <div class="header">
        <div id="corporativo">
            <img src="grey.png" alt="Logo">
            <h1>Select a Department</h1>
        </div>
    </div>
    
    <!-- 
       We apply a dynamic inline style to define the grid-template-columns 
       according to $columns.
    -->
    <div class="department-grid-container" 
         style="grid-template-columns: repeat(<?php echo $columns; ?>, 1fr);">
        <?php foreach ($departments as $dep): ?>
            <div class="department-grid-item">
                <form method="POST" action="index.php">
                    <input type="hidden" name="department_id" value="<?php echo $dep['id']; ?>">
                    <button type="submit" class="department-button">
                    <div class="letra"><?php echo htmlspecialchars($dep['name'])[0]; ?></div>
                        <?php echo htmlspecialchars($dep['name']); ?>
                    </button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// 4) If user submitted a department choice, store in session
if (isset($_POST['department_id'])) {
    $_SESSION['department_id'] = $_POST['department_id'];
}
$department_id = $_SESSION['department_id'] ?? null;

// 5) Retrieve only the tables that belong to the chosen department
$tables = [];
if ($department_id) {
    $stmtDept = $db->prepare("
        SELECT table_name
        FROM department_tables
        WHERE department_id = :depid
    ");
    $stmtDept->bindValue(':depid', $department_id, SQLITE3_INTEGER);
    $resultDept = $stmtDept->execute();
    while($rowDept = $resultDept->fetchArray(SQLITE3_ASSOC)) {
        $tables[] = $rowDept['table_name'];
    }
}

// 6) Helper functions for foreign keys
function isForeignKey($colName) {
    return (substr_count($colName, '_') >= 1);
}
function parseForeignKey($colName) {
    $parts = explode('_', $colName);
    $referencedTable = array_shift($parts);
    $displayColumns = $parts;
    return [$referencedTable, $displayColumns];
}

// 7) Determine if user selected a table from side menu
$selected_table = isset($_GET['table']) ? $_GET['table'] : null;
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$crud_message = '';

// Security check: if table is not in $tables, deny
if ($selected_table && !in_array($selected_table, $tables)) {
    die("Invalid or unauthorized table selected.");
}

// 8) Handle CRUD if a valid table is selected
if ($selected_table && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create'])) {
        // Insert a record
        $colsQuery = $db->query("PRAGMA table_info('$selected_table')");
        $fields = [];
        $values = [];
        while ($col = $colsQuery->fetchArray(SQLITE3_ASSOC)) {
            if ($col['name'] === 'id') continue;
            if (isset($_POST[$col['name']])) {
                $fields[] = $col['name'];
                $values[] = "'" . SQLite3::escapeString($_POST[$col['name']]) . "'";
            }
        }
        if (!empty($fields)) {
            $sql = "INSERT INTO \"$selected_table\" (" . implode(',', $fields) . ") VALUES (" . implode(',', $values) . ")";
            $db->exec($sql);
            $crud_message = "Record created.";
            $action = 'list';
        }
    } elseif (isset($_POST['update'])) {
        // Update a record
        $id = $_POST['id'];
        $colsQuery = $db->query("PRAGMA table_info('$selected_table')");
        $setParts = [];
        while ($col = $colsQuery->fetchArray(SQLITE3_ASSOC)) {
            if ($col['name'] === 'id') continue;
            if (isset($_POST[$col['name']])) {
                $setParts[] = $col['name'] . "='" . SQLite3::escapeString($_POST[$col['name']]) . "'";
            }
        }
        if (!empty($setParts)) {
            $sql = "UPDATE \"$selected_table\" SET " . implode(',', $setParts) . " WHERE id='" . SQLite3::escapeString($id) . "'";
            $db->exec($sql);
            $crud_message = "Record updated.";
            $action = 'list';
        }
    } elseif (isset($_POST['delete'])) {
        // Delete a record
        $id = $_POST['id'];
        $sql = "DELETE FROM \"$selected_table\" WHERE id='" . SQLite3::escapeString($id) . "'";
        $db->exec($sql);
        $crud_message = "Record deleted.";
        $action = 'list';
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo t("dashboard_title"); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- Header -->
<div class="header">
    <div id="corporativo">
        <img src="grey.png" alt="Logo">
        <h1><?php echo t("dashboard_title"); ?></h1>
    </div>
    <!-- Top-right: user info and logout -->
    <div style="position:absolute; top:15px; right:20px; font-size:10px;">
        <?php echo t("hello"); ?>, <?php echo htmlspecialchars($_SESSION['username']); ?>
        <a href="?action=logout" class="boton"><?php echo t("logout"); ?></a>
    </div>
</div>

<div class="container">

    <!-- Left nav: show only the tables from the chosen department -->
    <div class="nav">
        <h3><?php echo t("tables"); ?></h3>
        <?php foreach ($tables as $table): ?>
            <a href="?table=<?php echo urlencode($table); ?>"
               class="<?php echo ($selected_table === $table) ? 'active' : ''; ?>">
               <?php echo htmlspecialchars($table); ?>
            </a>
        <?php endforeach; ?>
        <hr>
        
        <!-- Optional: Let user “change department” by hitting a small script -->
        <form action="change_department.php" method="post">
            <input type="submit" class="btn boton" value="Change Department">
        </form>
        
        <!-- Relaunch importer link -->
        <a href="importador.php" class="btn boton"><?php echo t("relaunch_importer"); ?></a>
    </div>

    <!-- Main content area -->
    <div class="main">
        <?php if ($selected_table): ?>
            <h2><?php echo htmlspecialchars($selected_table); ?> Table</h2>
            
            <?php if ($crud_message): ?>
                <p class="message"><?php echo $crud_message; ?></p>
            <?php endif; ?>
            
            <?php
            // Get column metadata
            $colsQuery = $db->query("PRAGMA table_info('$selected_table')");
            $columns = [];
            while ($col = $colsQuery->fetchArray(SQLITE3_ASSOC)) {
                $columns[] = $col;
            }
            ?>

            <!-- CREATE -->
            <?php if ($action === 'create'): ?>
                <h3><?php echo t("create_new_record"); ?></h3>
                <form method="post">
                    <?php foreach ($columns as $col):
                        if ($col['name'] === 'id') continue;
                        $colName = $col['name'];
                        
                        if (isForeignKey($colName)) {
                            list($refTable, $displayCols) = parseForeignKey($colName);
                            ?>
                            <label><?php echo htmlspecialchars($colName); ?>:</label>
                            <select name="<?php echo htmlspecialchars($colName); ?>">
                                <option value="">-- Select --</option>
                                <?php
                                // Build a query to fetch ID + display columns from the referenced table
                                $fkSql = "SELECT id, " 
                                       . implode(", ", array_map(fn($c) => "\"$c\"", $displayCols))
                                       . " FROM \"$refTable\"";
                                $resultFK = $db->query($fkSql);
                                while ($rowFK = $resultFK->fetchArray(SQLITE3_ASSOC)) {
                                    $tmp = $rowFK;
                                    unset($tmp['id']);
                                    $displayStr = implode(" ", $tmp);
                                    echo "<option value='" . htmlspecialchars($rowFK['id']) . "'>"
                                         . htmlspecialchars($displayStr)
                                         . "</option>";
                                }
                                ?>
                            </select>
                        <?php 
                        } else { 
                        ?>
                            <label><?php echo htmlspecialchars($colName); ?>:</label>
                            <input type="text" name="<?php echo htmlspecialchars($colName); ?>">
                        <?php } ?>
                    <?php endforeach; ?>
                    <input type="submit" name="create" value="<?php echo t("create_new_record"); ?>">
                    <a href="?table=<?php echo urlencode($selected_table); ?>&action=list" class="btn">
                        <?php echo t("cancel"); ?>
                    </a>
                </form>

            <!-- EDIT -->
            <?php elseif ($action === 'edit' && isset($_GET['id'])): 
                $id = $_GET['id'];
                $stmt = $db->prepare("SELECT * FROM \"$selected_table\" WHERE id = :id LIMIT 1");
                $stmt->bindValue(':id', $id, SQLITE3_TEXT);
                $record = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                if ($record):
            ?>
                    <h3><?php echo t("edit_record"); ?></h3>
                    <form method="post">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($record['id']); ?>">
                        
                        <?php foreach ($columns as $col):
                            if ($col['name'] === 'id') continue;
                            $colName = $col['name'];
                            
                            if (isForeignKey($colName)) {
                                list($refTable, $displayCols) = parseForeignKey($colName);
                                ?>
                                <label><?php echo htmlspecialchars($colName); ?>:</label>
                                <select name="<?php echo htmlspecialchars($colName); ?>">
                                    <option value="">-- Select --</option>
                                    <?php
                                    $fkSql = "SELECT id, " 
                                            . implode(", ", array_map(fn($c) => "\"$c\"", $displayCols))
                                            . " FROM \"$refTable\"";
                                    $resultFK = $db->query($fkSql);
                                    while ($rowFK = $resultFK->fetchArray(SQLITE3_ASSOC)) {
                                        $tmp = $rowFK; 
                                        unset($tmp['id']);
                                        $displayStr = implode(" ", $tmp);
                                        $selected = ($record[$colName] == $rowFK['id']) ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($rowFK['id']) . "' $selected>"
                                             . htmlspecialchars($displayStr)
                                             . "</option>";
                                    }
                                    ?>
                                </select>
                            <?php 
                            } else { 
                            ?>
                                <label><?php echo htmlspecialchars($colName); ?>:</label>
                                <input type="text" 
                                       name="<?php echo htmlspecialchars($colName); ?>" 
                                       value="<?php echo htmlspecialchars($record[$colName]); ?>">
                            <?php } ?>
                        <?php endforeach; ?>
                        
                        <input type="submit" name="update" value="<?php echo t("edit_record"); ?>">
                        <a href="?table=<?php echo urlencode($selected_table); ?>&action=list" class="btn">
                            <?php echo t("cancel"); ?>
                        </a>
                    </form>
            <?php 
                endif; // record check
            ?>

            <!-- LIST ALL RECORDS -->
            <?php else: ?>
                <div class="actions">
                    <a href="?table=<?php echo urlencode($selected_table); ?>&action=create" class="btn">
                        <?php echo t("create_new_record"); ?>
                    </a>
                </div>
                <h3><?php echo t("records"); ?></h3>
                
                <table>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <th><?php echo htmlspecialchars($col['name']); ?></th>
                        <?php endforeach; ?>
                        <th>Actions</th>
                        <th>Métodos</th>
                    </tr>
                    <?php
                    $records = $db->query("SELECT * FROM \"$selected_table\"");
                    while ($rowData = $records->fetchArray(SQLITE3_ASSOC)) {
                        echo "<tr>";
                        foreach ($columns as $col) {
                            $colName = $col['name'];
                            if (isForeignKey($colName)) {
                                list($refTable, $displayCols) = parseForeignKey($colName);
                                $stmtFK = $db->prepare("
                                    SELECT " 
                                    . implode(", ", array_map(fn($c) => "\"$c\"", $displayCols))
                                    . " FROM \"$refTable\" WHERE id = :fkid
                                ");
                                $stmtFK->bindValue(':fkid', $rowData[$colName], SQLITE3_TEXT);
                                $resultFK = $stmtFK->execute()->fetchArray(SQLITE3_ASSOC);
                                $displayText = $resultFK ? implode(" ", $resultFK) : $rowData[$colName];
                                echo "<td>" . htmlspecialchars($displayText) . "</td>";
                            } else {
                                echo "<td>" . htmlspecialchars($rowData[$colName]) . "</td>";
                            }
                        }
                        echo "<td>";
                        echo "<a href='?table=$selected_table&action=edit&id={$rowData['id']}' class='boton'>Edit</a>";
                        ?>
                        <form method="post" class="inline" 
                              onsubmit="return confirm('<?php echo t("delete_record"); ?>');" 
                              style="display:inline;">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($rowData['id']); ?>">
                            <input type="submit" name="delete" value="Delete">
                        </form>
                        <?php
                        echo "</td>";
                        
                        // Métodos
                        echo "<td>";
                        foreach ($columns as $col) {
                            $colName = $col['name'];
                            $methodFile = "metodos/$selected_table/$colName.php";
                            if (file_exists($methodFile)) {
                                echo "<a class='boton' href='run_method.php?table=$selected_table&column=$colName&id={$rowData['id']}' target='_blank'>$colName</a><br>";
                            }
                        }
                        echo "</td>";
                        echo "</tr>";
                    }
                    ?>
                </table>
            <?php endif; ?>
        <?php else: ?>
            <p><?php echo t("select_table"); ?></p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>

