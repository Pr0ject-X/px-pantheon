<?php

declare(strict_types=1);

namespace Pr0jectX\PxPantheon\ProjectX\Plugin\CommandType;

use Pr0jectX\Px\ConfigTreeBuilder\ConfigTreeBuilder;
use Pr0jectX\Px\ProjectX\Plugin\PluginCommandRegisterInterface;
use Pr0jectX\Px\ProjectX\Plugin\PluginConfigurationBuilderInterface;
use Pr0jectX\Px\ProjectX\Plugin\PluginTasksBase;
use Pr0jectX\PxPantheon\ProjectX\Plugin\CommandType\Commands\PantheonCommand;
use Symfony\Component\Console\Question\Question;

/**
 * Define the pantheon command type.
 */
class PantheonCommandType extends PluginTasksBase implements PluginConfigurationBuilderInterface, PluginCommandRegisterInterface
{
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
     * @return string
     *   The pantheon site machine name.
     */
    public function getPantheonSite(): string
    {
        return $this->getConfigurations()['site'] ?? '';
    }

    /**
     * @inheritDoc
     */
    public function pluginConfiguration(): ConfigTreeBuilder
    {
        return (new ConfigTreeBuilder())
            ->setQuestionInput($this->input)
            ->setQuestionOutput($this->output)
            ->createNode('site')
                ->setValue((new Question(
                    $this->formatQuestion('Input the site machine name')
                ))->setValidator(function ($value) {
                    if (empty($value)) {
                        throw new \RuntimeException(
                            'The site machine name is required!'
                        );
                    }
                    if (!preg_match('/^[\w-]+$/', $value)) {
                        throw new \RuntimeException(
                            'The site machine name format is invalid!'
                        );
                    }
                    return $value;
                }))
            ->end();
    }
}
