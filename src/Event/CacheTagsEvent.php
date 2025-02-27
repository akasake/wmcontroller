<?php

namespace Drupal\wmcontroller\Event;

use Symfony\Component\EventDispatcher\Event;

class CacheTagsEvent extends Event
{
    protected $tags;

    public function __construct(array $tags)
    {
        $this->tags = $tags;
    }

    /**
     * @return string[]
     */
    public function getCacheTags()
    {
        return $this->tags;
    }
}

