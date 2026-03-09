<?php
namespace WeltPixel\InstagramWidget\Model\Config\Source;

class Frequency implements \Magento\Framework\Option\ArrayInterface
{
    const CRON_HOURLY = 'H';
    const CRON_DAILY = 'D';
    const CRON_WEEKLY = 'W';
    const CRON_MONTHLY = 'M';

    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::CRON_HOURLY, 'label' => __('Hourly')],
            ['value' => self::CRON_DAILY, 'label' => __('Daily')],
            ['value' => self::CRON_WEEKLY, 'label' => __('Weekly')],
            ['value' => self::CRON_MONTHLY, 'label' => __('Monthly')]
        ];
    }
}
