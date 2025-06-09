<?php

require_once 'phpscrapy.php';

use PHPScrapy\{Spider, Response, Request, Item, ItemPipeline, CrawlerProcess, Engine};

/**
 * ESEMPIO 1: Spider per E-commerce
 * Estrae prodotti da un sito di e-commerce
 */
class EcommerceSpider extends Spider
{
    protected $name = 'ecommerce';
    protected $startUrls = ['https://example-shop.com/products'];
    protected $allowedDomains = ['example-shop.com'];

    protected function getName(): string
    {
        return 'ecommerce';
    }

    public function parse(Response $response): \Generator
    {
        // Estrai i link dei prodotti
        $productLinks = $response->css('a.product-link');
        
        foreach ($productLinks as $link) {
            $productUrl = $link->attr('href');
            if ($productUrl) {
                yield new Request(
                    $this->urljoin($response->url, $productUrl),
                    [$this, 'parseProduct']
                );
            }
        }

        // Gestisci paginazione
        $nextPage = $response->css('a.next-page');
        if (!empty($nextPage)) {
            $nextUrl = $nextPage[0]->attr('href');
            yield new Request(
                $this->urljoin($response->url, $nextUrl),
                [$this, 'parse']
            );
        }
    }

    public function parseProduct(Response $response): \Generator
    {
        $item = new Item([
            'url' => $response->url,
            'name' => $this->extractText($response->css('h1.product-title')),
            'price' => $this->extractPrice($response->css('.price')),
            'description' => $this->extractText($response->css('.product-description')),
            'images' => $this->extractImages($response->css('img.product-image')),
            'rating' => $this->extractRating($response->css('.rating')),
            'availability' => $this->extractText($response->css('.availability')),
            'sku' => $this->extractText($response->css('.sku')),
            'categories' => $this->extractCategories($response->css('.breadcrumb a')),
            'scraped_at' => date('Y-m-d H:i:s')
        ]);

        yield $item;
    }

    private function extractText($selectors): string
    {
        return !empty($selectors) ? trim($selectors[0]->text()) : '';
    }

    private function extractPrice($selectors): float
    {
        if (empty($selectors)) return 0.0;
        
        $priceText = $selectors[0]->text();
        preg_match('/[\d,]+\.?\d*/', $priceText, $matches);
        return !empty($matches) ? floatval(str_replace(',', '', $matches[0])) : 0.0;
    }

    private function extractImages($selectors): array
    {
        $images = [];
        foreach ($selectors as $img) {
            $src = $img->attr('src');
            if ($src) {
                $images[] = $src;
            }
        }
        return $images;
    }

    private function extractRating($selectors): float
    {
        if (empty($selectors)) return 0.0;
        
        $ratingText = $selectors[0]->text();
        preg_match('/(\d+\.?\d*)/', $ratingText, $matches);
        return !empty($matches) ? floatval($matches[1]) : 0.0;
    }

    private function extractCategories($selectors): array
    {
        $categories = [];
        foreach ($selectors as $link) {
            $categories[] = trim($link->text());
        }
        return array_filter($categories);
    }

    private function urljoin($base, $url): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== null) {
            return $url;
        }
        
        $base_parts = parse_url($base);
        if ($url[0] === '/') {
            return $base_parts['scheme'] . '://' . $base_parts['host'] . $url;
        }
        
        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }
}

/**
 * ESEMPIO 2: Spider per News
 * Estrae articoli di news da multiple fonti
 */
class NewsSpider extends Spider
{
    protected $name = 'news';
    protected $startUrls = [
        'https://example-news.com/latest',
        'https://another-news.com/today'
    ];

    protected function getName(): string
    {
        return 'news';
    }

    public function parse(Response $response): \Generator
    {
        $articles = $response->css('article, .article-item');
        
        foreach ($articles as $article) {
            $title = $this->extractText($article->css('h2, h3, .title'));
            $link = $article->css('a')[0] ?? null;
            
            if ($title && $link) {
                $articleUrl = $link->attr('href');
                yield new Request(
                    $this->urljoin($response->url, $articleUrl),
                    [$this, 'parseArticle'],
                    'GET',
                    [],
                    '',
                    ['title' => $title] // Passa il titolo nei metadata
                );
            }
        }
    }

