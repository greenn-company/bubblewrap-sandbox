<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/TestCase.php';

// Fallback for environments where Composer autoload does not locate the class (e.g., optimized autoload missing).
if (!class_exists('SecureRun\\BubblewrapSandboxRunner')) {
    require_once __DIR__ . '/../src/BubblewrapSandboxRunner.php';
}
if (!class_exists('SecureRun\\Exceptions\\BubblewrapUnavailableException')) {
    require_once __DIR__ . '/../src/Exceptions/BubblewrapUnavailableException.php';
}
