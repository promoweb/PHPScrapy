<?php

// Composer dependencies required:
// composer require guzzlehttp/guzzle react/http-client react/stream react/promise

namespace PHPScrapy;

use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

/**
 * Classe base per definire uno Spider
 */
abstract class Spider
{
    protected $name;
    protected $startUrls = [];
    protected $allowedDomains = [];
    protected $customSettings = [];

    public function __construct()
    {
        $this->name = $this->getName();
    }

    abstract protected function getName(): string;
    abstract public function parse(Response $response): \Generator;

    public function getStartUrls(): array
    {
        return $this->startUrls;
    }

    public function getAllowedDomains(): array
    {
        return $this->allowedDomains;
    }

    public function getCustomSettings(): array
    {
        return $this->customSettings;
    }
}

/**
 * Rappresenta una richiesta HTTP
 */
class Request
{
    public $url;
    public $method;
    public $headers;
    public $body;
    public $callback;
    public $meta;
    public $priority;

    public function __construct(
        string $url,
        callable $callback = null,
        string $method = 'GET',
        array $headers = [],
        string $body = '',
        array $meta = [],
        int $priority = 0
    ) {
        $this->url = $url;
        $this->callback = $callback;
        $this->method = $method;
        $this->headers = $headers;
        $this->body = $body;
        $this->meta = $meta;
        $this->priority = $priority;
    }
}

/**
 * Rappresenta una risposta HTTP
 */
class Response
{
    public $url;
    public $status;
    public $headers;
    public $body;
    public $request;
    private $xpath;
    private $dom;

    public function __construct(string $url, int $status, array $headers, string $body, Request $request)
    {
        $this->url = $url;
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->request = $request;
    }

    /**
     * Estrae dati usando selettori CSS (semplificato)
     */
    public function css(string $selector): array
    {
        $this->initDom();
        $xpath = $this->cssToXpath($selector);
        return $this->xpath($xpath);
    }

    /**
     * Estrae dati usando XPath
     */
    public function xpath(string $expression): array
    {
        $this->initDom();
        $nodes = $this->xpath->query($expression);
        $results = [];
        
        foreach ($nodes as $node) {
            $results[] = new Selector($node, $this->dom);
        }
        
        return $results;
    }

    private function initDom(): void
    {
        if ($this->dom === null) {
            $this->dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $this->dom->loadHTML($this->body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $this->xpath = new \DOMXPath($this->dom);
        }
    }

    private function cssToXpath(string $css): string
    {
        // Conversione CSS to XPath semplificata
        $css = trim($css);
        $xpath = '//*';
        
        // Gestione semplificata di alcuni selettori comuni
        if (strpos($css, '#') === 0) {
            $id = substr($css, 1);
            $xpath = "//*[@id='$id']";
        } elseif (strpos($css, '.') === 0) {
            $class = substr($css, 1);
            $xpath = "//*[contains(@class, '$class')]";
        } elseif (preg_match('/^[a-zA-Z]+$/', $css)) {
            $xpath = "//$css";
        }
        
        return $xpath;
    }
}

/**
 * Rappresenta un elemento selezionato
 */
class Selector
{
    private $node;
    private $dom;

    public function __construct(\DOMNode $node, \DOMDocument $dom)
    {
        $this->node = $node;
        $this->dom = $dom;
    }

    public function get(): string
    {
        return $this->dom->saveHTML($this->node);
    }

    public function text(): string
    {
        return trim($this->node->textContent);
    }

    public function attr(string $name): ?string
    {
        if ($this->node instanceof \DOMElement) {
            return $this->node->getAttribute($name);
        }
        return null;
    }
}

/**
 * Rappresenta un elemento di dati estratti
 */
class Item implements \ArrayAccess
{
    private $data = [];

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet($offset)
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        $this->data[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->data[$offset]);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}

/**
 * Pipeline per processare gli item
 */
interface ItemPipeline
{
    public function processItem(Item $item, Spider $spider): Item;
}

/**
 * Scheduler per gestire le richieste
 */
class Scheduler
{
    private $queue;
    private $seen = [];

    public function __construct()
    {
        $this->queue = new \SplPriorityQueue();
        $this->queue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    public function enqueueRequest(Request $request): bool
    {
        $fingerprint = $this->getFingerprint($request);
        
        if (isset($this->seen[$fingerprint])) {
            return false; // Richiesta duplicata
        }
        
        $this->seen[$fingerprint] = true;
        $this->queue->insert($request, $request->priority);
        return true;
    }

    public function nextRequest(): ?Request
    {
        if ($this->queue->isEmpty()) {
            return null;
        }
        
        $item = $this->queue->extract();
        return $item['data'];
    }

    public function hasRequests(): bool
    {
        return !$this->queue->isEmpty();
    }

    private function getFingerprint(Request $request): string
    {
        return md5($request->method . ':' . $request->url);
    }
}

/**
 * Downloader per eseguire le richieste HTTP
 */
class Downloader
{
    private $client;
    private $settings;

    public function __construct(array $settings = [])
    {
        $this->settings = array_merge([
            'concurrent_requests' => 16,
            'delay' => 0,
            'user_agent' => 'PHPScrapy/1.0',
            'timeout' => 30,
        ], $settings);

        $this->client = new Client([
            'timeout' => $this->settings['timeout'],
            'headers' => [
                'User-Agent' => $this->settings['user_agent']
            ]
        ]);
    }