    public function parseArticle(Response $response): \Generator
    {
        $content = $this->extractFullContent($response);
        
        $item = new Item([
            'url' => $response->url,
            'title' => $response->request->meta['title'] ?? $this->extractText($response->css('h1')),
            'content' => $content,
            'author' => $this->extractText($response->css('.author, .byline')),
            'published_date' => $this->extractDate($response),
            'summary' => $this->generateSummary($content),
            'word_count' => str_word_count(strip_tags($content)),
            'source_domain' => parse_url($response->url, PHP_URL_HOST),
            'scraped_at' => date('Y-m-d H:i:s')
        ]);

        yield $item;
    }

    private function extractFullContent(Response $response): string
    {
        $contentSelectors = [
            '.article-content',
            '.post-content', 
            '[itemprop="articleBody"]',
            '.entry-content'
        ];

        foreach ($contentSelectors as $selector) {
            $content = $response->css($selector);
            if (!empty($content)) {
                return $content[0]->get();
            }
        }

        return '';
    }

    private function extractDate(Response $response): string
    {
        $dateSelectors = [
            'time[datetime]',
            '.published-date',
            '.post-date',
            '[itemprop="datePublished"]'
        ];

        foreach ($dateSelectors as $selector) {
            $elements = $response->css($selector);
            if (!empty($elements)) {
                $dateStr = $elements[0]->attr('datetime') ?: $elements[0]->text();
                if ($dateStr) {
                    return date('Y-m-d H:i:s', strtotime($dateStr));
                }
            }
        }

        return '';
    }

    private function generateSummary(string $content, int $maxWords = 50): string
    {
        $text = strip_tags($content);
        $words = explode(' ', $text);
        
        if (count($words) <= $maxWords) {
            return $text;
        }

        return implode(' ', array_slice($words, 0, $maxWords)) . '...';
    }

    private function extractText($selectors): string
    {
        return !empty($selectors) ? trim($selectors[0]->text()) : '';
    }

    private function urljoin($base, $url): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== null) {
            return $url;
        }
        
        $base_parts = parse_url($base);
        if ($url[0] === '/') {
            return $base_parts['scheme'] . '://' . $base_parts['host'] . $url;
        }
        
        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }
}

/**
 * PIPELINE AVANZATE
 */

// Pipeline per filtrare duplicati
class DuplicateFilterPipeline implements ItemPipeline
{
    private $seen = [];
    private $keyField;

    public function __construct(string $keyField = 'url')
    {
        $this->keyField = $keyField;
    }

    public function processItem(Item $item, ?Spider $spider): Item
    {
        $key = $item[$this->keyField] ?? null;
        
        if ($key && isset($this->seen[$key])) {
            throw new \Exception("Item duplicato trovato: {$key}");
        }
        
        if ($key) {
            $this->seen[$key] = true;
        }
        
        return $item;
    }
}

// Pipeline per validazione dati
class ValidationPipeline implements ItemPipeline
{
    private $requiredFields;

    public function __construct(array $requiredFields = [])
    {
        $this->requiredFields = $requiredFields;
    }

    public function processItem(Item $item, ?Spider $spider): Item
    {
        foreach ($this->requiredFields as $field) {
            if (empty($item[$field])) {
                throw new \Exception("Campo obbligatorio mancante: {$field}");
            }
        }

        // Pulizia dei dati
        foreach ($item->toArray() as $key => $value) {
            if (is_string($value)) {
                $item[$key] = trim(preg_replace('/\s+/', ' ', $value));
            }
        }

        return $item;
    }
}

// Pipeline per database MySQL
class MySQLPipeline implements ItemPipeline
{
    private $pdo;
    private $table;

    public function __construct(string $dsn, string $username, string $password, string $table)
    {
        $this->pdo = new \PDO($dsn, $username, $password);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->table = $table;
    }

    public function processItem(Item $item, ?Spider $spider): Item
    {
        $data = $item->toArray();
        $fields = array_keys($data);
        $placeholders = ':' . implode(', :', $fields);
        
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $fields) . ") VALUES ({$placeholders})";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        
        return $item;
    }
}

// Pipeline per esportazione CSV
class CsvWriterPipeline implements ItemPipeline
{
    private $file;
    private $delimiter;
    private $headerWritten = false;

    public function __construct(string $filename = 'items.csv', string $delimiter = ',')
    {
        $this->file = fopen($filename, 'w');
        $this->delimiter = $delimiter;
    }

    public function processItem(Item $item, ?Spider $spider): Item
    {
        $data = $item->toArray();
        
        if (!$this->headerWritten) {
            fputcsv($this->file, array_keys($data), $this->delimiter);
            $this->headerWritten = true;
        }
        
        fputcsv($this->file, array_values($data), $this->delimiter);
        
        return $item;
    }

