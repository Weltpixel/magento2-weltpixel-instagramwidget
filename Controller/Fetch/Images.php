<?php

namespace WeltPixel\InstagramWidget\Controller\Fetch;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use WeltPixel\InstagramWidget\Model\Api\GraphClient;
use WeltPixel\InstagramWidget\Model\InstagramWidgetCache;
use WeltPixel\InstagramWidget\Model\TokenResolver;

/**
 * Storefront proxy for the Instagram media feed.
 *
 * The browser supplies feed options only. The request URL is always built server side against
 * the pinned Graph API host, so this endpoint cannot be used to make the store fetch an
 * arbitrary address.
 */
class Images extends Action implements HttpGetActionInterface
{
    /**
     * Upper bound on the number of items a caller may request
     */
    public const MAX_ITEMS = 100;

    public const DEFAULT_ITEMS = 10;

    /**
     * Upper bound on API round trips per request, so paging cannot be used to tie up a worker
     */
    public const MAX_PAGES = 10;

    /**
     * @var InstagramWidgetCache
     */
    protected $instagramWidgetCache;

    /**
     * @var GraphClient
     */
    protected $graphClient;

    /**
     * @var TokenResolver
     */
    protected $tokenResolver;

    /**
     * Images constructor.
     *
     * @param Context $context
     * @param InstagramWidgetCache $instagramWidgetCache
     * @param GraphClient $graphClient
     * @param TokenResolver|null $tokenResolver
     */
    public function __construct(
        Context $context,
        InstagramWidgetCache $instagramWidgetCache,
        GraphClient $graphClient,
        TokenResolver $tokenResolver
    ) {
        $this->instagramWidgetCache = $instagramWidgetCache;
        $this->graphClient = $graphClient;
        $this->tokenResolver = $tokenResolver;
        parent::__construct($context);
    }

    /**
     * @return void
     */
    public function execute()
    {
        $params = $this->resolveParams();

        $accessToken = $this->resolveAccessToken($params['access_token']);
        if (!$this->graphClient->isValidToken($accessToken)) {
            $this->prepareResult([]);
            return;
        }

        $maxItems = $params['items'];
        $showVideos = $params['showVideos'];
        $useHashTagFilter = $params['useHashTagFilter'];
        $hashTagFilter = $params['hashTagFilter'];

        $hashTagPattern = ($useHashTagFilter && $hashTagFilter !== '')
            ? '/#' . preg_quote($hashTagFilter, '/') . '(\s|$)/i'
            : null;

        $detailUrlTemplate = $this->graphClient->buildMediaDetailUrlTemplate($accessToken);

        $collectedImages = [];
        $after = null;
        $page = 0;

        try {
            do {
                $response = $this->graphClient->get(
                    $this->graphClient->buildMediaListUrl($accessToken, $after, $maxItems)
                );

                if (!is_array($response) || empty($response['data']) || !is_array($response['data'])) {
                    break;
                }

                foreach ($response['data'] as $data) {
                    if (empty($data['id'])) {
                        continue;
                    }

                    $imageData = $this->loadImageData($detailUrlTemplate, $data['id']);
                    if (!is_array($imageData)) {
                        continue;
                    }

                    if (!$showVideos && strtoupper($imageData['media_type'] ?? '') == 'VIDEO') {
                        continue;
                    }

                    if ($hashTagPattern !== null) {
                        $caption = isset($imageData['caption']) ? (string)$imageData['caption'] : '';
                        if (!preg_match($hashTagPattern, $caption)) {
                            continue;
                        }
                    }

                    $collectedImages[] = $imageData;
                    if (count($collectedImages) >= $maxItems) {
                        break;
                    }
                }

                $after = $this->graphClient->extractAfterCursor($response['paging']['next'] ?? null);
                $page++;
            } while ($after !== null && $page < self::MAX_PAGES && count($collectedImages) < $maxItems);
        } catch (\Exception $ex) {
            $this->prepareResult([]);
            return;
        }

        $this->prepareResult(['data' => $collectedImages]);
    }

    /**
     * Turn the value the markup published into an access token.
     *
     * Current widget markup publishes a reference to a token configured in the module settings,
     * and the token is read here rather than travelling through the browser. Markup produced
     * before this release, and any template a merchant has forked, still sends the token itself,
     * so that form is accepted unchanged.
     *
     * @param string $value
     * @return string
     */
    protected function resolveAccessToken($value)
    {
        if (!$this->tokenResolver->isRef($value)) {
            return $value;
        }

        $name = $this->tokenResolver->extractName($value);
        if ($name === null) {
            return '';
        }

        return (string)$this->tokenResolver->resolveByName($name);
    }

    /**
     * Read feed options from the request.
     *
     * Options may arrive either as plain request parameters or, for widget markup rendered before
     * this module was updated, inside the query string of a legacy instaFetchUrl parameter. In the
     * legacy case only the query string is read; the scheme, host and path are discarded.
     *
     * @return array
     */
    protected function resolveParams()
    {
        $request = $this->getRequest();
        $source = [];

        $legacyUrl = $request->getParam('instaFetchUrl');
        if (is_string($legacyUrl) && $legacyUrl !== '') {
            $query = parse_url($legacyUrl, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                parse_str($query, $source);
            }
        }

        foreach (['access_token', 'items', 'hashTagFilter', 'useHashTagFilter', 'showVideos'] as $key) {
            $value = $request->getParam($key);
            if ($value !== null) {
                $source[$key] = $value;
            }
        }

        return [
            'access_token' => $this->scalarParam($source, 'access_token'),
            'items' => $this->itemsParam($source),
            'hashTagFilter' => $this->scalarParam($source, 'hashTagFilter'),
            'useHashTagFilter' => $this->boolParam($source, 'useHashTagFilter'),
            'showVideos' => $this->boolParam($source, 'showVideos')
        ];
    }

    /**
     * @param array $source
     * @param string $key
     * @return string
     */
    protected function scalarParam(array $source, $key)
    {
        $value = $source[$key] ?? '';

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * The widget sends 'false' and 'true' as strings, and older markup sends '0' and '1'.
     *
     * @param array $source
     * @param string $key
     * @return bool
     */
    protected function boolParam(array $source, $key)
    {
        $value = strtolower($this->scalarParam($source, $key));

        return !in_array($value, ['', '0', 'false', 'null', 'undefined'], true);
    }

    /**
     * @param array $source
     * @return int
     */
    protected function itemsParam(array $source)
    {
        $items = (int)$this->scalarParam($source, 'items');

        if ($items < 1) {
            $items = self::DEFAULT_ITEMS;
        }

        return min($items, self::MAX_ITEMS);
    }

    /**
     * Read one media item from the cache table, fetching and caching it on a miss.
     *
     * @param string $detailUrlTemplate
     * @param string $mediaId
     * @return array|null
     */
    protected function loadImageData($detailUrlTemplate, $mediaId)
    {
        $cached = $this->instagramWidgetCache->getInstagramContentByCacheId($mediaId);
        if ($cached) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $imageData = $this->instagramWidgetCache->fetchInstagramImageDetails($detailUrlTemplate, $mediaId);
        if (!is_array($imageData)) {
            return null;
        }

        $this->instagramWidgetCache->saveInstagramContentByCacheId($mediaId, json_encode($imageData));

        return $imageData;
    }

    /**
     * @param array $result
     * @return void
     */
    protected function prepareResult($result)
    {
        $jsonData = json_encode($result);
        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody($jsonData);
    }
}
