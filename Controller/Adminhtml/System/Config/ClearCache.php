<?php
namespace WeltPixel\InstagramWidget\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use WeltPixel\InstagramWidget\Model\InstagramWidgetCache;
use Magento\Framework\Controller\Result\JsonFactory;

class ClearCache extends Action
{
    /**
     * @var InstagramWidgetCache
     */
    protected $instagramWidgetCache;

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @param Context $context
     * @param InstagramWidgetCache $instagramWidgetCache
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(
        Context $context,
        InstagramWidgetCache $instagramWidgetCache,
        JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
        $this->instagramWidgetCache = $instagramWidgetCache;
        $this->resultJsonFactory = $resultJsonFactory;
    }

    /**
     * Check admin permissions for this controller
     *
     * @return boolean
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('WeltPixel_Instagram::InstagramSettings');
    }

    /**
     * Clear Instagram cache
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        try {
            $this->instagramWidgetCache->cleanInstagramCacheTable();
            $response = [
                'status' => true,
                'message' => __('The Instagram cache has been cleared successfully.')
            ];
        } catch (\Exception $e) {
            $response = [
                'status' => false,
                'message' => __('An error occurred while clearing the Instagram cache: %1', $e->getMessage())
            ];
        }

        $resultJson = $this->resultJsonFactory->create();
        return $resultJson->setData($response);
    }
}
