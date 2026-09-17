<?php

namespace WeltPixel\InstagramWidget\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use WeltPixel\InstagramWidget\Block\Adminhtml\Form\Field\InstagramToken;

/**
 * Resolves Instagram access tokens by their configured name.
 *
 * The widget markup carries a token reference rather than the token itself, so the access token
 * stays on the server. The reference is the token's configured name, which is a label chosen by
 * the merchant and safe to publish.
 */
class TokenResolver
{
    /**
     * Marks a value in the widget markup as a token name rather than a token
     */
    public const REF_PREFIX = 'wpigref:';

    /**
     * Names are reduced to this alphabet before use, matching the widget option source
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9\-]{1,128}$/';

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var Json
     */
    protected $serializer;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Lazily built map of sanitized name to token value
     *
     * @var array|null
     */
    protected $tokenMap = null;

    /**
     * TokenResolver constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Json $serializer,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->serializer = $serializer;
        $this->logger = $logger;
    }

    /**
     * Reduce a token name to the identifier form used in widget options.
     *
     * @param mixed $name
     * @return string
     */
    public function sanitizeName($name)
    {
        if (!is_scalar($name)) {
            return '';
        }

        return (string)preg_replace('/[^A-Za-z0-9\-]/', '', (string)$name);
    }

    /**
     * Whether the given value is a token reference rather than a token.
     *
     * @param mixed $value
     * @return bool
     */
    public function isRef($value)
    {
        return is_string($value) && strpos($value, self::REF_PREFIX) === 0;
    }

    /**
     * @param string $name
     * @return string
     */
    public function makeRef($name)
    {
        return self::REF_PREFIX . $this->sanitizeName($name);
    }

    /**
     * Pull the name out of a reference, or null when the value is not a well formed reference.
     *
     * @param mixed $ref
     * @return string|null
     */
    public function extractName($ref)
    {
        if (!$this->isRef($ref)) {
            return null;
        }

        $name = substr($ref, strlen(self::REF_PREFIX));

        return preg_match(self::NAME_PATTERN, $name) === 1 ? $name : null;
    }

    /**
     * Look up a token by its configured name.
     *
     * @param mixed $name
     * @return string|null
     */
    public function resolveByName($name)
    {
        $name = $this->sanitizeName($name);
        if ($name === '') {
            return null;
        }

        $tokens = $this->getTokenMap();

        return $tokens[$name] ?? null;
    }

    /**
     * Find the configured name for a token value.
     *
     * Lets a widget that holds a token directly still publish a reference, provided the same
     * token is also present in the configured list.
     *
     * @param mixed $token
     * @return string|null
     */
    public function findNameForValue($token)
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        foreach ($this->getTokenMap() as $name => $value) {
            if (hash_equals($value, $token)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Build the name to token map.
     *
     * Two configured names that reduce to the same identifier are ambiguous: the name would no
     * longer say which account's feed to render, so both are dropped rather than guessed at.
     *
     * @return array
     */
    protected function getTokenMap()
    {
        if ($this->tokenMap !== null) {
            return $this->tokenMap;
        }

        $this->tokenMap = [];
        $ambiguous = [];

        foreach ($this->getConfiguredTokens() as $entry) {
            if (!is_array($entry) || empty($entry['token_name']) || empty($entry['token_value'])) {
                continue;
            }

            $name = $this->sanitizeName($entry['token_name']);
            $value = (string)$entry['token_value'];
            if ($name === '' || $value === '') {
                continue;
            }

            if (isset($this->tokenMap[$name]) && !hash_equals($this->tokenMap[$name], $value)) {
                $ambiguous[$name] = true;
                continue;
            }

            $this->tokenMap[$name] = $value;
        }

        foreach (array_keys($ambiguous) as $name) {
            unset($this->tokenMap[$name]);
            $this->logger->warning(
                'InstagramWidget: token name "' . $name . '" matches more than one configured token. '
                . 'Rename them so each reduces to a distinct name.'
            );
        }

        return $this->tokenMap;
    }

    /**
     * @return array
     */
    protected function getConfiguredTokens()
    {
        $tokenOptions = $this->scopeConfig->getValue(InstagramToken::TOKEN_PATH);
        if (!$tokenOptions) {
            return [];
        }

        try {
            $decoded = $this->serializer->unserialize($tokenOptions);
        } catch (\Exception $ex) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
