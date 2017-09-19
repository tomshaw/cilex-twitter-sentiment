<?php

/**
 * A class that makes it easy to connect to and consume the Twitter stream via the Streaming API.
 *
 * Note: This is beta software - Please read the following carefully before using:
 *  - http://code.google.com/p/phirehose/wiki/Introduction
 *  - http://dev.twitter.com/pages/streaming_api
 * @author  Fenn Bailey <fenn.bailey@gmail.com>
 * @version 1.0RC
 */

namespace Cilex\Service;

use Cilex\Event\EventManager;

class Twitter
{

    const FORMAT_JSON = 'json';
    const FORMAT_XML = 'xml';
    
    const METHOD_FILTER = 'filter';
    const METHOD_SAMPLE = 'sample';
    const METHOD_RETWEET = 'retweet';
    const METHOD_FIREHOSE = 'firehose';
    const METHOD_LINKS = 'links';
    const METHOD_USER = 'user';
    const METHOD_SITE = 'site';
    
    const EARTH_RADIUS_KM = 6371;

    protected $URL_BASE = 'https://stream.twitter.com/1.1/statuses/';
    protected $username;
    protected $password;
    protected $method;
    protected $format = self::FORMAT_JSON;
    protected $lang = 'en';
    protected $count;
    protected $followIds;
    protected $trackWords;
    protected $locationBoxes;
    protected $conn;
    protected $fdrPool;
    protected $buff;
    protected $filterChanged;
    protected $reconnect;
    protected $statusRate;
    protected $lastErrorNo;
    protected $lastErrorMsg;
    protected $statusCount = 0;
    protected $filterCheckCount = 0;
    protected $enqueueSpent = 0;
    protected $filterCheckSpent = 0;
    protected $idlePeriod = 0;
    protected $maxIdlePeriod = 0;
    protected $enqueueTimeMS = 0;
    protected $filterCheckTimeMS = 0;
    protected $avgElapsed = 0;
    protected $connectFailuresMax = 20;
    protected $connectTimeout = 5;
    protected $readTimeout = 5;
    protected $idleReconnectTimeout = 90;
    protected $avgPeriod = 60;
    protected $status_length_base = 10;
    protected $userAgent = 'Phirehose/1.0RC +https://github.com/fennb/phirehose';
    protected $filterCheckMin = 5;
    protected $filterUpdMin = 120;
    protected $tcpBackoff = 1;
    protected $tcpBackoffMax = 16;
    protected $httpBackoff = 10;
    protected $httpBackoffMax = 240;
    protected $hostPort = 80;
    protected $secureHostPort = 443;
    protected $auth_method;
    protected $consumerKey = null;
    protected $consumerSecret = null;
    protected $response;
    
    protected $eventManager;

    public function __construct($username, $password, $consumerKey, $consumerSecret)
    {
        $this->username = $username;
        $this->password = $password;
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
    }

    public function setFormat($format = self::FORMAT_JSON)
    {
        $this->format = $format;
        return $this;
    }

    public function setMethod($method)
    {
        $this->method = $method;
        
        switch ($method) {
            case self::METHOD_USER:
                $this->URL_BASE = 'https://userstream.twitter.com/1.1/';
                break;
            case self::METHOD_SITE:
                $this->URL_BASE = 'https://sitestream.twitter.com/1.1/';
                break;
            default:
                break;
        }
        return $this;
    }

    public function setFollow($userIds)
    {
        $userIds = ($userIds === null) ? [] : $userIds;
        sort($userIds);
        if ($this->followIds != $userIds) {
            $this->filterChanged = true;
        }
        $this->followIds = $userIds;
    }

    public function getFollow()
    {
        return $this->followIds;
    }

    public function setTrack(array $trackWords)
    {
        $trackWords = ($trackWords === null) ? [] : $trackWords;
        sort($trackWords);
        if ($this->trackWords != $trackWords) {
            $this->filterChanged = true;
        }
        $this->trackWords = $trackWords;
    }

