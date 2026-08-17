<?php
require_once __DIR__ . '/vendor/autoload.php';
\Dotenv\Dotenv::createImmutable(__DIR__)->load();
// Prevent multiple inclusions
if (!defined('CONFIG_LOADED')) {
    define('CONFIG_LOADED', true);

    // 1. Detect Protocol (http vs https)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) 
                ? 'https://' 
                : 'http://';

    // 2. Detect Host (e.g., localhost or domain.com)
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // 3. Compute Project Subfolder (if running inside subdirectories like /localhost/my-project/)
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    $doc_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
    
    // Normalize slashes
    $doc_root = str_replace('\\', '/', $doc_root);
    $dir = str_replace('\\', '/', __DIR__);
    
    // Calculate root folder path relative to document root
    $subfolder = str_replace($doc_root, '', $dir);
    $subfolder = '/' . trim($subfolder, '/') . '/';
    if ($subfolder === '//') {
        $subfolder = '/';
    }

    // 4. Define Global Constants
    define('BASE_URL', $protocol . $host . $subfolder);
    define('ROOT_PATH', __DIR__ . '/');
}
?>