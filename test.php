<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include Composer's autoload file
require 'vendor/autoload.php';

// Verifica versione di PHP
echo "PHP version: " . phpversion() . "\n";

// Verifica autoloading
echo "\nAutoload paths:\n";
$autoloadPaths = explode(PATH_SEPARATOR, get_include_path());
foreach ($autoloadPaths as $path) {
    echo "$path\n";
}
?>
