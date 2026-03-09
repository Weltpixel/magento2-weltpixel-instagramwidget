<?php
namespace WeltPixel\InstagramWidget\Cron;

use WeltPixel\InstagramWidget\Model\InstagramWidgetCache;
use Magento\Framework\App\Config\ScopeConfigInterface;

class CleanCache
{
    /**
     * @var InstagramWidgetCache
     */
    protected $instagramWidgetCache;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param InstagramWidgetCache $instagramWidgetCache
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        InstagramWidgetCache $instagramWidgetCache,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->instagramWidgetCache = $instagramWidgetCache;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Execute cron job
     *
     * @return void
     */
    public function execute()
    {
        $isEnabled = $this->scopeConfig->getValue(
            'weltpixel_instagram/cron_settings/enabled',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        if ($isEnabled) {
            $this->instagramWidgetCache->cleanInstagramCacheTable();
        }
    }
}
