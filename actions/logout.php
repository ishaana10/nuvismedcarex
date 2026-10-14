<?php
/**
 * ClinicFlow Logout Action Handler
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
session_unset();
session_destroy();

require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
setToast("Logged Out", "You have been logged out securely.");

header("Location: ../login.php");
exit;
