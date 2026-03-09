<?php
namespace WeltPixel\InstagramWidget\Model\Config\Source;

class ApiType implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Options getter
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'instagram_api', 'label' => __('Instagram API')],
            ['value' => 'basic_api', 'label' => __('Basic API')]
        ];
    }

    /**
     * Get options in "key-value" format
     * @return array
     */
    public function toArray()
    {
        return [
            'instagram_api' => __('Instagram API'),
            'basic_api' => __('Basic API'),
        ];
    }
}
