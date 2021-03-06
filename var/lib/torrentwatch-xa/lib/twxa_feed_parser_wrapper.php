<?php

require_once 'laminas-xml/Security.php';
require_once 'PicoFeed/Config/Config.php';
require_once 'PicoFeed/Logging/Logger.php';
require_once 'PicoFeed/Base.php';
require_once 'PicoFeed/PicoFeedException.php';
require_once 'PicoFeed/Client/ClientException.php';
require_once 'PicoFeed/Client/Client.php';
require_once 'PicoFeed/Client/HttpHeaders.php';
require_once 'PicoFeed/Client/InvalidCertificateException.php';
require_once 'PicoFeed/Client/InvalidUrlException.php';
require_once 'PicoFeed/Client/UnauthorizedException.php';
require_once 'PicoFeed/Client/MaxSizeException.php';
require_once 'PicoFeed/Client/MaxRedirectException.php';
require_once 'PicoFeed/Client/ForbiddenException.php';
require_once 'PicoFeed/Client/TimeoutException.php';
require_once 'PicoFeed/Client/Curl.php';
require_once 'PicoFeed/Client/Url.php';
require_once 'PicoFeed/Encoding/Encoding.php';
require_once 'PicoFeed/Scraper/RuleLoader.php';
require_once 'PicoFeed/Filter/Filter.php';
require_once 'PicoFeed/Filter/Tag.php';
require_once 'PicoFeed/Filter/Attribute.php';
require_once 'PicoFeed/Filter/Html.php';
require_once 'PicoFeed/Generator/ContentGeneratorInterface.php';
require_once 'PicoFeed/Generator/FileContentGenerator.php';
require_once 'PicoFeed/Generator/YoutubeContentGenerator.php';
require_once 'PicoFeed/Processor/ItemProcessorInterface.php';
require_once 'PicoFeed/Processor/ContentFilterProcessor.php';
require_once 'PicoFeed/Processor/ContentGeneratorProcessor.php';
require_once 'PicoFeed/Processor/ItemPostProcessor.php';
require_once 'PicoFeed/Parser/DateParser.php';
require_once 'PicoFeed/Parser/ParserException.php';
require_once 'PicoFeed/Parser/MalformedXmlException.php';
require_once 'PicoFeed/Parser/XmlEntityException.php';
require_once 'PicoFeed/Parser/XmlParser.php';
require_once 'PicoFeed/Parser/ParserInterface.php';
require_once 'PicoFeed/Parser/Item.php';
require_once 'PicoFeed/Parser/Feed.php';
require_once 'PicoFeed/Parser/Parser.php';
require_once 'PicoFeed/Parser/Atom.php';
require_once 'PicoFeed/Parser/Rss10.php';
require_once 'PicoFeed/Parser/Rss20.php';
require_once 'PicoFeed/Parser/Rss91.php';
require_once 'PicoFeed/Parser/Rss92.php';
require_once 'PicoFeed/Reader/ReaderException.php';
require_once 'PicoFeed/Reader/UnsupportedFeedFormatException.php';
require_once 'PicoFeed/Reader/SubscriptionNotFoundException.php';
require_once 'PicoFeed/Reader/Reader.php';

use PicoFeed\Reader\Reader;
use PicoFeed\PicoFeedException;

class FeedParserWrapper {

    var $feedData = []; // parsed data from within feed document
    var $dateFormat = '';
    var $timeZone = '';

    function __construct($file, $cacheDir = '', $dateFormat = '', $timeZone = '', $cacheExpires = 3580) {
        if ($dateFormat !== '') {
            $this->dateFormat = $dateFormat;
        }
        if ($timeZone !== '') {
            $this->timeZone = $timeZone;
        } else {
            $this->timeZone = 'UTC';
        }
        if ($cacheDir !== '') {
            $cacheFile = $cacheDir . '/feedcache_' . md5($file);
            if (file_exists($cacheFile) && time() < filemtime($cacheFile) + $cacheExpires) {
                $timeTillExpiry = filemtime($cacheFile) + $cacheExpires - time();
                writeToLog("Feed cache expires in $timeTillExpiry" . "s, skipping refresh: $file\n", 2);
                // cache file is new enough
                $this->feedData = unserialize(join('', file($cacheFile)));
                // set 'cached' to 1 only if cached file is correct
                if ($this->feedData) {
                    $this->feedData['cached'] = 1;
                }
            } else {
                // cache file does not exist or is too old--create a new one
                writeToLog("Refreshing feed cache for: $file\n", 1);
                $this->parse($file);
                $serialized = serialize($this->feedData);
                if ($f = fopen($cacheFile, 'w')) {
                    fwrite($f, $serialized, strlen($serialized));
                    fclose($f);
                }
                if ($this->feedData) {
                    $this->feedData['cached'] = 0;
                }
            }
        } else {
            // cache is disabled; load and parse the file directly
            $this->parse($file);
            if ($this->feedData) {
                $this->feedData['cached'] = 0;
            }
        }
    }

