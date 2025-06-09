<?php
namespace phpscrapy\Spider;

use phpscrapy\Pipeline\BookPipeline;
use DOMDocument;
use DOMXPath;

class BookSpider {
    private $url;
    private $curlHandle;
    private $pipeline;

    public function __construct(string $url) {
        $this->url = $url;
        $this->curlHandle = curl_init();
        $this->pipeline = new BookPipeline(); // Inizializza con una pipeline di default
    }

    public function start() {
        $html = $this->fetchContent();
        $this->parseContent($html);
    }

    private function fetchContent(): string {
        curl_setopt($this->curlHandle, CURLOPT_URL, $this->url);
        curl_setopt($this->curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curlHandle, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($this->curlHandle);
        $error = curl_error($this->curlHandle);
        
        if ($error) {
            throw new \RuntimeException("Errore CURL: $error");
        }
        
        return $response;
    }

    private function parseContent(string $html) {
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        
        $xpath = new DOMXPath($dom);
        
        // Ricerca tutti i elementi con classe "book-item"
        $nodes = $xpath->query('//div[contains(@class, "book-item")]');
        
        foreach ($nodes as $node) {
            // Ricerca del titolo all'interno dell'elemento h3
            $titleNodes = $xpath->query('descendant::h3', $node);
            if ($titleNodes->length > 0) {
                $title = $titleNodes->item(0)->textContent;
            } else {
                $title = '';
            }
            
            // Ricerca del prezzo con classe "price"
            $priceNodes = $xpath->query('descendant::.price', $node);
            if ($priceNodes->length > 0) {
                $price = $priceNodes->item(0)->textContent;
            } else {
                $price = '';
            }
            
            $this->pipeline->process([
                'title' => $title,
                'price' => $price
            ]);
        }
    }

    public function setPipeline(BookPipeline $pipeline) {
        $this->pipeline = $pipeline; // Esempio di utilizzo: $spider->setPipeline(new BookPipeline());
    }
}
