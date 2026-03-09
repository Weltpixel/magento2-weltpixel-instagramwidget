<?php

namespace WeltPixel\InstagramWidget\Observer;

use Magento\Framework\Event\ObserverInterface;

/**
 * InstagramWidgetEditActionControllerSaveObserver observer
 */
class InstagramWidgetEditActionControllerSaveObserver implements ObserverInterface
{

    /**
     * @var \WeltPixel\InstagramWidget\Model\InstagramWidgetCache
     */
    protected $widgetCache;

    /**
     * Constructor
     *
     * @param \WeltPixel\InstagramWidget\Model\InstagramWidgetCache $widgetCache
     */
    public function __construct(
        \WeltPixel\InstagramWidget\Model\InstagramWidgetCache $widgetCache
    ) {
        $this->widgetCache = $widgetCache;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @return $this|void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $event = $observer->getEvent();
        if ($event instanceof \Magento\Framework\Event) {
            $eventName = $observer->getEvent()->getData();
            if ($eventName) {
                $this->widgetCache->cleanInstagramCacheTable();
            }
        }

        return $this;
    }
}
