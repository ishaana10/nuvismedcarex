<?php
/**
 * Application Versioning & Release Metadata Configuration
 */
require_once __DIR__ . '/../core/AppVersion.php';

define('APP_NAME', AppVersion::get('name') ?: 'Nuvis Medicoz');
define('APP_VERSION', AppVersion::get('full'));
define('APP_RELEASE_DATE', AppVersion::get('released_at'));
define('APP_BUILD_NAME', 'Build ' . AppVersion::get('build') . ' (' . AppVersion::get('commit') . ')');
