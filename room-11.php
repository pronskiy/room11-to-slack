<?php

require 'vendor/autoload.php';

use DiDom\Document;
use DiDom\Element;
use DiDom\Exceptions\InvalidSelectorException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Message implements JsonSerializable
{
    public DateTimeImmutable $timestamp;

    public function __construct(
        public string $content,
        public string $user,
        string $timestamp,
        public string $url,
    ) 
    {
        $content = preg_replace('#<br\s*/?>#i', "\n", $content);
        $content = html_entity_decode($content);
        $content = trim($content);

        $tz = new DateTimeZone("UTC");
        $this->timestamp = DateTimeImmutable::createFromFormat('g:i A', $timestamp, $tz);
        
        $this->content = $content;
    }
    
    public function __toString(): string
    {
        return sprintf("*%s %s*:\n%s\n", $this->user, $this->timestamp->format('G:i'), $this->content);
    }
    
    public function jsonSerialize(): mixed
    {
        return $this->__toString();
    }
}

class WebScraperToSlack
{
    const URL = 'https://chat.stackoverflow.com/transcript/11';
    
    private HttpClientInterface $client;

    public function __construct()
    {
        $client = HttpClient::create();
        $this->client = $client;
    }

    /**
     * @throws ClientExceptionInterface
     * @throws ServerExceptionInterface
     * @throws InvalidSelectorException
     * @throws RedirectionExceptionInterface
     * @throws TransportExceptionInterface
     * @throws Exception
     */
    public function scrapeWebContent()
    {
        // The content to be scraped from the web page
        $messages = [];

        $tz = new DateTimeZone("UTC");
        $today = (new DateTime('now', $tz))->format('Y/m/d');

        // Scrape content from web page
        $page = $this->client->request('GET', self::URL . '/' . $today)->getContent();

        $document = new Document($page);

        /** @var Element[]|DOMElement[] $posts */
        $posts = $document->find('#transcript div.monologue');
        
        $timestamp = '0';
        foreach($posts as $post) {
            $user = $post->find('div.signature div.username a')[0]->getNode()->textContent;

            $timestamp_node = $post->find('.messages .timestamp');
            if (count($timestamp_node) > 0) {
                $timestamp = $timestamp_node[0]->getNode()->textContent;
            }
            
            $messages_nodes = $post->find('div.message');
            foreach ($messages_nodes as $msg) {
                if (count($msg->find('.partial')) > 0) {
                    $msg_id = str_replace('message-', '', $msg->getAttribute('id'));
                    $fullUrl = 'https://chat.stackoverflow.com/messages/11/'.$msg_id;
                    $content = $this->client->request('GET', $fullUrl)->getContent();
                } else {
                    if (count($msg->find('.content .full')) > 0) {
                        $content = $msg->find('.content .full')[0]->innerHtml();
                    } else {
                        $content = $msg->find('.content')[0]->innerHtml();    
                    }
                }
                
                $message = new Message(
                    $content,
                    $user,
                    $timestamp,
                    self::URL,
                );
                $messages[] = $message;
            }
        }
        
        return $messages;
    }

    public function postToSlack(string $text)
    {
        $webhookUrl = $_SERVER['SLACK_WEBHOOK'];
        $this->client->request('POST', $webhookUrl, [
            'json' => ['text' => $text],  
        ]);
    }

    public function getLastHourItems($items)
    {
        $tz = new DateTimeZone("UTC");
        $lastHour = (new DateTime('now', $tz))->sub(new DateInterval('PT1H'));


//        $m = array_map(static function (Message $msg) use ($lastHour) {
//            return sprintf('%s | %s | %d', $msg->timestamp->format('d/m/y H:i'), $lastHour->format('d/m/y H:i'), $msg->timestamp >= $lastHour);
//        }, $items);
//        
//        $this->postToSlack('_DEBUG:_ ' . var_export($m, true));

        return array_filter($items, static function (Message $msg) use ($lastHour) {
            return $msg->timestamp >= $lastHour;
        });
    }
}

$scraper = new WebScraperToSlack();

$messages = $scraper->scrapeWebContent();
$scraper->getLastHourItems($messages);

return function ($event) use ($messages, $scraper) {
    $lastHourItems = $scraper->getLastHourItems($messages);
    foreach ($lastHourItems as $message) {
        $scraper->postToSlack($message);
    }
    return json_encode($lastHourItems, JSON_PRETTY_PRINT);
};
