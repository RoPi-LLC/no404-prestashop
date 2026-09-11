<?php
/**
 * no404 – Auto 404 Redirect for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    no404 <https://www.no404.tr>
 * @copyright 2026 no404
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace PrestaShop\Module\No404\Form;

use PrestaShop\Module\No404\Adapter\SymfonyCache;
use PrestaShop\Module\No404\Front\ClientFactory;
use PrestaShop\Module\No404\Settings\Keys;
use PrestaShop\Module\No404\Settings\SettingsReader;
use PrestaShop\Module\No404\Settings\SettingsSanitizer;
use PrestaShop\PrestaShop\Adapter\Configuration;
use PrestaShop\PrestaShop\Adapter\Shop\Context;
use PrestaShop\PrestaShop\Core\Configuration\AbstractMultistoreConfiguration;
use PrestaShop\PrestaShop\Core\Feature\FeatureInterface;
use PrestaShopBundle\Service\Form\MultistoreCheckboxEnabler;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface as OptionsResolverException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Loads and stores the settings of the current shop context. Values are read
 * through SettingsReader and cleaned through SettingsSanitizer — the same rules
 * the storefront applies when it reads them.
 */
final class SettingsDataConfiguration extends AbstractMultistoreConfiguration
{
    /** Form field => Configuration key. */
    public const FIELDS = [
        'enabled' => Keys::ENABLED,
        'api_key' => Keys::API_KEY,
        'api_base' => Keys::API_BASE,
        'force_301' => Keys::FORCE_301,
        'cache_ttl' => Keys::CACHE_TTL,
        'timeout_ms' => Keys::TIMEOUT_MS,
        'excluded_paths' => Keys::EXCLUDED_PATHS,
        'debug' => Keys::DEBUG,
        'catch_all' => Keys::CATCH_ALL,
        'cache_backend' => Keys::CACHE_BACKEND,
        'cache_dir' => Keys::CACHE_DIR,
    ];

    /** @var TranslatorInterface */
    private $translator;

