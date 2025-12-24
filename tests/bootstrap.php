<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Load dependency stubs (provide classes WPML extends)
require_once __DIR__ . '/../vendor/php-stubs/wordpress-stubs/wordpress-stubs.php';
require_once __DIR__ . '/../vendor/php-stubs/wp-cli-stubs/wp-cli-stubs.php';
require_once __DIR__ . '/../vendor/php-stubs/woocommerce-stubs/woocommerce-stubs.php';

// Note: We don't load wpml-stubs.php here because:
// 1. Stubs are for static analysis, not runtime
// 2. Tests validate syntax and content via file_get_contents + php -l
// 3. Avoids executing stray code that might remain in stubs