    public function __destruct()
    {
        if ($this->file) {
            fclose($this->file);
        }
    }
}

/**
 * ESEMPI DI UTILIZZO
 */

// Esempio 1: Scraping E-commerce con pipeline multiple
function runEcommerceCrawler()
{
    $settings = [
        'concurrent_requests' => 4,
        'delay' => 2,
        'user_agent' => 'Mozilla/5.0 (compatible; PHPScrapy/1.0)'
    ];

    $engine = new Engine($settings);
    
    // Aggiungi pipeline
    $engine->addPipeline(new ValidationPipeline(['name', 'price']));
    $engine->addPipeline(new DuplicateFilterPipeline('url'));
    $engine->addPipeline(new CsvWriterPipeline('products.csv'));
    
    $spider = new EcommerceSpider();
    
    echo "Avvio scraping e-commerce...\n";
    $engine->crawl($spider);
}

// Esempio 2: Scraping News con salvataggio su database
function runNewsCrawler()
{
    $settings = [
        'concurrent_requests' => 6,
        'delay' => 1,
        'user_agent' => 'NewsBot/1.0'
    ];

    $engine = new Engine($settings);
    
    // Pipeline per database (esempio)
    // $engine->addPipeline(new MySQLPipeline('mysql:host=localhost;dbname=news', 'user', 'pass', 'articles'));
    
    $engine->addPipeline(new JsonWriterPipeline('news_articles.json'));
    $engine->addPipeline(new DuplicateFilterPipeline('url'));
    
    $spider = new NewsSpider();
    
    echo "Avvio scraping news...\n";
    $engine->crawl($spider);
}

// Esempio 3: Crawler con configurazione personalizzata
function runCustomCrawler()
{
    $customSettings = [
        'concurrent_requests' => 10,
        'delay' => 0.5,
        'timeout' => 60,
        'user_agent' => 'CustomBot/2.0',
        'headers' => [
            'Accept-Language' => 'it-IT,it;q=0.9,en;q=0.8'
        ]
    ];

    $process = new CrawlerProcess($customSettings);
    
    // Puoi anche creare spider al volo
    $spider = new class extends Spider {
        protected $name = 'custom';
        protected $startUrls = ['https://httpbin.org/html'];
        
        protected function getName(): string
        {
            return 'custom';
        }
        
        public function parse(Response $response): \Generator
        {
            // Estrai tutti i link
            $links = $response->xpath('//a/@href');
            
            foreach ($links as $link) {
                $url = $link->attr('href');
                if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                    yield new Item([
                        'url' => $url,
                        'text' => $link->text(),
                        'found_on' => $response->url,
                        'timestamp' => time()
                    ]);
                }
            }
        }
    };
    
    echo "Avvio crawler personalizzato...\n";
    $process->crawl($spider);
}

/**
 * SPIDER SPECIALIZZATI
 */

// Spider per API REST
class ApiSpider extends Spider
{
    protected $name = 'api';
    protected $startUrls = ['https://jsonplaceholder.typicode.com/posts'];
    
    protected function getName(): string
    {
        return 'api';
    }
    
    public function parse(Response $response): \Generator
    {
        $data = json_decode($response->body, true);
        
        if (is_array($data)) {
            foreach ($data as $post) {
                // Per ogni post, fai una richiesta per i commenti
                $commentsUrl = "https://jsonplaceholder.typicode.com/posts/{$post['id']}/comments";
                yield new Request(
                    $commentsUrl, 
                    [$this, 'parseComments'],
                    'GET',
                    ['Accept' => 'application/json'],
                    '',
                    ['post' => $post]
                );
            }
        }
    }
    
    public function parseComments(Response $response): \Generator
    {
        $comments = json_decode($response->body, true);
        $post = $response->request->meta['post'];
        
        yield new Item([
            'post_id' => $post['id'],
            'post_title' => $post['title'],
            'post_body' => $post['body'],
            'comments_count' => count($comments),
            'comments' => $comments,
            'scraped_at' => date('Y-m-d H:i:s')
        ]);
    }
}

// Spider per siti con JavaScript (limitato senza browser)
class JsSpider extends Spider
{
    protected $name = 'js';
    protected $startUrls = ['https://example.com/dynamic-content'];
    
    protected function getName(): string
    {
        return 'js';
    }
    
