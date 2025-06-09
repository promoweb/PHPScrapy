# PHPScrapy - PHP Web Scraping Framework

## Overview
PHPScrapy is a lightweight PHP web scraping framework inspired by Python's Scrapy. It provides:
- Asynchronous HTTP requests using Guzzle and ReactPHP
- Extensible spider definition system
- Item pipeline processing
- Request scheduling and deduplication
- DOM parsing utilities

## Installation
1. Ensure you have PHP 7.2.5+ and Composer installed
2. Install dependencies:
```bash
composer require guzzlehttp/guzzle react/http-client react/stream react/promise
```

## Creating a Spider
Create a new spider by extending the `Spider` abstract class:

```php
use PHPScrapy\Spider;
use PHPScrapy\Response;

class MySpider extends Spider
{
    protected $startUrls = ['https://example.com'];
    
    protected function getName(): string {
        return 'my_spider';
    }
    
    public function parse(Response $response): \Generator {
        // Extract data using CSS selectors
        $items = $response->css('.product-item');
        
        foreach ($items as $item) {
            yield new Item([
                'title' => $item->css('h2::text')->text(),
                'price' => $item->css('.price::text')->text()
            ]);
        }
        
        // Follow pagination
        $nextPage = $response->css('a.next-page::attr(href)');
        if ($nextPage) {
            yield new Request($nextPage->attr('href'), [$this, 'parse']);
        }
    }
}
```

## Item Pipelines
Process extracted items by implementing the ItemPipeline interface:

```php
use PHPScrapy\ItemPipeline;
use PHPScrapy\Item;

class JsonExportPipeline implements ItemPipeline
{
    private $filename = 'output.json';
    private $items = [];
    
    public function processItem(Item $item, Spider $spider): Item {
        $this->items[] = $item->toArray();
        file_put_contents($this->filename, json_encode($this->items, JSON_PRETTY_PRINT));
        return $item;
    }
}
```

## Configuration
Customize scraping behavior with settings:

```php
$settings = [
    'concurrent_requests' => 8,  // Max parallel requests (default: 16)
    'delay' => 1,                // Seconds between requests (default: 0)
    'user_agent' => 'MyBot/1.0', // Custom user agent
    'timeout' => 30              // Request timeout in seconds
];
```

## Running Spiders
Execute your spider using the CrawlerProcess:

```php
use PHPScrapy\CrawlerProcess;

// Apply configuration
$settings = [
    'concurrent_requests' => 4,
    'delay' => 0.5
];

$process = new CrawlerProcess($settings);
$spider = new MySpider();

// Add pipelines
$engine = $process->getEngine();
$engine->addPipeline(new JsonExportPipeline());

// Start crawling
$process->crawl($spider);
```

## Example: Quotes Spider
The framework includes a sample spider for quotes.toscrape.com:

```php
class QuotesSpider extends Spider
{
    protected $startUrls = ['http://quotes.toscrape.com'];
    protected $name = 'quotes';

    protected function getName(): string {
        return 'quotes';
    }

    public function parse(Response $response): \Generator {
        $quotes = $response->css('div.quote');
        
        foreach ($quotes as $quote) {
            yield new Item([
                'text' => $quote->css('span.text::text')->text(),
                'author' => $quote->css('small.author::text')->text(),
                'tags' => array_map(function($tag) {
                    return $tag->text();
                }, $quote->css('div.tags a.tag'))
            ]);
        }

        // Follow pagination
        $nextPage = $response->css('li.next a::attr(href)');
        if (!empty($nextPage)) {
            $nextUrl = 'http://quotes.toscrape.com' . $nextPage[0]->attr('href');
            yield new Request($nextUrl, [$this, 'parse']);
        }
    }
}
```

## License
MIT License - Free for personal and commercial use
