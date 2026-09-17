<?php
namespace WeltPixel\InstagramWidget\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use WeltPixel\InstagramWidget\Model\Api\GraphClient;

class InstagramWidgetCache
{
    const MAX_PAGING_ITERATIONS = 3;

    /**
     * @var string
     */
    protected $instagramCacheTableName;

    /**
     * @var AdapterInterface
     */
    protected $connection;

    /**
     * @var ResourceConnection
     */
    protected $resource;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    protected $serializer;

    /**
     * @var GraphClient
     */
    protected $graphClient;

    /**
     * @param ResourceConnection $resource
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Serialize\Serializer\Json $serializer
     * @param GraphClient|null $graphClient
     */
    public function __construct(
        ResourceConnection $resource,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Serialize\Serializer\Json $serializer,
        GraphClient $graphClient
    ) {
        $this->resource = $resource;
        $this->connection = $resource->getConnection();
        $this->instagramCacheTableName = 'weltpixel_instagram_cache';
        $this->scopeConfig = $scopeConfig;
        $this->serializer = $serializer;
        $this->graphClient = $graphClient;
    }

    /**
     * Retrieve Instagram tokens from config
     * @return array
     */
    protected function getInstagramTokens()
    {
        $tokenOptions = $this->scopeConfig->getValue(\WeltPixel\InstagramWidget\Block\Adminhtml\Form\Field\InstagramToken::TOKEN_PATH);
        $tokens = [];
        if ($tokenOptions) {
            $decoded = $this->serializer->unserialize($tokenOptions);
            foreach ($decoded as $tokenOpt) {
                if (!empty($tokenOpt['token_value'])) {
                    $tokens[] = $tokenOpt['token_value'];
                }
            }
        }
        return $tokens;
    }

    /**
     * Fetch Instagram image details by media ID
     * @param string $imageUrl
     * @param string $imageId
     * @return array|false
     */
    public function fetchInstagramImageDetails($imageUrl, $imageId)
    {
        $imageUrl = str_replace(GraphClient::MEDIA_ID_PLACEHOLDER, rawurlencode((string)$imageId), (string)$imageUrl);

        $response = $this->graphClient->get($imageUrl);

        return is_array($response) ? $response : false;
    }

    /**
     * @return string
     */
    public function getInstagramCacheTableName()
    {
        return $this->resource->getTableName($this->instagramCacheTableName);
    }

    /**
     * @param string $cacheId
     * @return string
     */
    public function getInstagramContentByCacheId($cacheId)
    {
        $tableName = $this->getInstagramCacheTableName();
        $select = $this->connection->select()
            ->from(
                ['t' => $tableName],
                ['content']
            )
            ->where(
                "t.cache_id = :cache_id"
            );
        $bind = ['cache_id'=>$cacheId];
        $result = $this->connection->fetchOne($select, $bind);

        return $result;
    }

    /**
     * @param $caheId
     * @param $instagramContent
     */
    public function saveInstagramContentByCacheId($caheId, $instagramContent)
    {
        $insertData = [
            'cache_id' => $caheId,
            'content' => $instagramContent
        ];
        $tableName = $this->getInstagramCacheTableName();
        $deleteWhereCondition = [
            $this->connection->quoteInto('cache_id = ?', $caheId),
        ];
        $this->connection->delete($tableName, $deleteWhereCondition);
        $this->connection->insert($tableName, $insertData);
    }

    /**
     * @return void
     */
    public function cleanInstagramCacheTable()
    {
        $tableName = $this->getInstagramCacheTableName();
        $this->connection->truncateTable($tableName);
        $this->refetchAndResaveInstagramImages();
    }

    /**
     * Refetches Instagram images using tokens and stores them in the cache table
     * Handles paging up to MAX_PAGING_ITERATIONS
     */
    protected function refetchAndResaveInstagramImages()
    {
        $tokens = $this->getInstagramTokens();

        if (empty($tokens)) {
            return;
        }

        foreach ($tokens as $accessToken) {
            if (!$this->graphClient->isValidToken($accessToken)) {
                continue;
            }

            $iteration = 0;
            $after = null;
            $mediaDetailBaseUrl = $this->graphClient->buildMediaDetailUrlTemplate($accessToken);

            do {
                $response = $this->graphClient->get(
                    $this->graphClient->buildMediaListUrl($accessToken, $after)
                );

                if (!isset($response['data']) || !is_array($response['data'])) {
                    break;
                }

                // Step 2: For each media, fetch details and store
                foreach ($response['data'] as $mediaItem) {
                    if (empty($mediaItem['id'])) {
                        continue;
                    }
                    $imageData = $this->fetchInstagramImageDetails($mediaDetailBaseUrl, $mediaItem['id']);
                    if ($imageData) {
                        $this->saveInstagramContentByCacheId($mediaItem['id'], json_encode($imageData));
                    }
                }
                $iteration++;
                $after = $this->graphClient->extractAfterCursor($response['paging']['next'] ?? null);
            } while ($after !== null && $iteration < self::MAX_PAGING_ITERATIONS);
        }
    }
}