    public function fetch(Request $request): PromiseInterface
    {
        $promise = $this->client->requestAsync(
            $request->method,
            $request->url,
            [
                'headers' => $request->headers,
                'body' => $request->body,
                'http_errors' => false
            ]
        );

        return $promise->then(function ($response) use ($request) {
            return new Response(
                $request->url,
                $response->getStatusCode(),
                $response->getHeaders(),
                $response->getBody()->getContents(),
                $request
            );
        });
    }
}

/**
 * Engine principale di PHPScrapy
 */
class Engine
{
    private $scheduler;
    private $downloader;
    private $pipelines = [];
    private $settings;
    private $activeRequests = 0;
    private $maxConcurrentRequests;

    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
        $this->scheduler = new Scheduler();
        $this->downloader = new Downloader($settings);
        $this->maxConcurrentRequests = $settings['concurrent_requests'] ?? 16;
    }

    public function addPipeline(ItemPipeline $pipeline): void
    {
        $this->pipelines[] = $pipeline;
    }

    public function crawl(Spider $spider): PromiseInterface
    {
        // Aggiungi richieste iniziali
        foreach ($spider->getStartUrls() as $url) {
            $request = new Request($url, [$spider, 'parse']);
            $this->scheduler->enqueueRequest($request);
        }

        return $this->start();
    }

    private function start(): PromiseInterface
    {
        $deferred = new \React\Promise\Deferred();
        
        $this->processNextRequests()->then(function () use ($deferred) {
            $deferred->resolve();
        }, function ($error) use ($deferred) {
            $deferred->reject($error);
        });

        return $deferred->promise();
    }

    private function processNextRequests(): PromiseInterface
    {
        $promises = [];

        while ($this->activeRequests < $this->maxConcurrentRequests && 
               $this->scheduler->hasRequests()) {
            
            $request = $this->scheduler->nextRequest();
            if ($request) {
                $this->activeRequests++;
                $promise = $this->processRequest($request);
                $promises[] = $promise;
            }
        }

        if (empty($promises) && $this->activeRequests === 0) {
            return Promise\resolve();
        }

        return Promise\settle($promises)->then(function () {
            if ($this->scheduler->hasRequests() || $this->activeRequests > 0) {
                return $this->processNextRequests();
            }
            return Promise\resolve();
        });
    }

    private function processRequest(Request $request): PromiseInterface
    {
        return $this->downloader->fetch($request)->then(function (Response $response) use ($request) {
            $this->activeRequests--;
            
            if ($request->callback) {
                $results = call_user_func($request->callback, $response);
                
                if ($results instanceof \Generator) {
                    foreach ($results as $result) {
                        $this->handleResult($result, $response->request);
                    }
                }
            }

            return $this->processNextRequests();
        });
    }

    private function handleResult($result, Request $request): void
    {
        if ($result instanceof Request) {
            $this->scheduler->enqueueRequest($result);
        } elseif ($result instanceof Item) {
            $this->processItem($result);
        }
    }

    private function processItem(Item $item): void
    {
        foreach ($this->pipelines as $pipeline) {
            $item = $pipeline->processItem($item, null);
        }
    }
}

/**
 * Classe principale per avviare il crawler
 */
class CrawlerProcess
{
    private $settings;

    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
    }

    public function crawl(Spider $spider): void
    {
        $engine = new Engine($this->settings);
        
        $engine->crawl($spider)->then(function () {
            echo "Crawling completato!\n";
        }, function ($error) {
            echo "Errore durante il crawling: " . $error->getMessage() . "\n";
        });
    }
}

// Pipeline di esempio per salvare in JSON
class JsonWriterPipeline implements ItemPipeline
{
    private $file;
    private $items = [];

    public function __construct(string $filename = 'items.json')
    {
        $this->file = $filename;
    }

    public function processItem(Item $item, ?Spider $spider): Item
    {
        $this->items[] = $item->toArray();
        file_put_contents($this->file, json_encode($this->items, JSON_PRETTY_PRINT));
        return $item;
    }
}

// Esempio di Spider per estrarre quotes
class QuotesSpider extends Spider
{
    protected $startUrls = ['http://quotes.toscrape.com'];
    protected $name = 'quotes';

    protected function getName(): string
    {
        return 'quotes';
    }

    public function parse(Response $response): \Generator
    {
        $quotes = $response->css('div.quote');
        
        foreach ($quotes as $quote) {
            $item = new Item([
                'text' => $quote->css('span.text::text')->text(),
                'author' => $quote->css('small.author::text')->text(),
                'tags' => array_map(function($tag) {
                    return $tag->text();
                }, $quote->css('div.tags a.tag'))
            ]);
            
            yield $item;
        }

        // Segui i link di paginazione
        $nextPage = $response->css('li.next a::attr(href)');
        if (!empty($nextPage)) {
            $nextUrl = 'http://quotes.toscrape.com' . $nextPage[0]->attr('href');
            yield new Request($nextUrl, [$this, 'parse']);
        }
    }
}

// Esempio di utilizzo
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    // Configurazione
    $settings = [
        'concurrent_requests' => 8,
        'delay' => 1,
        'user_agent' => 'PHPScrapy Bot 1.0'
    ];

    // Creazione del processo crawler
    $process = new CrawlerProcess($settings);
    
    // Creazione dello spider
    $spider = new QuotesSpider();
    
    // Aggiunta di pipeline
    $engine = new Engine($settings);
    $engine->addPipeline(new JsonWriterPipeline('quotes.json'));
    
    // Avvio del crawling
    echo "Avvio crawling di quotes.toscrape.com...\n";
    $process->crawl($spider);
}