    public function getTrack()
    {
        return $this->trackWords;
    }

    public function setLocations($boundingBoxes)
    {
        $boundingBoxes = ($boundingBoxes === null) ? [] : $boundingBoxes;
        sort($boundingBoxes);
        $locationBoxes = [];
        foreach ($boundingBoxes as $boundingBox) {
            if (count($boundingBox) != 4) {
                return false;
            }
            $locationBoxes = array_merge($locationBoxes, $boundingBox);
        }
        if ($this->locationBoxes != $locationBoxes) {
            $this->filterChanged = true;
        }
        $this->locationBoxes = $locationBoxes;
    }

    public function getLocations()
    {
        if ($this->locationBoxes == null) {
            return null;
        }
        $locationBoxes = $this->locationBoxes;
        $ret = [];
        while (count($locationBoxes) >= 4) {
            $ret[] = array_splice($locationBoxes, 0, 4);
        }
        return $ret;
    }

    public function setLocationsByCircle($locations)
    {
        $boundingBoxes = [];
        foreach ($locations as $locTriplet) {
            if (count($locTriplet) != 3) {
                return false;
            }
            list ($lon, $lat, $radius) = $locTriplet;
            
            $maxLat = round($lat + rad2deg($radius / self::EARTH_RADIUS_KM), 2);
            $minLat = round($lat - rad2deg($radius / self::EARTH_RADIUS_KM), 2);
            $maxLon = round($lon + rad2deg($radius / self::EARTH_RADIUS_KM / cos(deg2rad($lat))), 2);
            $minLon = round($lon - rad2deg($radius / self::EARTH_RADIUS_KM / cos(deg2rad($lat))), 2);
            $boundingBoxes[] = [
                $minLon,
                $minLat,
                $maxLon,
                $maxLat
            ];
        }
        $this->setLocations($boundingBoxes);
    }

    public function setCount($count)
    {
        $this->count = $count;
    }

    public function setLang($lang)
    {
        $this->lang = $lang;
    }

    public function getLang()
    {
        return $this->lang;
    }

    public function consume($reconnect = true)
    {
        $this->reconnect = $reconnect;
        
        do {
            
            $this->reconnect();
            
            $lastAverage = $lastFilterCheck = $lastFilterUpd = $lastStreamActivity = time();
            $fdw = $fde = null;
            while ($this->conn !== null && !feof($this->conn) && ($numChanged = stream_select($this->fdrPool, $fdw, $fde, $this->readTimeout)) !== false) {
                if ((time() - $lastStreamActivity) > $this->idleReconnectTimeout) {
                    $this->reconnect();
                    $lastStreamActivity = time();
                    continue;
                }
                $this->fdrPool = [
                    $this->conn
                ];
                $chunk_info = trim(fgets($this->conn));
                if ($chunk_info == '') {
                    continue;
                }
                $this->idlePeriod = (time() - $lastStreamActivity);
                $this->maxIdlePeriod = ($this->idlePeriod > $this->maxIdlePeriod) ? $this->idlePeriod : $this->maxIdlePeriod;
                $lastStreamActivity = time();
                
                $len = hexdec($chunk_info);
                $s = '';
                $len += 2;
                while (!feof($this->conn)) {
                    $s .= fread($this->conn, $len - strlen($s));
                    if (strlen($s) >= $len) {
                        break;
                    }
                }
                $this->buff .= substr($s, 0, - 2);
                
                while (1) {
                    $eol = strpos($this->buff, "\r\n");
                    if ($eol === 0) {
                        $this->buff = substr($this->buff, $eol + 2);
                        continue;
                    }
                    if ($eol === false) {
                        break;
                    }
                    $enqueueStart = microtime(true);
                    $this->enqueueStatus(substr($this->buff, 0, $eol));
                    $this->enqueueSpent += (microtime(true) - $enqueueStart);
                    $this->statusCount ++;
                    $this->buff = substr($this->buff, $eol + 2);
                }
                
                $this->avgElapsed = time() - $lastAverage;
                if ($this->avgElapsed >= $this->avgPeriod) {
                    $this->statusRate = round($this->statusCount / $this->avgElapsed, 0);
                    $this->enqueueTimeMS = ($this->statusCount > 0) ? round($this->enqueueSpent / $this->statusCount * 1000, 2) : 0;
                    $this->filterCheckTimeMS = ($this->filterCheckCount > 0) ? round($this->filterCheckSpent / $this->filterCheckCount * 1000, 2) : 0;
                    
                    $this->heartbeat();
                    $this->statusUpdate();
                    $lastAverage = time();
                }
                if ($this->method == self::METHOD_FILTER && (time() - $lastFilterCheck) >= $this->filterCheckMin) {
                    $this->filterCheckCount ++;
                    $lastFilterCheck = time();
                    $filterCheckStart = microtime(true);
                    $this->checkFilterPredicates();
                    $this->filterCheckSpent += (microtime(true) - $filterCheckStart);
                }
                if ($this->filterChanged == true && (time() - $lastFilterUpd) >= $this->filterUpdMin) {
                    $this->reconnect();
                    $lastFilterUpd = time();
                }
            }
            
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            
            $this->lastErrorNo = is_resource($this->conn) ? @socket_last_error($this->conn) : null;
            $this->lastErrorMsg = ($this->lastErrorNo > 0) ? @socket_strerror($this->lastErrorNo) : 'Socket disconnected';
            
        } while ($this->reconnect);
    }

