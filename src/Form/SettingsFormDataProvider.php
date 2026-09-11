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

use PrestaShop\PrestaShop\Core\Configuration\DataConfigurationInterface;
use PrestaShop\PrestaShop\Core\Form\FormDataProviderInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Bridges the core form handler and SettingsDataConfiguration.
 */
final class SettingsFormDataProvider implements FormDataProviderInterface
{
    /** @var DataConfigurationInterface */
    private $dataConfiguration;

    public function __construct(DataConfigurationInterface $dataConfiguration)
    {
        $this->dataConfiguration = $dataConfiguration;
    }

    /**
     * {@inheritdoc}
     */
    public function getData(): array
    {
        return $this->dataConfiguration->getConfiguration();
    }

    /**
     * {@inheritdoc}
     */
    public function setData(array $data): array
    {
        return $this->dataConfiguration->updateConfiguration($data);
    }
}