    public function parse(Response $response): \Generator
    {
        // Per contenuti JS, potresti dover fare richieste AJAX dirette
        // Esempio: cercare gli endpoint API nel codice sorgente
        
        preg_match_all('/api\/[^"\']+/i', $response->body, $matches);
        
        foreach ($matches[0] as $apiEndpoint) {
            $apiUrl = $this->urljoin($response->url, $apiEndpoint);
            yield new Request(
                $apiUrl,
                [$this, 'parseApiData'],
                'GET',
                ['Accept' => 'application/json']
            );
        }
    }
    
    public function parseApiData(Response $response): \Generator
    {
        $data = json_decode($response->body, true);
        
        if ($data) {
            yield new Item([
                'api_endpoint' => $response->url,
                'data' => $data,
                'response_size' => strlen($response->body),
                'scraped_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
    
    private function urljoin($base, $url): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== null) {
            return $url;
        }
        
        $base_parts = parse_url($base);
        if ($url[0] === '/') {
            return $base_parts['scheme'] . '://' . $base_parts['host'] . $url;
        }
        
        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }
}

/**
 * MIDDLEWARE PERSONALIZZATI
 */

// Middleware per rotazione User-Agent
class UserAgentMiddleware
{
    private $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ];
    
    public function processRequest(Request $request): Request
    {
        $randomUserAgent = $this->userAgents[array_rand($this->userAgents)];
        $request->headers['User-Agent'] = $randomUserAgent;
        return $request;
    }
}

// Middleware per gestione proxy
class ProxyMiddleware
{
    private $proxies = [];
    private $currentProxy = 0;
    
    public function __construct(array $proxies)
    {
        $this->proxies = $proxies;
    }
    
    public function processRequest(Request $request): Request
    {
        if (!empty($this->proxies)) {
            $proxy = $this->proxies[$this->currentProxy];
            $request->headers['Proxy'] = $proxy;
            $this->currentProxy = ($this->currentProxy + 1) % count($this->proxies);
        }
        return $request;
    }
}

/**
 * UTILITIES AVANZATE
 */

// Classe per gestire sessioni e cookies
class SessionManager
{
    private $cookies = [];
    
    public function updateCookies(array $headers): void
    {
        if (isset($headers['Set-Cookie'])) {
            $cookieHeaders = is_array($headers['Set-Cookie']) ? 
                $headers['Set-Cookie'] : [$headers['Set-Cookie']];
                
            foreach ($cookieHeaders as $cookie) {
                $this->parseCookie($cookie);
            }
        }
    }
    
    public function getCookieHeader(): string
    {
        $cookieStrings = [];
        foreach ($this->cookies as $name => $value) {
            $cookieStrings[] = "{$name}={$value}";
        }
        return implode('; ', $cookieStrings);
    }
    
    private function parseCookie(string $cookie): void
    {
        $parts = explode(';', $cookie);
        $nameValue = explode('=', trim($parts[0]), 2);
        
        if (count($nameValue) === 2) {
            $this->cookies[$nameValue[0]] = $nameValue[1];
        }
    }
}

// Classe per rate limiting
class RateLimiter
{
    private $requests = [];
    private $maxRequests;
    private $timeWindow;
    
    public function __construct(int $maxRequests = 60, int $timeWindow = 60)
    {
        $this->maxRequests = $maxRequests;
        $this->timeWindow = $timeWindow;
    }
    
    public function canMakeRequest(): bool
    {
        $now = time();
        $this->requests = array_filter($this->requests, function($time) use ($now) {
            return ($now - $time) < $this->timeWindow;
        });
        
        return count($this->requests) < $this->maxRequests;
    }
    
    public function recordRequest(): void
    {
        $this->requests[] = time();
    }
    