    protected function statusUpdate()
    {
        $this->statusCount = $this->filterCheckCount = $this->enqueueSpent = 0;
        $this->filterCheckSpent = $this->idlePeriod = $this->maxIdlePeriod = 0;
    }

    public function getLastErrorMsg()
    {
        return $this->lastErrorMsg;
    }

    public function getLastErrorNo()
    {
        return $this->lastErrorNo;
    }

    protected function connect()
    {
        $connectFailures = 0;
        $tcpRetry = $this->tcpBackoff / 2;
        $httpRetry = $this->httpBackoff / 2;
        
        do {
            
            if ($this->method == self::METHOD_FILTER) {
                $this->checkFilterPredicates();
            }
            
            $url = $this->URL_BASE . $this->method . '.' . $this->format;
            $urlParts = parse_url($url);
            
            $requestParams = [];
            
            if ($this->lang) {
                $requestParams['language'] = $this->lang;
            }
            
            if (($this->method == self::METHOD_FILTER || $this->method == self::METHOD_USER) && count($this->trackWords) > 0) {
                $requestParams['track'] = implode(',', $this->trackWords);
            }
            if (($this->method == self::METHOD_FILTER || $this->method == self::METHOD_SITE) && count($this->followIds) > 0) {
                $requestParams['follow'] = implode(',', $this->followIds);
            }
            if ($this->method == self::METHOD_FILTER && count($this->locationBoxes) > 0) {
                $requestParams['locations'] = implode(',', $this->locationBoxes);
            }
            if ($this->count != 0) {
                $requestParams['count'] = $this->count;
            }
            
            $errNo = $errStr = null;
            $scheme = ($urlParts['scheme'] == 'https') ? 'ssl://' : 'tcp://';
            $port = ($urlParts['scheme'] == 'https') ? $this->secureHostPort : $this->hostPort;
            
            @$this->conn = fsockopen($scheme . $urlParts['host'], $port, $errNo, $errStr, $this->connectTimeout);
            
            if (!$this->conn || !is_resource($this->conn)) {
                $this->lastErrorMsg = $errStr;
                $this->lastErrorNo = $errNo;
                $connectFailures ++;
                if ($connectFailures > $this->connectFailuresMax) {
                    $msg = 'TCP failure limit exceeded with ' . $connectFailures . ' failures. Last error: ' . $errStr;
                    throw new PhirehoseConnectLimitExceeded($msg, $errNo); // Throw an exception for other code to handle
                }
                $tcpRetry = ($tcpRetry < $this->tcpBackoffMax) ? $tcpRetry * 2 : $this->tcpBackoffMax;
                sleep($tcpRetry);
                continue;
            }
            
            $this->lastErrorMsg = null;
            $this->lastErrorNo = null;
            
            stream_set_blocking($this->conn, 1);
            
            $postData = http_build_query($requestParams, null, '&');
            $postData = str_replace('+', '%20', $postData);
            
            $authCredentials = $this->getAuthorizationHeader($url, $requestParams);
            
            $s = "POST " . $urlParts['path'] . " HTTP/1.1\r\n";
            $s .= "Host: " . $urlParts['host'] . ':' . $port . "\r\n";
            $s .= "Connection: Close\r\n";
            $s .= "Content-type: application/x-www-form-urlencoded\r\n";
            $s .= "Content-length: " . strlen($postData) . "\r\n";
            $s .= "Accept: */*\r\n";
            $s .= 'Authorization: ' . $authCredentials . "\r\n";
            $s .= 'User-Agent: ' . $this->userAgent . "\r\n";
            $s .= "\r\n";
            $s .= $postData . "\r\n";
            $s .= "\r\n";
            
            fwrite($this->conn, $s);
            
            list ($httpVer, $httpCode, $httpMessage) = preg_split('/\s+/', trim(fgets($this->conn, 1024)), 3);
            
            $respHeaders = $respBody = '';
            $isChunking = false;
            
            while (false != ($hLine = trim(fgets($this->conn, 4096)))) {
                $respHeaders .= $hLine . "\n";
                if (strtolower($hLine) == 'transfer-encoding: chunked') {
                    $isChunking = true;
                }
            }
            
            if ($httpCode != 200) {
                $connectFailures ++;
                
                while (false != ($bLine = trim(fgets($this->conn, 4096)))) {
                    $respBody .= $bLine;
                }
                
                $errStr = 'HTTP ERROR ' . $httpCode . ': ' . $httpMessage . ' (' . $respBody . ')';
                
                $this->lastErrorMsg = $errStr;
                $this->lastErrorNo = $httpCode;
                
                if ($connectFailures > $this->connectFailuresMax) {
                    $msg = 'Connection failure limit exceeded with ' . $connectFailures . ' failures. Last error: ' . $errStr;
                    throw new PhirehoseConnectLimitExceeded($msg, $httpCode);
                }
                
                $httpRetry = ($httpRetry < $this->httpBackoffMax) ? $httpRetry * 2 : $this->httpBackoffMax;
                sleep($httpRetry);
                continue;
            }
        } while (!is_resource($this->conn) || $httpCode != 200);
        
        $connectFailures = 0;
        $this->lastErrorMsg = null;
        $this->lastErrorNo = null;
        
        stream_set_blocking($this->conn, 0);
        
        $this->filterChanged = false;
        $this->fdrPool = [
            $this->conn
        ];
        $this->buff = '';
    }