    function parse($file) {
        try {
            $reader = new Reader;
            // get a resource
            $resource = $reader->download($file);
//            // detect feed format
//            $format = $reader->detectFormat($resource->getContent());
//            if (empty($format)) {
//                throw new UnsupportedFeedFormatException('Unable to detect feed format');
//            }
            // get the right parser instance according to the feed format
            $parser = $reader->getParser(
                    $resource->getUrl(),
                    $resource->getContent(),
                    $resource->getEncoding()
            );
            //TODO figure out why magnet: links are filtered out by the ContentFilter even though they are supposed to be whitelisted, then re-enable content filtering
            $parser->disableContentFiltering();
            // get a feed object
            $feed = $parser->execute();
            // print the feed properties with the magic method __toString()
            //writeToLog("raw feed: " . $feed . "\n", 2);
            $this->feedData = $this->convertFeedObjectToArray($feed);
        } catch (PicoFeedException $e) {
            writeToLog("Error parsing feed: $file: " . print_r($e, true) . "\n", -1);
        }
    }

    private function convertFeedObjectToArray($feedObject) {
        $feedArray = [];
        // Feed object
        //TODO get rid of ['feed']
        $feedArray['feed']['id'] = $feedObject->getId(); // Unique feed id
        $feedArray['feed']['title'] = $feedObject->getTitle(); // Feed title
        $feedArray['feed']['link'] = $feedObject->getFeedUrl(); // Feed URL
        $feedArray['feed']['website'] = $feedObject->getSiteUrl(); // Website URL
        $feedArray['feed']['updated'] = $this->convertDateTimeToString($feedObject->getDate(), 'c'); // Feed last updated date (DateTime object)
        //$feedArray['feed']['subtitle'] = $feedObject->getDescription(); // Feed description
        for ($i = 0; $i < count($feedObject->items); $i++) {
            // Item object
            $feedArray['feed']['entry'][$i]['id'] = $feedObject->items[$i]->getId(); // Item unique id
            $feedArray['feed']['entry'][$i]['title'] = $feedObject->items[$i]->getTitle(); // Item title
            $feedArray['feed']['entry'][$i]['URL'] = $feedObject->items[$i]->getUrl(); // Item url
            $feedArray['feed']['entry'][$i]['published'] = $this->convertDateTimeToString($feedObject->items[$i]->getPublishedDate(), 'c');
            $feedArray['feed']['entry'][$i]['updated'] = $this->convertDateTimeToString($feedObject->items[$i]->getUpdatedDate(), 'c');
            $feedArray['feed']['entry'][$i]['author'] = $feedObject->items[$i]->getAuthor(); // Item author
            $feedArray['feed']['entry'][$i]['enclosure']['url'] = $feedObject->items[$i]->getEnclosureUrl(); // Enclosure url
            $feedArray['feed']['entry'][$i]['enclosure']['type'] = $feedObject->items[$i]->getEnclosureType(); // Enclosure mime-type (audio/mp3, image/png...)
            $feedArray['feed']['entry'][$i]['content'] = $feedObject->items[$i]->getContent(); // Item content (filtered or raw)
            $feedArray['feed']['entry'][$i]['pubDate'] = $this->convertDateTimeToString($feedObject->items[$i]->getPublishedDate(), $this->dateFormat);
            //TODO where is the item description?
            //Item description is optional for RSS2.0 only if the item title exists but required if it doesn't.
            //Item description does not exist in Atom spec; an optional item summary presumably takes its place.
        }
        return $feedArray;
    }

    function changeDataEncoding($input, $outputEncoding) {
        if (function_exists('mb_detect_encoding')) {
            if (is_array($input)) {
                $encoding = mb_detect_encoding(print_r($input, true));
            } else if (is_string($input)) {
                $encoding = mb_detect_encoding($input);
            }
            switch ($encoding) {
                case 'ASCII':
                case $outputEncoding:
                    return $input;
                case '':
                    return mb_convert_encoding($input, $outputEncoding);
                default:
                    return mb_convert_encoding($input, $outputEncoding, $encoding);
            }
        } else {
            return $input;
        }
    }

    function getParsedData($outputEncoding = 'UTF-8') {
        // return data as array in specified encoding
        return $this->changeDataEncoding($this->feedData, $outputEncoding);
    }

    private function convertDateTimeToString($dateTimeObject, $dateFormat = 'M d, H:i') {
        $dateString = '';
        if ($dateFormat !== '' && isset($dateTimeObject) && $dateTimeObject instanceof DateTime) {
            $dateTimeObject->setTimezone(new DateTimeZone($this->timeZone));
            $dateString = date_format($dateTimeObject, $dateFormat);
        }
        return $dateString;
    }

}
