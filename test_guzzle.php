<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the Composer autoloader
require 'vendor/autoload.php';

try {
    $client = new \GuzzleHttp\Client();
    $response = $client->get('https://www.example.com');
    echo "GuzzleHttp\Client trovato con successo! ";
    echo "Status Code: " . $response->getStatusCode() . "\n";
} catch (\Exception $e) {
    echo "Errore: " . $e->getMessage() . "\n";
}
?>
