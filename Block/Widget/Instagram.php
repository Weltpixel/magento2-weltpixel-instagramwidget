<?php
namespace WeltPixel\InstagramWidget\Block\Widget;

class Instagram extends \Magento\Framework\View\Element\Template implements \Magento\Widget\Block\BlockInterface
{

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    protected $_serializer;

    /**
     * @var \WeltPixel\InstagramWidget\Model\TokenResolver
     */
    protected $_tokenResolver;

    /**
     * Instagram constructor.
     * @param \Magento\Framework\Serialize\Serializer\Json $_serializer
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \WeltPixel\InstagramWidget\Model\TokenResolver|null $tokenResolver
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Serialize\Serializer\Json $_serializer,
        \Magento\Framework\View\Element\Template\Context $context,
        \WeltPixel\InstagramWidget\Model\TokenResolver $tokenResolver,
        array $data = []
    )
    {
        $this->_serializer = $_serializer;
        $this->_tokenResolver = $tokenResolver;
        parent::__construct($context, $data);
    }

    /**
     * @return string
     */
    public function getTemplate()
    {
        $instagramApiType = $this->getData('instagram_api_type');
        switch ($instagramApiType) {
            case 'basic_api':
                $template = 'widget/basic/instagram_widget.phtml';
                break;
            case 'instagram_api':
                $template = 'widget/instagram_api/instagram_widget.phtml';
                break;
            default:
                $template = 'widget/instagram_api/instagram_widget.phtml';
                break;
        }

        $this->setTemplate($template);
        return parent::getTemplate();
    }

    /**
     * @param int $storeId
     * @return mixed
     */
    public function isLazyLoadEnabled() {
        return $this->_scopeConfig->getValue('weltpixel_lazy_loading/general/enable', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * @param int $storeId
     * @return mixed|string
     */
    public function getLazyLoadPlaceholderWidth() {
        $imgWidth = null;
        $imgWidth = (int) $this->_scopeConfig->getValue('weltpixel_lazy_loading/advanced/placeholder_width', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

        return $imgWidth && is_integer($imgWidth) ? $imgWidth . 'px' : 'auto';
    }

    public function getInstagramToken() {
        $token = $this->getData('token');
        if ($this->getData('use_predefined_token')) {
            $tokenId = $this->getData('predefined_token');
            $tokenOptions = $this->_scopeConfig->getValue(\WeltPixel\InstagramWidget\Block\Adminhtml\Form\Field\InstagramToken::TOKEN_PATH);
            if (isset($tokenOptions)) {
                $tokens = $this->_serializer->unserialize($tokenOptions);
                foreach ($tokens as $tokenOpt) {
                    if ($tokenId == preg_replace('/[^A-Za-z0-9\-]/', '', $tokenOpt['token_name'])) {
                        $token = $tokenOpt['token_value'];
                    }
                }
            }
        }

        return $token;
    }

    /**
     * A reference to the access token, for the markup to publish instead of the token itself.
     *
     * Falls back to the token when no reference can be produced, which keeps widgets working
     * that hold a token directly rather than choosing one configured in the module settings.
     * A reference is only returned once it has been confirmed to resolve back to a token, so
     * this can never publish a reference the controller would fail to understand.
     *
     * @return string
     */
    public function getInstagramTokenRef()
    {
        if ($this->getData('use_predefined_token')) {
            $name = $this->_tokenResolver->sanitizeName($this->getData('predefined_token'));
            if ($this->_tokenResolver->resolveByName($name)) {
                return $this->_tokenResolver->makeRef($name);
            }
        }

        $name = $this->_tokenResolver->findNameForValue($this->getData('token'));
        if ($name !== null) {
            return $this->_tokenResolver->makeRef($name);
        }

        return (string)$this->getInstagramToken();
    }
}