    public function __construct(Configuration $configuration, Context $shopContext, FeatureInterface $multistoreFeature, TranslatorInterface $translator)
    {
        parent::__construct($configuration, $shopContext, $multistoreFeature);
        $this->translator = $translator;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration(): array
    {
        $settings = $this->reader();

        return [
            'enabled' => $settings->isEnabled(),
            // Never sent back to the browser; the page shows the masked form instead.
            'api_key' => '',
            'api_base' => $settings->apiBase(),
            'force_301' => $settings->force301(),
            'cache_ttl' => $settings->cacheTtl(),
            'timeout_ms' => $settings->timeoutMs(),
            'excluded_paths' => $settings->excludedPathsText(),
            'debug' => $settings->debug(),
            'catch_all' => $settings->catchAll(),
            'cache_backend' => $settings->cacheBackend(),
            'cache_dir' => $settings->cacheDirectory(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function updateConfiguration(array $configuration): array
    {
        try {
            $this->validateConfiguration($configuration);
        } catch (OptionsResolverException $e) {
            return [$this->translator->trans('The submitted settings could not be read. Reload the page and try again.', [], 'Modules.No404.Admin')];
        }

        $errors = [];
        $shopConstraint = $this->getShopConstraint();
        $clean = $configuration;

        foreach (['enabled', 'force_301', 'debug', 'catch_all'] as $switch) {
            if (array_key_exists($switch, $clean)) {
                $clean[$switch] = empty($clean[$switch]) ? 0 : 1;
            }
        }

        if (array_key_exists('api_base', $clean)) {
            $base = SettingsSanitizer::apiBase($clean['api_base'], $this->reader()->apiBase());
            $clean['api_base'] = $base['value'];
            if (!$base['valid']) {
                $errors[] = $this->translator->trans('The no404 address must be a valid http(s) URL. The previous value has been kept. Leave the field empty to restore the default.', [], 'Modules.No404.Admin');
            }
        }

        if (array_key_exists('api_key', $clean)) {
            $key = SettingsSanitizer::apiKey($clean['api_key']);
            if (null !== $key) {
                $clean['api_key'] = $key;
            } elseif (!$this->isRemovingOverride('api_key', $configuration)) {
                // Empty or still masked: keep the stored key.
                unset($clean['api_key']);
            }
        }

        if (array_key_exists('cache_ttl', $clean)) {
            $clean['cache_ttl'] = SettingsSanitizer::cacheTtl($clean['cache_ttl']);
        }
        if (array_key_exists('timeout_ms', $clean)) {
            $clean['timeout_ms'] = SettingsSanitizer::timeoutMs($clean['timeout_ms']);
        }
        if (array_key_exists('excluded_paths', $clean)) {
            $clean['excluded_paths'] = SettingsSanitizer::excludedPaths($clean['excluded_paths']);
        }
        if (array_key_exists('cache_backend', $clean)) {
            $clean['cache_backend'] = SettingsSanitizer::cacheBackend($clean['cache_backend']);
        }
        if (array_key_exists('cache_dir', $clean)) {
            $directory = SettingsSanitizer::cacheDirectory($clean['cache_dir']);
            if (null === $directory || ('' !== $directory && !SymfonyCache::isUsable(ClientFactory::cacheDirectoryIn($directory)))) {
                $clean['cache_dir'] = $this->reader()->cacheDirectory();
                $errors[] = $this->translator->trans('The cache directory must be an absolute path to an existing, writable directory. The previous value has been kept.', [], 'Modules.No404.Admin');
            } else {
                $clean['cache_dir'] = $directory;
            }
        }

        foreach (self::FIELDS as $field => $key) {
            $this->updateConfigurationValue($key, $field, $clean, $shopConstraint);
        }

        return $errors;
    }

    /**
     * {@inheritdoc}
     */
    protected function buildResolver(): OptionsResolver
    {
        $resolver = new OptionsResolver();
        $resolver->setDefined(array_keys(self::FIELDS));
        $resolver->setAllowedTypes('enabled', ['bool', 'int', 'string']);
        $resolver->setAllowedTypes('force_301', ['bool', 'int', 'string']);
        $resolver->setAllowedTypes('debug', ['bool', 'int', 'string']);
        $resolver->setAllowedTypes('catch_all', ['bool', 'int', 'string']);
        $resolver->setAllowedTypes('cache_backend', ['string', 'null']);
        $resolver->setAllowedTypes('cache_dir', ['string', 'null']);
        $resolver->setAllowedTypes('api_key', ['string', 'null']);
        $resolver->setAllowedTypes('api_base', ['string', 'null']);
        $resolver->setAllowedTypes('excluded_paths', ['string', 'null']);
        $resolver->setAllowedTypes('cache_ttl', ['int', 'string', 'null']);
        $resolver->setAllowedTypes('timeout_ms', ['int', 'string', 'null']);

        return $resolver;
    }

    /**
     * In a shop or group context an unticked multistore checkbox means "inherit
     * from the parent context": the stored override must be deleted even though
     * the (always empty) key field carries no value.
     *
     * @param string $field form field
     * @param array<string, mixed> $input submitted data
     *
     * @return bool
     */
    private function isRemovingOverride($field, array $input)
    {
        return $this->multistoreFeature->isUsed()
            && !$this->shopContext->isAllShopContext()
            && !isset($input[MultistoreCheckboxEnabler::MULTISTORE_FIELD_PREFIX . $field]);
    }

    /** @return SettingsReader settings of the current context, through the core configuration adapter */
    private function reader()
    {
        $shopConstraint = $this->getShopConstraint();

        return new SettingsReader(function ($key) use ($shopConstraint) {
            $value = $this->configuration->get($key, null, $shopConstraint);

            return null === $value ? false : $value;
        });
    }
}
