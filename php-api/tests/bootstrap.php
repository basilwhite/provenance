<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Test DB config — override via env if your local MySQL differs.
// See php-api/README.md for how to set this up.
putenv('PROVENANCE_DB_HOST=' . (getenv('PROVENANCE_TEST_DB_HOST') ?: '127.0.0.1'));
putenv('PROVENANCE_DB_PORT=' . (getenv('PROVENANCE_TEST_DB_PORT') ?: '3307'));
putenv('PROVENANCE_DB_NAME=' . (getenv('PROVENANCE_TEST_DB_NAME') ?: 'provenance_test'));
putenv('PROVENANCE_DB_USER=' . (getenv('PROVENANCE_TEST_DB_USER') ?: 'root'));
putenv('PROVENANCE_DB_PASS=' . (getenv('PROVENANCE_TEST_DB_PASS') ?: ''));
