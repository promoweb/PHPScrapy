<?php
namespace phpscrapy\Pipeline;

class BookPipeline {
    private $data = [];
    
    public function process(array $item) {
        $this->data[] = $item;
    }
    
    public function getResults(): array {
        return $this->data;
    }
    
    public function exportData(string $filename = 'results.json') {
        $data = $this->data;
        $this->data = []; // Reset data after export
        
        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Impossibile serializzare i dati in JSON');
        }
        
        $file = fopen($filename, 'w');
        if ($file === false) {
            throw new \RuntimeException("Impossibile creare il file $filename");
        }
        
        fwrite($file, $json);
        fclose($file);
        
        return $filename;
    }
}
