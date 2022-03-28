<?php

declare(strict_types=1);

namespace Pr0jectX\PxPantheon\ProjectX\Plugin\CommandType;

use Pr0jectX\Px\ConfigTreeBuilder\ConfigTreeBuilder;
use Pr0jectX\Px\ProjectX\Plugin\PluginCommandRegisterInterface;
use Pr0jectX\Px\ProjectX\Plugin\PluginConfigurationBuilderInterface;
use Pr0jectX\Px\ProjectX\Plugin\PluginTasksBase;
use Pr0jectX\Px\PxApp;
use Pr0jectX\PxPantheon\Pantheon;
use Pr0jectX\PxPantheon\ProjectX\Plugin\CommandType\Commands\PantheonCommand;
use Symfony\Component\Console\Question\Question;

/**
 * Define the pantheon command type.
 */
class PantheonCommandType extends PluginTasksBase implements PluginConfigurationBuilderInterface, PluginCommandRegisterInterface
{
    /**
     * The pantheon default framework.
     */
    protected const DEFAULT_FRAMEWORK = 'drupal';

    /**
     * @inheritDoc
     */
    public static function pluginId(): string
    {
        return 'pantheon';
    }

    /**
     * @inheritDoc
     */
    public static function pluginLabel(): string
    {
        return 'Pantheon';
    }

    /**
     * @inheritDoc
     */
    public function registeredCommands(): array
    {
        return [
            PantheonCommand::class,
        ];
    }

    /**
     * Get the pantheon site machine name.
     *
     * @return string|null
     *   The pantheon site machine name.
     */
    public function getPantheonSite(): ?string
    {
        return $this->getConfigurations()['site'] ?? null;
    }

    /**
     * Get the pantheon site framework.
     *
     * @return string|null
     *   The pantheon site framework.
     */
    public function getPantheonFramework(): ?string
    {
        return $this->getConfigurations()['framework'] ?? null;
    }

    /**
     * Get the pantheon site PHP version.
     *
     * @return string|null
     *   The pantheon site PHP version.
     */
    public function getPantheonPhpVersion(): ?string
    {
        return $this->getConfigurations()['php_version'] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function pluginConfiguration(): ConfigTreeBuilder
    {
        $frameworks = Pantheon::frameworks();
        $phpVersions = PxApp::activePhpVersions();

        $siteMachineName = $this->getPantheonSite();
        $sitePhpVersion = $this->getPantheonPhpVersion() ?? $phpVersions[1];
        $siteFramework = $this->getPantheonFramework() ?? static::DEFAULT_FRAMEWORK;

        $configBuilder = (new ConfigTreeBuilder())
            ->setQuestionInput($this->input)
            ->setQuestionOutput($this->output);

        try {
            $configBuilder->createNode('php_version')
                ->setValue($this->choice('Select the site PHP version', $phpVersions, $sitePhpVersion)
                    ->setValidator($this->checkValueIsNotEmpty('site PHP version'))
                    ->setNormalizer(static function ($value) use ($phpVersions) {
                        return $phpVersions[$value] ?? $value;
                    }))
                ->end();
            $configBuilder->createNode('framework')
                ->setValue($this->choice('Select the site framework', $frameworks, $siteFramework)
                    ->setValidator($this->checkValueIsNotEmpty('site framework')))
                ->end();
            $configBuilder->createNode('site')
                ->setValue((new Question(
                    $this->formatQuestionDefault('Input the site machine name', $siteMachineName),
                    $siteMachineName
                ))->setValidator($this->checkValueIsNotEmpty(
                    'site machine name',
                    static function ($text, $value) {
                        if (!preg_match('/^[\w-]+$/', $value)) {
                            throw new \InvalidArgumentException(
                                "The $text format is invalid!"
                            );
                        }
                        return $value;
                    }
                )))
                ->end();
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }

        return $configBuilder;
    }

    /**
     * Check if the question value is not empty.
     *
     * @param string $text
     *   The text of the element.
     * @param callable|null $nextValidator
     *   The next validator that should be called, otherwise null.
     *
     * @return callable
     *   A callable function that accepts a value argument.
     */
    protected function checkValueIsNotEmpty(
        string $text,
        callable $nextValidator = null
    ): callable {
        return static function ($value) use ($text, $nextValidator) {
            if (empty($value)) {
                throw new \InvalidArgumentException(
                    "The $text is required!"
                );
            }

            if (is_callable($nextValidator)) {
                $value = $nextValidator($text, $value);
            }

            return $value;
        };
    }
}
