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

use PrestaShop\Module\No404\Platform;
use PrestaShop\Module\No404\Settings\Keys;
use PrestaShopBundle\Form\Admin\Type\MultistoreConfigurationType;
use PrestaShopBundle\Form\Admin\Type\SwitchType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The settings form. Every field carries `multistore_configuration_key`, so in
 * a shop or group context PrestaShop adds its "override for this context"
 * checkbox next to it; the parent type enables that.
 */
final class SettingsFormType extends TranslatorAwareType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', SwitchType::class, [
                'label' => $this->trans('Apply no404 redirects', 'Modules.No404.Admin'),
                'help' => $this->trans('Master switch. When off, 404 pages are shown as they are.', 'Modules.No404.Admin'),
                'required' => false,
                'multistore_configuration_key' => Keys::ENABLED,
            ])
            ->add('api_key', PasswordType::class, [
                'label' => $this->trans('API key', 'Modules.No404.Admin'),
                'help' => $this->trans('Copy it from your no404 dashboard: your site > Integration. It is used server-side only. Leave the field empty to keep the saved key.', 'Modules.No404.Admin'),
                'required' => false,
                'always_empty' => true,
                'attr' => [
                    'autocomplete' => 'off',
                    'placeholder' => $this->trans('Paste your key', 'Modules.No404.Admin'),
                ],
                'multistore_configuration_key' => Keys::API_KEY,
            ])
            ->add('api_base', TextType::class, [
                'label' => $this->trans('no404 address', 'Modules.No404.Admin'),
                'help' => $this->trans(
                    'You should not need to touch this — the default, %default%, is the right address. Change it only if you host no404 on your own server. Leave the field empty to restore the default.',
                    'Modules.No404.Admin',
                    ['%default%' => Keys::DEFAULT_API_BASE]
                ),
                'required' => false,
                'empty_data' => '',
                'attr' => ['placeholder' => Keys::DEFAULT_API_BASE],
                'multistore_configuration_key' => Keys::API_BASE,
            ])
            ->add('force_301', SwitchType::class, [
                'label' => $this->trans('Send every match as a 301', 'Modules.No404.Admin'),
                'help' => $this->trans('By default the 301/302 choice follows the 301 threshold set for this site in the no404 dashboard. A 301 is cached permanently by browsers and cannot be taken back — enable this only if you are confident in your catalogue.', 'Modules.No404.Admin'),
                'required' => false,
                'multistore_configuration_key' => Keys::FORCE_301,
            ]);

        // Before PrestaShop 9.1.5 the core never fires its "page not found"
        // event, so every 404 already takes the catch-all path: nothing to choose.
        if (Platform::firesNotFoundHook(_PS_VERSION_)) {
            $builder->add('catch_all', SwitchType::class, [
                'label' => $this->trans('Catch-all mode', 'Modules.No404.Admin'),
                'help' => $this->trans('Also redirects 404 pages that PrestaShop renders without its "page not found" event — for example a disabled product set to "404 Not Found". It only runs on responses that are already 404s. Off by default.', 'Modules.No404.Admin'),
                'required' => false,
                'multistore_configuration_key' => Keys::CATCH_ALL,
            ]);
        }

        // Constraint options as arrays, not named arguments: this form also runs
        // on PHP 7.2 in the PrestaShop 8 line.
        $builder
            ->add('cache_ttl', IntegerType::class, [
                'label' => $this->trans('Cache lifetime (seconds)', 'Modules.No404.Admin'),
                'help' => $this->trans('Stops bots that request the same dead URL over and over from using up your monthly quota. Default 3600 (1 hour); minimum 60, maximum 604800 (7 days).', 'Modules.No404.Admin'),
                'attr' => ['min' => Keys::MIN_CACHE_TTL, 'max' => Keys::MAX_CACHE_TTL, 'step' => 60],
                'constraints' => [
                    new NotBlank(),
                    new Range(['min' => Keys::MIN_CACHE_TTL, 'max' => Keys::MAX_CACHE_TTL]),
                ],
                'multistore_configuration_key' => Keys::CACHE_TTL,
            ])
            ->add('timeout_ms', IntegerType::class, [
                'label' => $this->trans('Timeout (milliseconds)', 'Modules.No404.Admin'),
                'help' => $this->trans('If no404 does not answer within this time, the 404 page is shown as usual. Minimum 300, maximum 1500.', 'Modules.No404.Admin'),
                'attr' => ['min' => Keys::MIN_TIMEOUT_MS, 'max' => Keys::MAX_TIMEOUT_MS, 'step' => 100],
                'constraints' => [
                    new NotBlank(),
                    new Range(['min' => Keys::MIN_TIMEOUT_MS, 'max' => Keys::MAX_TIMEOUT_MS]),
                ],
                'multistore_configuration_key' => Keys::TIMEOUT_MS,
            ])
            ->add('excluded_paths', TextareaType::class, [
                'label' => $this->trans('Excluded paths', 'Modules.No404.Admin'),
                'help' => $this->trans('One path prefix per line; URLs starting with these are never sent to no404. Static files, /modules, /img, /themes, the back office and similar paths are excluded automatically.', 'Modules.No404.Admin'),
                'required' => false,
                'empty_data' => '',
                'attr' => ['rows' => 5, 'placeholder' => "/promo\n/old-blog"],
                'multistore_configuration_key' => Keys::EXCLUDED_PATHS,
            ])
            ->add('cache_backend', ChoiceType::class, [
                'label' => $this->trans('Cache backend', 'Modules.No404.Admin'),
                'help' => $this->trans('Files work everywhere. With APCu, answers are served from shared memory first, which helps on busy stores. Files are used when APCu is not available.', 'Modules.No404.Admin'),
                'choices' => [
                    $this->trans('Files', 'Modules.No404.Admin') => Keys::BACKEND_FILE,
                    $this->trans('APCu, then files', 'Modules.No404.Admin') => Keys::BACKEND_APCU,
                ],
                'choice_translation_domain' => false,
                'multistore_configuration_key' => Keys::CACHE_BACKEND,
            ])
            ->add('cache_dir', TextType::class, [
                'label' => $this->trans('Cache directory', 'Modules.No404.Admin'),
                'help' => $this->trans(
                    'Leave empty to use PrestaShop\'s cache directory (%default%). Enter an absolute path only if that directory is not writable on your server.',
                    'Modules.No404.Admin',
                    ['%default%' => rtrim(_PS_CACHE_DIR_, '/\\')]
                ),
                'required' => false,
                'empty_data' => '',
                'multistore_configuration_key' => Keys::CACHE_DIR,
            ])
            ->add('debug', SwitchType::class, [
                'label' => $this->trans('Debug headers', 'Modules.No404.Admin'),
                'help' => $this->trans('Adds X-No404-* headers to 404 responses to help support diagnose a setup. They never contain the API key or visitor data.', 'Modules.No404.Admin'),
                'required' => false,
                'multistore_configuration_key' => Keys::DEBUG,
            ]);
    }

    /**
     * {@inheritdoc}
     *
     * @see \PrestaShopBundle\Form\Extension\MultistoreConfigurationTypeExtension
     */
    public function getParent(): string
    {
        return MultistoreConfigurationType::class;
    }
}
