<?php

namespace Cilex\Event;

use ArrayAccess;

class Event
{
    protected $name;
    protected $params = [];

    public function __construct($name = null, $params = null)
    {
        if (null !== $name) {
            $this->setName($name);
        }

        if (null !== $params) {
            $this->setParams($params);
        }
    }

    public function getName()
    {
        return $this->name;
    }

    public function setParams($params)
    {
        $this->params = $params;
    }

    public function getParams()
    {
        return $this->params;
    }

    public function getParam($name, $default = null)
    {
        if (is_array($this->params) || $this->params instanceof ArrayAccess) {
            if (!isset($this->params[$name])) {
                return $default;
            }
            return $this->params[$name];
        }
        if (!isset($this->params->{$name})) {
            return $default;
        }
        return $this->params->{$name};
    }

    public function setName($name)
    {
        $this->name = (string) $name;
    }

    public function setParam($name, $value)
    {
        if (is_array($this->params) || $this->params instanceof ArrayAccess) {
            $this->params[$name] = $value;
            return;
        }
        $this->params->{$name} = $value;
    }
}