    protected function checkFilterPredicates()
    {
    }

    protected function disconnect()
    {
        if (is_resource($this->conn)) {
            fclose($this->conn);
        }
        $this->conn = null;
        $this->reconnect = false;
    }

    private function reconnect()
    {
        $reconnect = $this->reconnect;
        $this->disconnect(); 
        $this->reconnect = $reconnect;
        $this->connect();
    }

    public function enqueueStatus($status)
    {        
        $data = json_decode($status, true);
        EventManager::getInstance()->trigger('TweetStream', $data);
    }

    public function heartbeat()
    {}

    public function setHostPort($port)
    {
        $this->hostPort = $port;
    }

    public function setSecureHostPort($port)
    {
        $this->secureHostPort = $port;
    }

    /*
     * oAuth
     */
    protected function prepareParameters($method = null, $url = null, array $params)
    {
        if (empty($method) || empty($url)) {
            return false;
        }
        
        $oauth = [];
        $oauth['oauth_consumer_key'] = $this->consumerKey;
        $oauth['oauth_nonce'] = md5(uniqid(rand(), true));
        $oauth['oauth_signature_method'] = 'HMAC-SHA1';
        $oauth['oauth_timestamp'] = time();
        $oauth['oauth_version'] = '1.0A';
        $oauth['oauth_token'] = $this->username;
        if (isset($params['oauth_verifier'])) {
            $oauth['oauth_verifier'] = $params['oauth_verifier'];
            unset($params['oauth_verifier']);
        }
        
        foreach ($oauth as $k => $v) {
            $oauth[$k] = $this->encode_rfc3986($v);
        }
        
        $sigParams = [];
        $hasFile = false;
        if (is_array($params)) {
            foreach ($params as $k => $v) {
                if (strncmp('@', $k, 1) !== 0) {
                    $sigParams[$k] = $this->encode_rfc3986($v);
                    $params[$k] = $this->encode_rfc3986($v);
                } else {
                    $params[substr($k, 1)] = $v;
                    unset($params[$k]);
                    $hasFile = true;
                }
            }
            
            if ($hasFile === true) {
                $sigParams = [];
            }
        }
        
        $sigParams = array_merge($oauth, (array) $sigParams);
        
        ksort($sigParams);
        
        $oauth['oauth_signature'] = $this->encode_rfc3986($this->generateSignature($method, $url, $sigParams));
        
        return [
            'request' => $params,
            'oauth' => $oauth
        ];
    }

