<?php
namespace WeltPixel\InstagramWidget\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

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
     * @param ResourceConnection $resource
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Serialize\Serializer\Json $serializer
     */
    public function __construct(
        ResourceConnection $resource,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Serialize\Serializer\Json $serializer
    ) {
        $this->resource = $resource;
        $this->connection = $resource->getConnection();
        $this->instagramCacheTableName = 'weltpixel_instagram_cache';
        $this->scopeConfig = $scopeConfig;
        $this->serializer = $serializer;
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
        $imageUrl = str_replace('{{IG_MEDIA_ID}}', $imageId, $imageUrl);
        try {
            $ch = curl_init($imageUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $result = curl_exec($ch);
            curl_close($ch);
            $response = json_decode($result, true);
        } catch (\Exception $ex) {
            return false;
        }
        return $response;
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
            $mediaApiUrl = 'https://graph.instagram.com/me/media?fields=id,caption,media_type,media_url,permalink,thumbnail_url,timestamp&access_token=' . $accessToken;
            $iteration = 0;
            $nextUrl = $mediaApiUrl;
            do {
                try {
                    $ch = curl_init($nextUrl);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    $result = curl_exec($ch);
                    curl_close($ch);
                    $response = json_decode($result, true);
                } catch (\Exception $ex) {
                    break;
                }
                if (!isset($response['data']) || !is_array($response['data'])) {
                    break;
                }

                // Step 2: For each media, fetch details and store
                $mediaDetailBaseUrl = 'https://graph.instagram.com/{{IG_MEDIA_ID}}?fields=caption,media_type,media_url,like_count,permalink&access_token=' . $accessToken;
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
                $nextUrl = isset($response['paging']['next']) ? $response['paging']['next'] : null;
            } while ($nextUrl && $iteration < self::MAX_PAGING_ITERATIONS);
        }
    }
}
