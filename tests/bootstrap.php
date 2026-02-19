<?php

/**
 * PHPUnit-Bootstrap für BS AWO Jobs.
 * Definiert ABSPATH und lädt den Plugin-Autoloader, damit Klassen ohne WordPress-Kern getestet werden können.
 */

if (! defined('ABSPATH')) {
    define('ABSPATH', true);
}

$plugin_dir = dirname(__DIR__);

spl_autoload_register(
    function ($class) use ($plugin_dir) {
        if (strpos($class, 'BsAwoJobs\\') !== 0) {
            return;
        }
        $relative   = substr($class, strlen('BsAwoJobs\\'));
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
        $file       = $plugin_dir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relativePath;

        if (file_exists($file)) {
            require_once $file;
        }
    }
);
