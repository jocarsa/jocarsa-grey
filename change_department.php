<?php
session_start();
unset($_SESSION['department_id']);
header("Location: index.php");
exit;

