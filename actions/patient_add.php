<?php
/**
 * Patient Registration Action Handler (Thin Bootstrap)
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

requireAuth();
validateCsrfRequest();

$controller = new \ClinicFlow\Http\Controllers\PatientController();
$controller->register();
