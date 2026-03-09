<?php

namespace WeltPixel\InstagramWidget\Controller\Fetch;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use WeltPixel\InstagramWidget\Model\InstagramWidgetCache;

class Images extends Action
{
    /**
     * @var InstagramWidgetCache
     */
    protected $instagramWidgetCache;

    /**
     * Content constructor.
     * @param Context $context
     * @param InstagramWidgetCache $instagramWidgetCache
     */
    public function __construct(
        Context $context,
        InstagramWidgetCache $instagramWidgetCache
    ) {
        $this->instagramWidgetCache = $instagramWidgetCache;
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        $instaFetchUrl = $this->getRequest()->getParam('instaFetchUrl');

        if (!$instaFetchUrl) {
            return $this->prepareResult([]);
        }

        $urlQueryStrings = parse_url($instaFetchUrl);
        parse_str($urlQueryStrings['query'], $urlQueryParams);

        $accessToken = $urlQueryParams['access_token'] ?? '';
        $maxItems = $urlQueryParams['items'] ?? '10';
        $hashTagFilter = $urlQueryParams['hashTagFilter'] ?? '';
        $useHashTagFilter = $urlQueryParams['useHashTagFilter'] ?? '0';
        $showVideos = !empty($urlQueryParams['showVideos']) ? $urlQueryParams['showVideos'] : '0';

        if (!$accessToken) {
            return $this->prepareResult([]);
        }

        $mediaImageDataFetchUrl = $urlQueryStrings['scheme'] . '://' . $urlQueryStrings['host'] . '/' . '{{IG_MEDIA_ID}}' . '?fields=caption,media_type,media_url,like_count,permalink&access_token=' . $accessToken;

        try {
            $collectedImages = [];
            $nextUrl = $instaFetchUrl;
            $hashTagPattern = ($useHashTagFilter == 1) && $hashTagFilter ? '/#' . preg_quote($hashTagFilter, '/') . '(\s|$)/i' : null;

            while ($nextUrl && count($collectedImages) < $maxItems) {
                $ch = curl_init($nextUrl);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);

                $result = curl_exec($ch);
                $response = json_decode($result, true);

                if (isset($response['data'])) {
                    foreach ($response['data'] as $key => $data) {
                        $imageData = $this->instagramWidgetCache->getInstagramContentByCacheId($data['id']);
                        if ($imageData) {
                            $imageData = json_decode($imageData, true);
                        } else {
                            $imageData = $this->instagramWidgetCache->fetchInstagramImageDetails($mediaImageDataFetchUrl, $data['id']);
                            $this->instagramWidgetCache->saveInstagramContentByCacheId($data['id'], json_encode($imageData));
                        }

                        if (($showVideos == 0) && strtoupper($imageData['media_type']) == 'VIDEO') {
                            continue;
                        }

                        // Hashtag filtering
                        if ($useHashTagFilter && $hashTagPattern) {
                            $caption = isset($imageData['caption']) ? $imageData['caption'] : '';
                            if (!preg_match($hashTagPattern, $caption)) {
                                continue;
                            }
                        }
                        $collectedImages[] = $imageData;
                        if (count($collectedImages) >= $maxItems) {
                            break;
                        }
                    }
                }

                // Check for next page
                if (isset($response['paging']['next']) && count($collectedImages) < $maxItems) {
                    $nextUrl = $response['paging']['next'];
                } else {
                    $nextUrl = null;
                }
            }

            $result = ['data' => $collectedImages];
        } catch (\Exception $ex) {
            return $this->prepareResult([]);
        }

        return $this->prepareResult($result);
    }

    /**
     * @param string $imageUrl
     * @param string $imageId
     * @return false|mixed
     */


    /**
     * @param array $result
     * @return string
     */
    protected function prepareResult($result)
    {
        $jsonData = json_encode($result);
        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody($jsonData);
    }
}
