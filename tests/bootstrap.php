<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Note: We don't load the stubs file here because:
// 1. Stubs are for static analysis (PHPStan), not runtime
// 2. Loading may trigger missing dependency errors for classes WPML extends
// 3. Tests validate syntax and content via file_get_contents + php -l
