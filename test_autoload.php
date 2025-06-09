<?php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);

   require 'vendor/autoload.php';

   try {
       $client = new \GuzzleHttp\Client();
       echo "GuzzleHttp\Client trovato! Versione: " . $client->getDescription() . "\n";
   } catch (\Exception $e) {
       echo "Errore: " . $e->getMessage() . "\n";
   }
   ?>
