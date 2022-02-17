<?php

declare(strict_types=1);

namespace Pr0jectX\PxPantheon\ProjectX\Plugin\CommandType\Commands;

use Pr0jectX\Px\ProjectX\Plugin\PluginCommandTaskBase;
use Pr0jectX\Px\PxApp;
use Pr0jectX\PxPantheon\Pantheon;
use Pr0jectX\PxPantheon\ProjectX\Plugin\CommandType\PantheonCommandType;
use Pr0jectX\Px\Task\LoadTasks as PxTasks;
use Psr\Cache\CacheItemInterface;
use Robo\Collection\CollectionBuilder;
use Robo\Contract\VerbosityThresholdInterface;
use Stringy\StaticStringy;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Define the pantheon command.
 */
class PantheonCommand extends PluginCommandTaskBase
{
    use PxTasks;

    /**
     * Define the terminus stable version
     */
    private const TERMINUS_STABLE_VERSION = '3.0.5';

    /**
     * Authenticate with the pantheon service.
     */
    public function pantheonLogin(): void
    {
        Pantheon::displayBanner();

        $this->terminusCommand()
            ->setSubCommand('auth:login')
            ->run();
    }

    /**
     * Display the pantheon developer information.
     *
     * @param null $siteEnv
     *   The pantheon site environment.
     */
    public function pantheonInfo($siteEnv = null): void
    {
        Pantheon::displayBanner();

        try {
            $siteName = $this->getPantheonSiteName();
            $siteEnv = $siteEnv ?? $this->askForPantheonSiteEnv();

            $this->terminusCommand()
                ->setSubCommand('connection:info')
                ->arg("{$siteName}.{$siteEnv}")
                ->run();
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Setup the project to use the pantheon service.
     */
    public function pantheonSetup(): void
    {
        Pantheon::displayBanner();

        $phpVersions = PxApp::activePhpVersions();

        $phpVersion = $this->askChoice(
            'Select the PHP version',
            $phpVersions,
            $phpVersions[1]
        );

        $this->taskWriteToFile(PxApp::projectRootPath() . '/pantheon.yml')
            ->text(Pantheon::loadTemplateFile('pantheon.yml'))
            ->place('PHP_VERSION', $phpVersion)
            ->run();

        $framework = $this->askChoice('Select the PHP framework', [
            'drupal' => 'Drupal',
            'wordpress' => 'Wordpress'
        ], 'drupal');

        try {
            if ($framework === 'drupal') {
                $this->setupDrupal();
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Install the terminus command utility system-wide.
     *
     * @param null|string $version
     *   The terminus version to install.
     */
    public function pantheonInstallTerminus(?string $version = null): void
    {
        if (!$this->isTerminusInstalled()) {
            try {
                $userDir = PxApp::userDir();
                $version = $version ?? $this->fetchLatestTerminusRelease();

                $stack = $this->taskExecStack()
                    ->exec("mkdir -p {$userDir}/terminus")
                    ->exec("cd {$userDir}/terminus")
                    ->exec("curl -L https://github.com/pantheon-systems/terminus/releases/download/{$version}/terminus.phar --output terminus")
                    ->exec('chmod +x terminus')
                    ->exec('sudo ln -s ~/terminus/terminus /usr/local/bin/terminus');

                $results = $stack->run();

                if ($results->wasSuccessful()) {
                    $this->success(
                        'The terminus utility has successfully been installed!'
                    );
                }
            } catch (\Exception $exception) {
                $this->error($exception->getMessage());
            }
        } else {
            $this->note('The terminus utility has already been installed!');
        }
    }

    /**
     * Import the local database with the remote pantheon site.
     *
     * @param string $dbFile
     *   The local path to the database file.
     * @param string $siteEnv
     *   The pantheon site environment.
     */
    public function pantheonImport(
        string $dbFile = null,
        string $siteEnv = 'dev'
    ): void {

        try {
            $siteName = $this->getPantheonSiteName();

            if (
                !isset(Pantheon::environments()[$siteEnv])
                || in_array($siteEnv, ['test', 'live'])
            ) {
                throw new \RuntimeException(
                    'The environment is invalid! Only the dev environment is allowed at this time!'
                );
            }
            $dbFile = $dbFile ?? $this->exportEnvDatabase();

            if (!file_exists($dbFile)) {
                throw new \RuntimeException(
                    'The database file path is invalid!'
                );
            }

            $result = $this->terminusCommand()
                ->setSubCommand('import:database')
                ->args(["{$siteName}.{$siteEnv}", $dbFile])
                ->run();

            if ($result->wasSuccessful()) {
                $this->success(
                    'The database was successfully imported into the pantheon site.'
                );
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Run drush commands against the remote Drupal site.
     *
     * @aliases remote:drupal
     *
     * @param array $cmd
     *   An arbitrary drush command.
     */
    public function pantheonDrupal(array $cmd): void
    {
        try {
            $siteName = $this->getPantheonSiteName();
            $siteEnv = $this->askForPantheonSiteEnv();

            $command = $this->terminusCommand()
                ->setSubCommand('remote:drush')
                ->arg("{$siteName}.{$siteEnv}");

            if (!empty($cmd)) {
                $command->args(['--', implode(' ', $cmd)]);
            }
            $command->run();
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Create a site on the remote pantheon service.
     *
     * @param string|null $label
     *   Set the site human readable label.
     * @param string|null $upstream
     *   Set the site upstream.
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function pantheonCreateSite(
        string $label = null,
        string $upstream = null
    ): void {
        Pantheon::displayBanner();

        try {
            $label = $label ?? $this->doAsk((new Question(
                $this->formatQuestion('Input the site label')
            ))->setValidator(function ($value) {
                if (empty($value)) {
                    throw new \RuntimeException(
                        'The site label is required!'
                    );
                }
                return $value;
            }));

            $upstreamOptions = $this->getUpstreamOptions();

            $upstream = $upstream ?? $this->askChoice(
                'Select the site upstream',
                $upstreamOptions,
                'empty'
            );

            if (isset($upstream) && !isset($upstreamOptions[$upstream])) {
                throw new \InvalidArgumentException(
                    sprintf('The site upstream value is invalid!')
                );
            }
            $name = strtolower(strtr($label, ' ', '-'));

            /** @var \Robo\Collection\CollectionBuilder $command */
            $command = $this->terminusCommand()
                ->setSubCommand('site:create')
                ->args([
                    $name,
                    $label,
                    $upstream
                ]);

            if ($this->confirm('Associate the site with an organization?', false)) {
                $orgOptions = $this->getOrgOptions();

                if (count($orgOptions) !== 0) {
                    if ($org = $this->askChoice('Select an organization', $orgOptions)) {
                        $command->option('org', $org);
                    }
                }
            }
            $result = $command->run();

            if ($result->wasSuccessful()) {
                $this->success(
                    'The pantheon site was successfully created!'
                );
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Add a team member to the remote pantheon service.
     *
     * @param string $email
     *   The member email address.
     * @param string $role
     *   The member role name, e.g. (developer, team_member).
     */
    public function pantheonAddMember(
        string $email = null,
        string $role = 'team_member'
    ): void {
        Pantheon::displayBanner();

        try {
            $siteName = $this->getPantheonSiteName();

            $email = $email ?? $this->doAsk(
                (new Question(
                    $this->formatQuestion('Input the site user email address')
                ))->setValidator(function ($value) {
                    if (empty($value)) {
                        throw new \RuntimeException(
                            'The user email address is required!'
                        );
                    }
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        throw new \RuntimeException(
                            'The user email address is invalid!'
                        );
                    }
                    return $value;
                })
            );
            $roleOptions = ['developer', 'team_member'];

            if (!in_array($role, $roleOptions)) {
                $this->error(
                    sprintf('The user role is invalid!')
                );
                return;
            }

            $result = $this->terminusCommand()
                ->setSubCommand('site:team:add')
                ->args([$siteName, $email, $role])
                ->run();

            if ($result->wasSuccessful()) {
                $this->success(sprintf(
                    'The user with %s email has successfully been added!',
                    $email
                ));
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     *  Sync the remote pantheon database with the local environment.
     *
     * @param string $siteEnv
     *   The environment to sync the database from e.g (dev, test, live).
     * @param array $opts
     * @option $no-backup
     *   Don't create a backup prior to database retrieval.
     * @option $filename
     *   The filename of the remote database that's downloaded.
     */
    public function pantheonSync(string $siteEnv = null, $opts = [
        'no-backup' => false,
        'filename' => 'remote.db.sql.gz'
    ]): void
    {
        Pantheon::displayBanner();

        try {
            $siteName = $this->getPantheonSiteName();
            $siteEnv = $siteEnv ?? $this->askForPantheonSiteEnv();

            $collection = $this->collectionBuilder();

            if (!$opts['no-backup']) {
                $collection->addTask($this->terminusCommand()
                    ->setSubCommand('backup:create')
                    ->arg("{$siteName}.{$siteEnv}")
                    ->option('element', 'db'));
            }

            $dbBackupFilename = implode(DIRECTORY_SEPARATOR, [
                PxApp::projectTempDir(),
                $opts['filename']
            ]);

            if (file_exists($dbBackupFilename)) {
                $this->_remove($dbBackupFilename);
            }

            $collection->addTask($this->terminusCommand()
                ->setSubCommand('backup:get')
                ->arg("{$siteName}.{$siteEnv}")
                ->option('element', 'db')
                ->option('to', $dbBackupFilename));

            $backupResult = $collection->run();

            if ($backupResult->wasSuccessful()) {
                $this->importEnvDatabase($dbBackupFilename);
            } else {
                throw new \RuntimeException(sprintf(
                    'Unable to sync the %s.%s database with environment.',
                    $siteName,
                    $siteEnv
                ));
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }


    /**
     * Determine if terminus has been installed.
     *
     * @return bool
     *   Return true if terminus is installed; otherwise false.
     */
    protected function isTerminusInstalled(): bool
    {
        return $this->hasExecutable('terminus');
    }

    /**
     * Fetch the latest terminus release.
     *
     * @return string
     *   The latest release for terminus.
     */
    protected function fetchLatestTerminusRelease(): string
    {
        $task = $this->taskExec(
            'curl --silent "https://api.github.com/repos/pantheon-systems/terminus/releases/latest" \
            | perl -nle\'print $& while m#"tag_name": "\K[^"]*#g\''
        );

        $result = $task->printOutput(false)
            ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
            ->run();

        $version = trim($result->getMessage());

        return $version ?? self::TERMINUS_STABLE_VERSION;
    }

    /**
     * Import the environment database.
     *
     * @param string $sourceFile
     *   The database source file.
     * @param bool $sourceCleanUp
     *   Delete the database source file after import.
     *
     * @return bool
     *   Return true if the import was successful; otherwise false.
     */
    protected function importEnvDatabase(
        string $sourceFile,
        bool $sourceCleanUp = true
    ): bool {
        if ($command = $this->findCommand('db:import')) {
            $syncDbCollection = $this->collectionBuilder();

            if (!PxApp::getEnvironmentInstance()->isRunning()) {
                $syncDbCollection->addTask(
                    $this->taskSymfonyCommand(
                        $this->findCommand('env:start')
                    )
                );
            }
            $syncDbCollection->addTask(
                $this->taskSymfonyCommand($command)->arg(
                    'importFile',
                    $sourceFile
                )
            );
            $importResult = $syncDbCollection->run();

            if ($sourceCleanUp && $importResult->wasSuccessful()) {
                $this->_remove($sourceFile);
                return true;
            }
        }

        throw new \RuntimeException(
            "The database was not automatically synced. As the environment doesn't support that feature!"
        );
    }

    /**
     * Export the environment database.
     *
     * @return string
     *   Return the exported database file.
     */
    protected function exportEnvDatabase(): string
    {
        if ($command = $this->findCommand('db:export')) {
            $dbFilename = 'local.db';

            $dbExportFile = implode(
                DIRECTORY_SEPARATOR,
                [PxApp::projectTempDir(), $dbFilename]
            );
            $this->_remove($dbExportFile);

            $exportResult = $this->taskSymfonyCommand($command)
                ->arg('export_dir', PxApp::projectTempDir())
                ->opt('filename', $dbFilename)
                ->run();

            if ($exportResult->wasSuccessful()) {
                return "{$dbExportFile}.sql.gz";
            }
        }

        throw new \RuntimeException('Unable to export the local database.');
    }

    /**
     * Setup the Drupal pantheon integration.
     */
    protected function setupDrupal(): void
    {
        if (
            !PxApp::composerHasPackage('drupal/core')
            && !PxApp::composerHasPackage('drupal/core-recommended')
        ) {
            throw new \RuntimeException(
                'Install Drupal core prior to running the pantheon setup.'
            );
        }
        $drupalRoot = $this->findDrupalRoot() ?? $this->ask(
            'Input the Drupal root'
        );

        $drupalRootPath = implode(
            DIRECTORY_SEPARATOR,
            [PxApp::projectRootPath(), $drupalRoot]
        );

        if (!file_exists($drupalRootPath)) {
            throw new \RuntimeException(
                "The Drupal root path doesn't exist!"
            );
        }
        $collection = $this->collectionBuilder();
        $drupalDefault = "{$drupalRootPath}/sites/default";

        $collection
            ->addTask(
                $this->taskWriteToFile("{$drupalDefault}/settings.pantheon.php")
                    ->text(Pantheon::loadTemplateFile('drupal/settings.pantheon.txt'))
            );

        $collection->addTask(
            $this->taskWriteToFile("{$drupalDefault}/settings.php")
                ->append()
                ->appendUnlessMatches(
                    '/^include.+settings.pantheon.php";$/m',
                    Pantheon::loadTemplateFile('drupal/settings.include.txt')
                )
        );

        if ($this->confirm('Add Drupal quicksilver scripts?', true)) {
            $collection->addTaskList([
                $this->taskWriteToFile(PxApp::projectRootPath() . '/pantheon.yml')
                    ->append()
                    ->appendUnlessMatches(
                        '/^workflows:$/',
                        Pantheon::loadTemplateFile('drupal/pantheon.workflows.txt')
                    ),
                $this->taskWriteToFile("{$drupalRootPath}/private/hooks/afterSync.php")
                    ->text(Pantheon::loadTemplateFile('drupal/hooks/afterSync.txt')),
                $this->taskWriteToFile("{$drupalRootPath}/private/hooks/afterDeploy.php")
                    ->text(Pantheon::loadTemplateFile('drupal/hooks/afterDeploy.txt')),
            ]);
        }
        $result = $collection->run();

        if ($result->wasSuccessful()) {
            $this->success(
                sprintf('The pantheon setup was successful for the Drupal framework!')
            );
        }
    }

    /**
     * Find the Drupal root directory.
     *
     * @return string
     *   The Drupal root directory if found.
     */
    protected function findDrupalRoot(): string
    {
        $composerJson = PxApp::getProjectComposer();

        if (
            isset($composerJson['extra'])
            && isset($composerJson['extra']['installer-paths'])
        ) {
            foreach ($composerJson['extra']['installer-paths'] as $path => $types) {
                if (!in_array('type:drupal-core', $types)) {
                    continue;
                }
                return dirname($path, 1);
            }
        }

        return '';
    }

    /**
     * Get the pantheon site name.
     *
     * @return string
     *   Return the pantheon site name.
     */
    protected function getPantheonSiteName(): string
    {
        $siteName = $this->getPlugin()->getPantheonSite();

        if (!isset($siteName) || empty($siteName)) {
            throw new \RuntimeException(
                "The pantheon site name is required.\nRun the `vendor/bin/px config:set pantheon` command."
            );
        }

        return $siteName;
    }

    /**
     * Ask to select the pantheon site environment.
     *
     * @param string $default
     *   The default site environment.
     *
     * @param array $exclude
     * @return string
     *   The pantheon site environment.
     */
    protected function askForPantheonSiteEnv($default = 'dev', $exclude = []): string
    {
        return $this->askChoice(
            'Select the pantheon site environment',
            array_filter(Pantheon::environments(), function ($key) use ($exclude) {
                return !in_array($key, $exclude);
            }, ARRAY_FILTER_USE_KEY),
            $default
        );
    }

    /**
     * Get the pantheon organization options.
     *
     * @return array
     *   An array of organization options.
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function getOrgOptions(): array
    {
        return $this->buildCommandListOptions(
            'org:list',
            'name',
            'label'
        );
    }

    /**
     * Get the pantheon upstream options.
     *
     * @return array
     *   An array of upstream options.
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function getUpstreamOptions(): array
    {
        return $this->buildCommandListOptions(
            'upstream:list',
            'machine_name',
            'label'
        );
    }

    /**
     * Build the command list options.
     *
     * @param string $subCommand
     *   The sub-command to execute.
     * @param string $optionKey
     *   The property to use for the option key.
     * @param string $valueKey
     *   The property to use for the value key.
     *
     * @return array
     *   An array of command list options.
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function buildCommandListOptions(
        string $subCommand,
        string $optionKey,
        string $valueKey
    ): array {
        $options = [];

        foreach ($this->buildCommandListArray($subCommand) as $data) {
            if (!isset($data[$optionKey]) || !isset($data[$valueKey])) {
                continue;
            }
            $options[$data[$optionKey]] = $data[$valueKey];
        }
        ksort($options);

        return $options;
    }

    /**
     * Build the command list array output.
     *
     * @param string $subCommand
     *   The sub-command to execute.
     * @param int $cacheExpiration
     *   The cache expiration in seconds.
     *
     * @return array
     *   An array of the command output.
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function buildCommandListArray(string $subCommand, $cacheExpiration = 3600): array
    {
        $cacheKey = strtr($subCommand, ':', '.');

        return $this->pluginCache()->get(
            "commandOutput.{$cacheKey}",
            function (CacheItemInterface $item) use ($subCommand, $cacheExpiration) {
                $item->expiresAfter($cacheExpiration);

                $result = $this->terminusCommand()
                    ->setSubCommand($subCommand)
                    ->option('format', 'json')
                    ->printOutput(false)
                    ->silent(true)
                    ->run();

                if ($result->wasSuccessful()) {
                    return json_decode(
                        $result->getMessage(),
                        true
                    );
                }
            }
        ) ?? [];
    }

    /**
     * Determine if an executable exist.
     *
     * @param string $executable
     *   The name of the executable binary.
     *
     * @return bool
     *   Return true if executable exist; otherwise false.
     */
    protected function hasExecutable(string $executable): bool
    {
        return (new ExecutableFinder())->find($executable) !== null;
    }

    /**
     * Get the pantheon command type plugin.
     *
     * @return \Pr0jectX\PxPantheon\ProjectX\Plugin\CommandType\PantheonCommandType
     */
    protected function getPlugin(): PantheonCommandType
    {
        return $this->plugin;
    }

    /**
     * Retrieve the terminus command.
     *
     * @return \Pr0jectX\PxPantheon\Task\ExecCommand|\Robo\Collection\CollectionBuilder
     */
    protected function terminusCommand(): CollectionBuilder
    {
        return $this->taskExecCommand('terminus');
    }
}
