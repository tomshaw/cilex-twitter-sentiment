<?php
/**
 * @see https://blog.cotten.io/how-to-screw-up-singletons-in-php-3e8c83b63189
 */

/*EventManager::getInstance()->attach('TweetStream', function ($e) {
 dump($e->getName());
 dump($e->getParams());
 });*/

namespace Cilex\Event;

class EventManager
{

    public static $instance = null;

    private $events = [];
    
    protected $eventPrototype;
    
    protected function __construct()
    {
        $this->eventPrototype = new Event();
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    public function trigger($name, $data = [])
    {
        
        $ev = clone $this->eventPrototype;
        $ev->setName($name);
        $ev->setParams($data);
        
        if ($this->hasEvent($name)) {
            foreach ($this->getEvent($name) as $event) {
                call_user_func($event['callback'], $ev);
            }
        }
        
        return $this;
    }

    public function attach($name, callable $callback, $priority = 1)
    {
        if (!isset($this->events[$name])) {
            $this->events[$name] = [];
        }
        
        $event = [
            'name' => (string) $name,
            'callback' => $callback,
            'priority' => (int) $priority
        ];
        
        array_push($this->events[$name], $event);
        
        if (count($this->events[$name]) > 1) {
            usort($this->events[$name], [
                $this,
                'priority'
            ]);
        }
    }

    public function detach($name)
    {
        if (isset($this->events[$name])) {
            unset($this->events[$name]);
        }
    }

    public function getEvents()
    {
        return $this->events;
    }
    
    public function hasEvent($name)
    {
        if (isset($this->events[$name])) {
            return $this->events[$name];
        }
        return false;
    }

    public function getEvent($name)
    {
        if ($this->hasEvent($name)) {
            return $this->events[$name];
        }
        return false;
    }

    protected function priority($a, $b)
    {
        if ($a['priority'] == $b['priority']) {
            return 0;
        }
        
        return ($a['priority'] < $b['priority']) ? - 1 : 1;
    }
}
