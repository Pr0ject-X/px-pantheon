<?php

declare(strict_types=1);

namespace Pr0jectX\PxPantheon\ProjectX\Plugin\CommandType\Commands;

use Pr0jectX\Px\CommonCommandTrait;
use Pr0jectX\Px\Contracts\DatabaseInterface;
use Pr0jectX\Px\Database\Database;
use Pr0jectX\Px\Database\DatabaseOpener;
use Pr0jectX\Px\ProjectX\Plugin\PluginCommandTaskBase;
use Pr0jectX\Px\ProjectX\Plugin\PluginInterface;
use Pr0jectX\Px\PxApp;
use Pr0jectX\PxPantheon\Pantheon;
use Pr0jectX\Px\Task\LoadTasks as PxTasks;
use Psr\Cache\CacheItemInterface;
use Robo\Collection\CollectionBuilder;
use Robo\Contract\VerbosityThresholdInterface;
use Robo\Exception\TaskException;
use Robo\Result;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Define the pantheon command.
 */
class PantheonCommand extends PluginCommandTaskBase
{
    use PxTasks;
    use CommonCommandTrait;

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
     * Set up the pantheon project scaffolding.
     */
    public function pantheonSetup(): void
    {
        Pantheon::displayBanner();

        try {
            $this
                ->setupPantheonFramework()
                ->setupPantheonConfiguration();
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Display the pantheon developer information.
     *
     * @param null $siteEnv
     *   The pantheon site environment.
     */
    public function pantheonInfo($siteEnv = null, $opts = [
        'single' => false,
        'git-command' => false,
        'mysql-command' => false,
        'redis-command' => false,
    ]): ?Result
    {
        try {
            if (!$opts['quiet']) {
                Pantheon::displayBanner();
            }
            $siteName = $this->getPantheonSiteName();
            $siteEnv = $siteEnv ?? $this->askForPantheonSiteEnv();

            $siteCommand = $this->terminusCommand()
                ->printOutput(!$opts['quiet'])
                ->setSubCommand('connection:info')
                ->arg("$siteName.$siteEnv");

            $commands = array_filter([
                $opts['git-command'] ? 'git_command' : null,
                $opts['mysql-command'] ? 'mysql_command' : null,
                $opts['redis-command'] ? 'redis_command' : null,
            ]);

            if (!empty($commands)) {
                if ($opts['single'] === true) {
                    $siteCommand->option('field', current($commands));
                } else {
                    $siteCommand->option('fields', implode(',', $commands));
                }
            }
            return $siteCommand->run();
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }

        return null;
    }

    /**
     * Install the terminus command utility system-wide.
     *
     * @param null|string $version
     *   The terminus version to install.
     */
    public function pantheonInstallTerminus(?string $version = null): void
    {
        if ($this->isTerminusInstalled()) {
            $this->note('The terminus utility has already been installed!');
        } else {
            try {
                $userDir = PxApp::userDir();
                $version = $version ?? $this->fetchLatestTerminusRelease();
                $baseUrl = 'https://github.com/pantheon-systems/terminus/releases/download';

                $stack = $this->taskExecStack()
                    ->exec("mkdir -p $userDir/terminus")
                    ->exec("cd $userDir/terminus")
                    ->exec("curl -L $baseUrl/$version/terminus.phar --output terminus")
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
        }
    }

    /**
     * Open a MySQL connect with the pantheon site.
     *
     * @param string|null $siteEnv
     *   The pantheon site environment.
     * @param array $opts
     *
     * @option launch
     *   Open the MySQL connection using a database application.
     * @option app-name
     *   Input database application name to use for the connection, requires --launch.
     *
     * @return void
     */
    public function pantheonMysql(?string $siteEnv = null, array $opts = [
        'launch' => false,
        'app-name' => InputOption::VALUE_REQUIRED
    ]): void
    {
        try {
            $siteName = $this->getPantheonSiteName();
            $siteEnv = $siteEnv ?? $this->askForPantheonSiteEnv();
            $appName = $opts['app-name'];

            if ($this->wakePantheonEnvironment($siteName, $siteEnv)) {
                    $command = isset($opts['launch']) && $opts['launch']
                    ? $this->getPantheonMysqlOpenCommand($siteName, $siteEnv, $appName)
                    : $this->getPantheonMysqlCommand($siteName, $siteEnv);

                $this->taskExec($command)->run();
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }



    /**
     * Import the local database into the pantheon site.
     *
     * @param string|null $dbFile
     *   The local path to the database file.
     * @param string $siteEnv
     *   The pantheon site environment.
     */
    public function pantheonImport(
        ?string $dbFile = null,
        string $siteEnv = 'dev'
    ): void {
        try {
            if (
                !isset(Pantheon::environments()[$siteEnv])
                || in_array($siteEnv, ['test', 'live'])
            ) {
                throw new \InvalidArgumentException(
                    'The environment is invalid! Only the dev environment is allowed at this time!'
                );
            }
            $dbFile = $dbFile ?? $this->exportLocalDatabase();

            if (!file_exists($dbFile)) {
                throw new \InvalidArgumentException(
                    'The database file path is invalid!'
                );
            }
            $siteName = $this->getPantheonSiteName();

            if ($mysqlCommand = $this->getPantheonMysqlCommand($siteName, $siteEnv)) {
                $importCommand = !$this->isGzipped($dbFile)
                    ? "$mysqlCommand < $dbFile"
                    : "gunzip -c $dbFile | $mysqlCommand";

                $importResult = $this->taskExec($importCommand)->run();

                if ($importResult->wasSuccessful()) {
                    $this->success(
                        'The database was successfully imported into the pantheon site.'
                    );
                }
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Run a drush command on the pantheon site.
     *
     * @aliases remote:drush, pantheon:drupal
     *
     * @param array $cmd
     *   An arbitrary drush command.
     */
    public function pantheonDrush(array $cmd): void
    {
        try {
            $siteName = $this->getPantheonSiteName();
            $siteEnv = $this->askForPantheonSiteEnv();

            $command = $this->terminusCommand()
                ->setSubCommand('remote:drush')
                ->arg("$siteName.$siteEnv");

            if (!empty($cmd)) {
                $command->args(['--', implode(' ', $cmd)]);
            }
            $command->run();
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Create a new site on the pantheon platform.
     *
     * @param string|null $label
     *   Set the site human-readable label.
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
                    throw new \InvalidArgumentException(
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
                    'The site upstream value is invalid!'
                );
            }
            $name = strtolower(str_replace(' ', '-', $label));

            $command = $this->terminusCommand()
                ->setSubCommand('site:create')
                ->args([
                    $name,
                    $label,
                    $upstream
                ]);

            if ($this->confirm('Associate the site with an organization?')) {
                $orgOptions = $this->getOrgOptions();

                if (
                    (count($orgOptions) !== 0)
                    && $org = $this->askChoice('Select an organization', $orgOptions)
                ) {
                    $command->option('org', $org);
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
     * Add a team member to the pantheon platform.
     *
     * @param string|null $email
     *   The member email address.
     * @param string $role
     *   The member role name, e.g. (developer, team_member).
     */
    public function pantheonAddMember(
        ?string $email = null,
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
                        throw new \InvalidArgumentException(
                            'The user email address is required!'
                        );
                    }
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        throw new \InvalidArgumentException(
                            'The user email address is invalid!'
                        );
                    }
                    return $value;
                })
            );
            $roleOptions = ['developer', 'team_member'];

            if (!in_array($role, $roleOptions)) {
                $this->error(
                    'The user role is invalid!'
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
     * Sync the pantheon site database with the local environment.
     *
     * @param string|null $siteEnv
     *   The environment to sync the database from e.g (dev, test, live).
     * @param array $opts
     * @option $no-backup
     *   Don't create a backup prior to database retrieval.
     * @option $filename
     *   The filename of the remote database that's downloaded.
     */
    public function pantheonSync(?string $siteEnv = null, array $opts = [
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
                    ->arg("$siteName.$siteEnv")
                    ->option('element', 'db'));
            }

            $dbBackupFilename = implode(DIRECTORY_SEPARATOR, [
                PxApp::projectTempDir(),
                $opts['filename']
            ]);

            if (
                !file_exists($dbBackupFilename)
                || $this->confirm('Download the pantheon database again?')
            ) {
                $collection->addTask($this->taskFilesystemStack()->remove($dbBackupFilename));
                $collection->addTask($this->terminusCommand()
                    ->setSubCommand('backup:get')
                    ->arg("$siteName.$siteEnv")
                    ->option('element', 'db')
                    ->option('to', $dbBackupFilename));
            }
            $backupResult = $collection->run();

            if (!$backupResult->wasSuccessful()) {
                throw new \InvalidArgumentException(sprintf(
                    'Unable to sync the %s.%s database with environment.',
                    $siteName,
                    $siteEnv
                ));
            }
            $this->importLocalDatabase($dbBackupFilename);
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Set up the pantheon configuration.
     *
     * @return $this
     */
    protected function setupPantheonConfiguration(): self
    {
        $plugin = $this->getPlugin();
        $phpVersion = $plugin->getPantheonPhpVersion();

        if (!isset($phpVersion)) {
            throw new \InvalidArgumentException(
                'The pantheon PHP version has not been defined!'
            );
        }

        $this->taskWriteToFile(PxApp::projectRootPath() . '/pantheon.yml')
            ->text(Pantheon::loadTemplateFile('pantheon.yml'))
            ->place('PHP_VERSION', $phpVersion)
            ->run();

        return $this;
    }

    /**
     * Set up the pantheon framework.
     *
     * @return $this
     */
    protected function setupPantheonFramework(): self
    {
        $plugin = $this->getPlugin();
        $framework = $plugin->getPantheonFramework();

        if (!isset($framework)) {
            throw new \InvalidArgumentException(
                'The pantheon framework has not been defined!'
            );
        }

        if ($framework === 'drupal') {
            $this->setupFrameworkDrupal();
        }

        return $this;
    }

    /**
     * Get the pantheon MySQL database.
     *
     * @param string $siteName
     *   The pantheon site name.
     * @param string $siteEnv
     *   The pantheon site environment.
     *
     * @return \Pr0jectX\Px\Contracts\DatabaseInterface
     *   The pantheon MySQL database instance.
     *
     * @throws \JsonException
     */
    protected function getPantheonMysqlDatabase(
        string $siteName,
        string $siteEnv
    ): DatabaseInterface {
        $task = $this->terminusCommand()
            ->setSubCommand('connection:info')
            ->arg("$siteName.$siteEnv")
            ->option('--format', 'json')
            ->option('--fields', 'mysql_host,mysql_port,mysql_database,mysql_username,mysql_password');

        $database = new Database();
        $mysqlResult = $this->runSilentCommand($task);

        if ($mysqlResult->wasSuccessful()) {
            $mysqlData = json_decode(
                $mysqlResult->getMessage(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $database
                ->setType('mysql')
                ->setHost($mysqlData['mysql_host'])
                ->setPort($mysqlData['mysql_port'])
                ->setDatabase($mysqlData['mysql_database'])
                ->setUsername($mysqlData['mysql_username'])
                ->setPassword($mysqlData['mysql_password']);
        }

        return $database;
    }

    /**
     * Get pantheon mysql open command.
     *
     * @param string $siteName
     *   The pantheon site name.
     * @param string $siteEnv
     *   The pantheon site environment.
     * @param string|null $appName
     *   The database application name.
     *
     * @return string|null
     *   The mysql open command.
     *
     * @throws \JsonException
     */
    protected function getPantheonMysqlOpenCommand(
        string $siteName,
        string $siteEnv,
        string $appName = null
    ): ?string {
        $database = $this->getPantheonMysqlDatabase($siteName, $siteEnv);

        if (!$database->isValid()) {
            throw new \RuntimeException(
                'The pantheon database is invalid!'
            );
        }
        $databaseOpener = new DatabaseOpener();
        $databaseOptions = $databaseOpener->applicationOptions();

        $appDefault = array_key_exists(DatabaseOpener::DEFAULT_APPLICATION, $databaseOptions)
            ? DatabaseOpener::DEFAULT_APPLICATION
            : array_key_first($databaseOptions);

        if (!isset($appName, $databaseOptions[$appName])) {
            $appName = count($databaseOptions) === 1
                ? array_key_first($databaseOptions)
                : $this->askChoice(
                    'Select the database application to launch',
                    $databaseOptions,
                    $appDefault
                );
        }

        return $databaseOpener->command($appName, $database);
    }

    /**
     * Get the pantheon MySQL command.
     *
     * @param string $siteName
     *   The pantheon site name.
     * @param string $siteEnv
     *   The pantheon site environment.
     *
     * @return string|null
     *   The pantheon MySQL command.
     */
    protected function getPantheonMysqlCommand(
        string $siteName,
        string $siteEnv
    ): ?string {
         $task = $this->terminusCommand()
            ->setSubCommand('connection:info')
            ->arg("$siteName.$siteEnv")
            ->option('--field', 'mysql_command');

        $mysqlResult = $this->runSilentCommand($task);

        if ($mysqlResult->wasSuccessful()) {
            return $mysqlResult->getMessage();
        }

        return null;
    }

    /**
     * Wake up a pantheon environment.
     *
     * @param string $siteName
     *   The pantheon site name.
     * @param string $siteEnv
     *   The pantheon site environment.
     *
     * @return bool
     *   Return true if the wakeup was successful; otherwise false.
     */
    protected function wakePantheonEnvironment(
        string $siteName,
        string $siteEnv
    ): bool {
        $task = $this->terminusCommand()
            ->setSubCommand('env:wake')
            ->arg("$siteName.$siteEnv");

        $result = $this->runSilentCommand($task);

        if ($result->wasSuccessful()) {
            return true;
        }

        return false;
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
     * Import the local environment database.
     *
     * @param string $sourceFile
     *   The database source file.
     */
    protected function importLocalDatabase(
        string $sourceFile
    ): void {
        if ($command = $this->findCommand('db:import')) {
            $collection = $this->collectionBuilder();

            if (!PxApp::getEnvironmentInstance()->isRunning()) {
                $collection->addTask(
                    $this->taskSymfonyCommand(
                        $this->findCommand('env:start')
                    )
                );
            }
            $collection->addTask(
                $this->taskSymfonyCommand($command)->arg(
                    'importFile',
                    $sourceFile
                )
            );
            $importResult = $collection->run();

            if (!$importResult->wasSuccessful()) {
                throw new \InvalidArgumentException(
                    "The database was not synced. As the environment doesn't support that feature!"
                );
            }
        }
    }

    /**
     * Export the local environment database.
     *
     * @param string $dbFilename
     *   The exported database filename.
     *
     * @return string
     *   Return the exported database file.
     *
     * @throws \Robo\Exception\TaskException
     */
    protected function exportLocalDatabase(string $dbFilename = 'local.db'): ?string
    {
        if ($command = $this->findCommand('db:export')) {
            $dbExportFile = implode(
                DIRECTORY_SEPARATOR,
                [PxApp::projectTempDir(), $dbFilename]
            );
            $this->_remove($dbExportFile);

            $exportResult = $this->taskSymfonyCommand($command)
                ->arg('export_dir', PxApp::projectTempDir())
                ->opt('filename', $dbFilename)
                ->run();

            if (!$exportResult->wasSuccessful()) {
                throw new TaskException(
                    $exportResult->getTask(),
                    'Unable to export the local database.'
                );
            }

            return "$dbExportFile.sql.gz";
        }

        return null;
    }

    /**
     * Set up pantheon to work with the Drupal framework.
     */
    protected function setupFrameworkDrupal(): void
    {
        if (
            !PxApp::composerHasPackage('drupal/core')
            && !PxApp::composerHasPackage('drupal/core-recommended')
        ) {
            throw new \InvalidArgumentException(
                'Install Drupal core prior to running the pantheon setup.'
            );
        }
        $collection = $this->collectionBuilder();

        if (!pxApp::composerHasPackage('pantheon-systems/drupal-integrations')) {
            $collection->addTask(
                $this->taskComposerConfig()
                    ->arg('extra.drupal-scaffold.allowed-packages')
                    ->arg('["pantheon-systems/drupal-integrations"]')
                    ->option('--json')
                    ->option('--merge')
            );
            $collection->addTask(
                $this->taskComposerRequire()
                    ->arg('pantheon-systems/drupal-integrations')
                    ->option('--with-all-dependencies')
            );
        }

        if ($this->confirm('Add Drupal quicksilver scripts?', true)) {
            $collection->addTask(
                $this->taskWriteToFile(PxApp::projectRootPath() . '/pantheon.yml')
                    ->append()
                    ->appendUnlessMatches(
                        '/^workflows:$/',
                        Pantheon::loadTemplateFile('drupal/pantheon.workflows.txt')
                    ),
            );

            if (!pxApp::composerHasPackage('pr0ject-x/pantheon-drupal-quicksilver')) {
                $collection->addTask($this->taskComposerConfig()
                    ->arg('extra.installer-paths')
                    ->arg('{"web/private/scripts/quicksilver/{$name}/": ["type:quicksilver-script"]}')
                    ->option('--json')
                    ->option('--merge'));
                $collection->addTask(
                    $this->taskComposerRequire()->arg('pr0ject-x/pantheon-drupal-quicksilver'),
                );
            }
        }
        $result = $collection->run();

        if ($result->wasSuccessful()) {
            $this->success(
                'The pantheon setup was successful for the Drupal framework!'
            );
        }
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

        if (empty($siteName)) {
            throw new \InvalidArgumentException(
                "The pantheon site name is required.\nRun the `vendor/bin/px config:set pantheon` command."
            );
        }

        return $siteName;
    }

    /**
     * Ask to select the pantheon site environment.
     *
     * @param string $siteEnv
     *   The pantheon site environment.
     * @param array $exclude
     *   An array of site environments to exclude.
     *
     * @return string
     *   The pantheon site environment.
     */
    protected function askForPantheonSiteEnv(string $siteEnv = 'dev', array $exclude = []): string
    {
        $environments = array_filter(array_merge(
            Pantheon::environments(),
            $this->getPantheonMultiDevSites()
        ));

        return $this->askChoice(
            'Select the pantheon site environment',
            array_filter($environments, static function ($key) use ($exclude) {
                return !in_array($key, $exclude, true);
            }, ARRAY_FILTER_USE_KEY),
            $siteEnv
        );
    }

    /**
     * Get the pantheon multi-dev sites.
     *
     * @return array
     *   An array of the pantheon multi-dev sites.
     */
    protected function getPantheonMultiDevSites(): array
    {
        try {
            $siteName = $this->getPantheonSiteName();
            $results = $this->runSilentCommand($this->terminusCommand()
                ->setSubCommand('multidev:list')
                ->arg($siteName)
                ->option('--quiet')
                ->option('--field', 'id'));

            if ($results->wasSuccessful()) {
                $sites = array_map('trim', explode(
                    "\n",
                    $results->getMessage()
                ));
                return !empty($sites)
                    ? array_combine($sites, $sites)
                    : [];
            }
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());
        }

        return [];
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
            if (!isset($data[$optionKey], $data[$valueKey])) {
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
    protected function buildCommandListArray(string $subCommand, int $cacheExpiration = 3600): array
    {
        $cacheKey = str_replace(':', '.', $subCommand);

        return $this->pluginCache()->get(
            "commandOutput.$cacheKey",
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
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                }

                return null;
            }
        ) ?? [];
    }

    /**
     * Determine if the file is gzipped.
     *
     * @param string $filepath
     *   The fully qualified path to the file.
     *
     * @return bool
     */
    protected function isGzipped(string $filepath): bool
    {
        if (!file_exists($filepath)) {
            throw new \InvalidArgumentException(
                'The file path does not exist.'
            );
        }
        $contentType = mime_content_type($filepath);

        $mimeType = substr(
            $contentType,
            strpos($contentType, '/') + 1
        );

        return $mimeType === 'x-gzip' || $mimeType === 'gzip';
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
    protected function getPlugin(): PluginInterface
    {
        return $this->plugin;
    }

    /**
     * Retrieve the terminus command.
     *
     * @return \Pr0jectX\Px\Task\ExecCommand|\Robo\Collection\CollectionBuilder
     */
    protected function terminusCommand(): CollectionBuilder
    {
        return $this->taskExecCommand('terminus');
    }
}
