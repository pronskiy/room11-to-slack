<?php

require 'vendor/autoload.php';

use DiDom\Element;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use DiDom\Document;

class Message implements JsonSerializable
{
    public \DateTimeImmutable $timestamp;

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
        $this->timestamp = \DateTimeImmutable::createFromFormat('g:i A', $timestamp, $tz);
        
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
    private HttpClientInterface $client;

    public function __construct()
    {
        $client = HttpClient::create();
        $this->client = $client;
    }

    public function scrapeWebContent(string $url)
    {
        // The content to be scraped from the web page
        $messages = [];

        // Scrape content from web page
        $page = $this->client->request('GET', $url)->getContent();

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
                    $url,
                );
                $messages[] = $message;
            }
        }
        
        return $messages;
    }

    public function postToSlack(string $webhookUrl, string $text)
    {
        $this->client->request('POST', $webhookUrl, [
            'json' => ['text' => $text],  
        ]);
    }
}

$scraper = new WebScraperToSlack();

$url = 'https://chat.stackoverflow.com/transcript/11';
$webhookUrl = getenv('SLACK_WEBHOOK');

$messages = $scraper->scrapeWebContent($url);

function getLastHourItems($items)
{
    $tz = new DateTimeZone("UTC");
    $lastHour = (new \DateTime('now', $tz))->sub(new \DateInterval('PT1H'));

    return array_filter($items, static function (Message $msg) use ($lastHour) {
        return $msg->timestamp >= $lastHour;
    });
}

return function ($event) use ($messages, $scraper, $webhookUrl) {
    foreach (getLastHourItems($messages) as $message) {
        $scraper->postToSlack($webhookUrl, $message);  
    }
    return json_encode(getLastHourItems($messages), JSON_PRETTY_PRINT);
};
