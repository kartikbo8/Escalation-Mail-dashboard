<?php
require_once __DIR__ . '/../includes/auth.php';
doLogout();
header('Location: login.php');
exit;
