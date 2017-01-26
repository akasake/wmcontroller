<?php

namespace Drupal\wmcontroller\EventSubscriber;

use Drupal\wmcontroller\Exception\NoSuchCacheEntryException;
use Drupal\wmcontroller\Entity\Cache;
use Drupal\wmcontroller\Http\CachedResponse;
use Drupal\wmcontroller\Service\Cache\Manager;
use Drupal\wmcontroller\Event\EntityPresentedEvent;
use Drupal\wmcontroller\WmcontrollerEvents;
use Drupal\wmcontroller\Event\CachePurgeEvent;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;

class CacheSubscriber implements EventSubscriberInterface
{
    /** @var Manager */
    protected $manager;

    protected $expiries;

    protected $store;

    protected $tags;

    protected $presentedEntityTags = [];

    public function __construct(
        Manager $manager,
        array $expiries,
        $store = false,
        $tags = false
    ) {
        $this->manager = $manager;
        $this->expiries = $expiries;
        $this->store = $store;
        $this->tags = $tags;
    }

    public static function getSubscribedEvents()
    {
        $events[KernelEvents::REQUEST][] = ['onCachedResponse', 10000];
        $events[KernelEvents::RESPONSE][] = ['onResponse', -255];
        $events[KernelEvents::TERMINATE][] = ['onTerminate', 0];
        $events[WmcontrollerEvents::ENTITY_PRESENTED][] = ['onEntityPresented', 0];

        return $events;
    }

    public function onCachedResponse(GetResponseEvent $event)
    {
        if (!$this->store || !$this->tags) {
            return;
        }

        $request = $event->getRequest();
        if ($this->ignore($request)) {
            return;
        }

        try {
            $event->setResponse(
                $this->getCache($request)->toResponse()
            );
        } catch (NoSuchCacheEntryException $e) {
        }
    }

    public function onResponse(FilterResponseEvent $event)
    {
        $request = $event->getRequest();
        if ($this->ignore($request)) {
            return;
        }

        $response = $event->getResponse();
        if (
            empty($this->expiries)
            || $response->headers->hasCacheControlDirective('s-maxage')
        ) {
            return;
        }

        $path = $request->getPathInfo();
        foreach ($this->expiries as $re => $definition) {
            // # should be safe... I guess
            if (!preg_match('#' . $re . '#', $path)) {
                continue;
            }

            if (!empty($definition['s-maxage'])) {
                $response->setSharedMaxAge($definition['s-maxage']);
            }

            if (!empty($definition['maxage'])) {
                $response->setMaxAge($definition['maxage']);
            }

            return;
        }
    }

    public function onEntityPresented(EntityPresentedEvent $event)
    {
        $entity = $event->getEntity();
        $this->presentedEntityTags[] = sprintf(
            '%s:%d',
            $entity->getEntityTypeId(),
            $entity->id()
        );
    }

    public function onTerminate(PostResponseEvent $event)
    {
        if (!$this->tags) {
            return;
        }

        $request = $event->getRequest();
        if ($this->ignore($request)) {
            return;
        }

        $response = $event->getResponse();

        if (
            $response instanceof CachedResponse
            || !$response->isCacheable()
        ) {
            return;
        }

        // TODO find a way to handle exceptions thrown from this point on.
        // (we're in the kernel::TERMINATE phase)
        $this->manager->set(
            new Cache(
                $request->getUri(),
                $request->getMethod(),
                $this->store ? $response->getContent() : '',
                $this->store ? $response->headers->all() : [],
                time() + $response->getMaxAge() // returns s-maxage if set.
            ),
            $this->presentedEntityTags
        );
    }

    protected function ignore(Request $request)
    {
        return $request->hasSession()
            && $request->getSession()->get('uid') != 0;
    }

    /**
     * @return Cache The cached entry.
     *
     * @throws NoSuchCacheEntryException.
     */
    protected function getCache(Request $request)
    {
        return $this->manager->get(
            $request->getUri(),
            $request->getMethod()
        );
    }
}
