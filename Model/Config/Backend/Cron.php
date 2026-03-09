<?php
namespace WeltPixel\InstagramWidget\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Config\ValueFactory;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use WeltPixel\InstagramWidget\Model\Config\Source\Frequency as FrequencySource;

class Cron extends Value
{
    /**
     * @var ValueFactory
     */
    protected $_configValueFactory;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param ValueFactory $configValueFactory
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        ValueFactory $configValueFactory,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->_configValueFactory = $configValueFactory;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return Value
     */
    public function afterSave()
    {
        $time = $this->getData('groups/cron_settings/fields/time/value');
        $frequency = $this->getData('groups/cron_settings/fields/frequency/value');

        $cronExprArray = [
            intval($time[1]), // Minute
            $this->getHourPart($frequency, intval($time[0])), // Hour
            $frequency == FrequencySource::CRON_MONTHLY ? '1' : '*', // Day of the Month
            '*', // Month of the Year
            $frequency == FrequencySource::CRON_WEEKLY ? '1' : '*', // Day of the Week
        ];

        $cronExprString = join(' ', $cronExprArray);

        try {
            $this->_configValueFactory->create()->load(
                'crontab/default/jobs/weltpixel_instagram_cache_cleanup/schedule/cron_expr',
                'path'
            )->setValue(
                $cronExprString
            )->setPath(
                'crontab/default/jobs/weltpixel_instagram_cache_cleanup/schedule/cron_expr'
            )->save();
        } catch (\Exception $e) {
            throw new \Exception(__('We can\'t save the cron expression.'));
        }

        return parent::afterSave();
    }

    /**
     * Get hour part of cron expression
     *
     * @param string $frequency
     * @param int $hour
     * @return string
     */
    private function getHourPart($frequency, $hour)
    {
        switch ($frequency) {
            case FrequencySource::CRON_HOURLY:
                return '*';
            default:
                return (string)$hour;
        }
    }
}