    public function getWaitTime(): int
    {
        if ($this->canMakeRequest()) {
            return 0;
        }
        
        $oldestRequest = min($this->requests);
        return ($oldestRequest + $this->timeWindow) - time();
    }
}

/**
 * ESEMPI DI UTILIZZO COMPLETI
 */

// Esempio completo con tutte le funzionalità
function runAdvancedCrawler()
{
    echo "=== CRAWLER AVANZATO ===\n";
    
    $settings = [
        'concurrent_requests' => 5,
        'delay' => 1,
        'timeout' => 30,
        'user_agent' => 'AdvancedBot/1.0'
    ];
    
    $engine = new Engine($settings);
    
    // Pipeline chain
    $engine->addPipeline(new ValidationPipeline(['url']));
    $engine->addPipeline(new DuplicateFilterPipeline('url'));
    $engine->addPipeline(new JsonWriterPipeline('advanced_results.json'));
    $engine->addPipeline(new CsvWriterPipeline('advanced_results.csv'));
    
    // Crawler multiplo
    $spiders = [
        new ApiSpider(),
        new QuotesSpider(),
        // new EcommerceSpider(), // Decommentare per usare
        // new NewsSpider()       // Decommentare per usare
    ];
    
    foreach ($spiders as $spider) {
        echo "Avvio spider: {$spider->getName()}\n";
        $engine->crawl($spider)->then(function() use ($spider) {
            echo "Completato spider: {$spider->getName()}\n";
        });
    }
}

// Esempio con rate limiting
function runRateLimitedCrawler()
{
    echo "=== CRAWLER CON RATE LIMITING ===\n";
    
    $rateLimiter = new RateLimiter(10, 60); // 10 richieste per minuto
    
    class RateLimitedSpider extends Spider
    {
        private $rateLimiter;
        
        public function __construct(RateLimiter $rateLimiter)
        {
            $this->rateLimiter = $rateLimiter;
            parent::__construct();
        }
        
        protected $name = 'rate_limited';
        protected $startUrls = ['https://httpbin.org/delay/1'];
        
        protected function getName(): string
        {
            return 'rate_limited';
        }
        
        public function parse(Response $response): \Generator
        {
            // Controlla rate limit prima di fare nuove richieste
            if ($this->rateLimiter->canMakeRequest()) {
                $this->rateLimiter->recordRequest();
                
                yield new Item([
                    'url' => $response->url,
                    'status' => $response->status,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                
                // Genera più richieste se necessario
                for ($i = 1; $i <= 5; $i++) {
                    if ($this->rateLimiter->canMakeRequest()) {
                        yield new Request(
                            "https://httpbin.org/delay/{$i}",
                            [$this, 'parseDelay']
                        );
                        $this->rateLimiter->recordRequest();
                    } else {
                        echo "Rate limit raggiunto, aspetto...\n";
                        sleep($this->rateLimiter->getWaitTime());
                    }
                }
            }
        }
        
        public function parseDelay(Response $response): \Generator
        {
            $data = json_decode($response->body, true);
            yield new Item([
                'url' => $response->url,
                'delay' => $data['delay'] ?? 0,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
    }
    
    $spider = new RateLimitedSpider($rateLimiter);
    $process = new CrawlerProcess(['concurrent_requests' => 2]);
    $process->crawl($spider);
}

// Esempio di utilizzo con sessioni
function runSessionCrawler()
{
    echo "=== CRAWLER CON SESSIONI ===\n";
    
    class SessionSpider extends Spider
    {
        private $sessionManager;
        
        public function __construct()
        {
            $this->sessionManager = new SessionManager();
            parent::__construct();
        }
        
        protected $name = 'session';
        protected $startUrls = ['https://httpbin.org/cookies/set/test/value'];
        
        protected function getName(): string
        {
            return 'session';
        }
        
        public function parse(Response $response): \Generator
        {
            // Aggiorna cookies dalla risposta
            $this->sessionManager->updateCookies($response->headers);
            
            // Fai una richiesta che richiede i cookies
            yield new Request(
                'https://httpbin.org/cookies',
                [$this, 'parseCookies'],
                'GET',
                ['Cookie' => $this->sessionManager->getCookieHeader()]
            );
        }
        
        public function parseCookies(Response $response): \Generator
        {
            $data = json_decode($response->body, true);
            yield new Item([
                'cookies' => $data['cookies'] ?? [],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
    }
    
    $spider = new SessionSpider();
    $process = new CrawlerProcess();
    $process->crawl($spider);
}

// Esecuzione degli esempi
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    echo "Scegli un esempio da eseguire:\n";
    echo "1. E-commerce Crawler\n";
    echo "2. News Crawler\n";
    echo "3. Custom Crawler\n";
    echo "4. Advanced Crawler\n";
    echo "5. Rate Limited Crawler\n";
    echo "6. Session Crawler\n";
    
    $choice = readline("Inserisci il numero (1-6): ");
    
    switch ($choice) {
        case '1':
            runEcommerceCrawler();
            break;
        case '2':
            runNewsCrawler();
            break;
        case '3':
            runCustomCrawler();
            break;
        case '4':
            runAdvancedCrawler();
            break;
        case '5':
            runRateLimitedCrawler();
            break;
        case '6':
            runSessionCrawler();
            break;
        default:
            echo "Scelta non valida. Eseguo il crawler avanzato...\n";
            runAdvancedCrawler();
    }
}