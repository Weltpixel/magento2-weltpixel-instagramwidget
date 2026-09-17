<?php

namespace WeltPixel\InstagramWidget\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

/**
 * Builds and executes Instagram Graph API requests.
 *
 * Every request URL is built here from a pinned host and a fixed path. No URL that arrives
 * from a browser, or from a paging.next value in an API response, is ever passed to curl.
 * Pagination is carried by the opaque "after" cursor only, which is re-embedded into a URL
 * this class builds itself.
 */
class GraphClient
{
    /**
     * The only host this client will ever contact
     */
    public const API_HOST = 'graph.instagram.com';

    public const MEDIA_LIST_PATH = '/me/media';

    /**
     * Placeholder used by callers that build a detail URL once and reuse it per media id
     */
    public const MEDIA_ID_PLACEHOLDER = '{{IG_MEDIA_ID}}';

    public const LIST_FIELDS = 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp';

    public const DETAIL_FIELDS = 'caption,media_type,media_url,like_count,permalink';

    public const TIMEOUT = 10;

    /**
     * Responses larger than this are discarded rather than decoded
     */
    public const MAX_RESPONSE_BYTES = 2097152;

    /**
     * Instagram tokens are long opaque strings, but always from this alphabet
     */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9._\-]{10,1024}$/';

    /**
     * Paging cursors are base64-ish opaque strings
     */
    public const CURSOR_PATTERN = '/^[A-Za-z0-9._\-=]{1,1024}$/';

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * GraphClient constructor.
     *
     * @param Curl $curl
     * @param LoggerInterface $logger
     */
    public function __construct(
        Curl $curl,
        LoggerInterface $logger
    ) {
        $this->curl = $curl;
        $this->logger = $logger;
    }

    /**
     * Whether the given value looks like an access token we are willing to send.
     *
     * @param mixed $token
     * @return bool
     */
    public function isValidToken($token)
    {
        return is_string($token) && preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /**
     * Whether the given value looks like an Instagram paging cursor.
     *
     * @param mixed $cursor
     * @return bool
     */
    public function isValidCursor($cursor)
    {
        return is_string($cursor) && preg_match(self::CURSOR_PATTERN, $cursor) === 1;
    }

    /**
     * Build the media listing URL.
     *
     * @param string $token
     * @param string|null $after
     * @param int|null $limit
     * @return string
     */
    public function buildMediaListUrl($token, $after = null, $limit = null)
    {
        $query = [
            'fields' => self::LIST_FIELDS,
            'access_token' => $token
        ];

        if ($limit !== null) {
            $query['limit'] = (int)$limit;
        }

        if ($this->isValidCursor($after)) {
            $query['after'] = $after;
        }

        return 'https://' . self::API_HOST . self::MEDIA_LIST_PATH . '?' . http_build_query($query);
    }

    /**
     * Build a media detail URL template. The media id placeholder is substituted by the caller.
     *
     * @param string $token
     * @return string
     */
    public function buildMediaDetailUrlTemplate($token)
    {
        $query = [
            'fields' => self::DETAIL_FIELDS,
            'access_token' => $token
        ];

        return 'https://' . self::API_HOST . '/' . self::MEDIA_ID_PLACEHOLDER . '?' . http_build_query($query);
    }

    /**
     * Pull the opaque "after" cursor out of an API supplied paging.next URL.
     *
     * The URL itself is discarded. Only the cursor survives, and it is re-embedded into a URL
     * that buildMediaListUrl() constructs, so a hostile or redirected paging value cannot steer
     * the next request anywhere.
     *
     * @param mixed $nextUrl
     * @return string|null
     */
    public function extractAfterCursor($nextUrl)
    {
        if (!is_string($nextUrl) || $nextUrl === '') {
            return null;
        }

        $query = parse_url($nextUrl, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }

        $params = [];
        parse_str($query, $params);
        $after = $params['after'] ?? null;

        return $this->isValidCursor($after) ? $after : null;
    }

    /**
     * Execute a GET against the Instagram Graph API.
     *
     * Refuses any URL that is not https on the pinned host, so a caller cannot use this
     * method as a general purpose fetcher even by mistake.
     *
     * @param string $url
     * @return array|null
     */
    public function get($url)
    {
        if (!$this->isApiUrl($url)) {
            return null;
        }

        try {
            $this->curl->setTimeout(self::TIMEOUT);
            $this->curl->setOptions([
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT
            ]);
            $this->curl->get($url);

            if ((int)$this->curl->getStatus() !== 200) {
                return null;
            }

            $body = (string)$this->curl->getBody();
        } catch (\Exception $ex) {
            $this->logger->error('InstagramWidget API request failed: ' . $ex->getMessage());
            return null;
        }

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether the URL is https on the pinned API host.
     *
     * @param mixed $url
     * @return bool
     */
    public function isApiUrl($url)
    {
        if (!is_string($url) || $url === '') {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return strtolower($parts['scheme']) === 'https'
            && strtolower($parts['host']) === self::API_HOST;
    }
}
