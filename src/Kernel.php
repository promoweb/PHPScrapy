<?php
   namespace phpscrapy;

   use phpscrapy\Pipeline\BookPipeline;
   use phpscrapy\Spider\BookSpider;

   class Kernel {
       private $spider;
       private $pipeline;

       public function __construct() {
           $this->registerAutoloader();
       }

       protected function registerAutoloader() {
           require 'vendor/autoload.php';
       }

       public function createSpider(BookPipeline $pipeline): BookSpider {
           $spider = new BookSpider('http://example.com/books');
           $spider->setPipeline($pipeline);
           return $spider;
       }
   }
   ?>