    protected function encode_rfc3986($string)
    {
        return str_replace('+', ' ', str_replace('%7E', '~', rawurlencode(($string))));
    }

    protected function generateSignature($method = null, $url = null, $params = null)
    {
        if (empty($method) || empty($url)) {
            return false;
        }
        
        $concat = '';
        foreach ((array) $params as $key => $value) {
            $concat .= "{$key}={$value}&";
        }
        
        $concat = substr($concat, 0, - 1);
        $concatenatedParams = $this->encode_rfc3986($concat);
        
        $urlParts = parse_url($url);
        $scheme = strtolower($urlParts['scheme']);
        $host = strtolower($urlParts['host']);
        $port = isset($urlParts['port']) ? intval($urlParts['port']) : 0;
        $retval = strtolower($scheme) . '://' . strtolower($host);
        if (!empty($port) && (($scheme === 'http' && $port != 80) || ($scheme === 'https' && $port != 443))) {
            $retval .= ":{$port}";
        }
        
        $retval .= $urlParts['path'];
        if (!empty($urlParts['query'])) {
            $retval .= "?{$urlParts['query']}";
        }
        
        $normalizedUrl = $this->encode_rfc3986($retval);
        $method = $this->encode_rfc3986($method);
        
        $signatureBaseString = "{$method}&{$normalizedUrl}&{$concatenatedParams}";
        
        $key = $this->encode_rfc3986($this->consumerSecret) . '&' . $this->encode_rfc3986($this->password);
        return base64_encode(hash_hmac('sha1', $signatureBaseString, $key, true));
    }

    protected function getOAuthHeader($method, $url, $params = [])
    {
        $params = $this->prepareParameters($method, $url, $params);
        $oauthHeaders = $params['oauth'];
        
        //$urlParts = parse_url($url);
        $oauth = 'OAuth realm="",';
        foreach ($oauthHeaders as $name => $value) {
            $oauth .= "{$name}=\"{$value}\",";
        }
        $oauth = substr($oauth, 0, - 1);
        
        return $oauth;
    }

    protected function getAuthorizationHeader($url, $requestParams)
    {
        return $this->getOAuthHeader('POST', $url, $requestParams);
    }
}

class PhirehoseException extends \Exception
{
}

class PhirehoseNetworkException extends PhirehoseException
{
}

class PhirehoseConnectLimitExceeded extends PhirehoseException
{
}
