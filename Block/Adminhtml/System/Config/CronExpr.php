<?php
namespace WeltPixel\InstagramWidget\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class CronExpr extends Field
{
    /**
     * @var string
     */
    protected $_template = 'WeltPixel_InstagramWidget::system/config/cron_expr.phtml';

    /**
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Remove scope label
     *
     * @param  AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Return element html
     *
     * @param  AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * Get current cron expression
     *
     * @return string
     */
    public function getCronExpr()
    {
        return $this->_scopeConfig->getValue(
            'crontab/default/jobs/weltpixel_instagram_cache_cleanup/schedule/cron_expr',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ) ?: __('Not configured');
    }
} 