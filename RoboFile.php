<?php

declare(strict_types = 1);

use Consolidation\AnnotatedCommand\CommandResult;
use League\Container\Container as LeagueContainer;
use NuvoleWeb\Robo\Task\Config\Robo\loadTasks as ConfigLoader;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Common\ConfigAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Robo\Tasks;
use Robo\Collection\CollectionBuilder;
use Sweetchuck\LintReport\Reporter\BaseReporter;
use Sweetchuck\Robo\Git\GitTaskLoader;
use Sweetchuck\Robo\Phpcs\PhpcsTaskLoader;
use Sweetchuck\Robo\PhpMessDetector\PhpmdTaskLoader;
use Sweetchuck\Robo\Phpstan\PhpstanTaskLoader;
use Sweetchuck\Utils\Filter\ArrayFilterEnabled;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

class RoboFile extends Tasks implements LoggerAwareInterface, ConfigAwareInterface {

  use LoggerAwareTrait;
  use ConfigAwareTrait;
  use ConfigLoader;
  use GitTaskLoader;
  use PhpcsTaskLoader;
  use PhpmdTaskLoader;
  use PhpstanTaskLoader;

  protected array $composerInfo = [];

  protected string $packageVendor = '';

  protected string $packageName = '';

  protected string $binDir = 'vendor/bin';

  /**
   * @phpstan-var null|enum-git-hook
   */
  protected ?string $gitHook = NULL;

  protected ?string $envVarNamePrefix = NULL;

  /**
   * @phpstan-var null|enum-environment-type
   */
  protected ?string $environmentType = NULL;

  /**
   * @phpstan-var null|enum-environment-name
   */
  protected ?string $environmentName = NULL;

  protected string $logDir = './reports';

  /**
   * RoboFile constructor.
   */
  public function __construct() {
    putenv('COMPOSER_DISABLE_XDEBUG_WARN=1');
    $this
      ->initComposerInfo()
      ->initEnvVarNamePrefix()
      ->initEnvironmentTypeAndName();
  }

  /**
   * @hook pre-command @initLintReporters
   */
  public function onHookPreCommandInitLintReporters() {
    $container = $this->getContainer();
    if (!($container instanceof LeagueContainer)) {
      return;
    }

    foreach (BaseReporter::getServices() as $name => $class) {
      if ($container->has($name)) {
        continue;
      }

      $container
        ->add($name, $class)
        ->setShared(false);
    }
  }

  /**
   * @command config:export
   */
  public function cmdConfigExportExecute(
    array $options = [
      'format' => 'yaml',
    ]
  ): CommandResult {
    return CommandResult::data($this->getConfig()->export());
  }

  /**
   * Run code style checkers.
   *
   * @command lint
   *
   * @initLintReporters
   */
  public function cmdLintExecute(): CollectionBuilder {
    return $this
      ->collectionBuilder()
      ->addTask($this->taskComposerValidate())
      ->addTask($this->getTaskPhpcsLint());
  }

  /**
   * @command lint:phpcs
   *
   * @initLintReporters
   */
  public function cmdLintPhpcsExecute(): CollectionBuilder {
    return $this->getTaskPhpcsLint();
  }

  /**
   * @command lint:phpmd
   *
   * @initLintReporters
   */
  public function cmdLintPhpmdExecute(): CollectionBuilder {
    return $this->getTaskPhpmdLint();
  }

  /**
   * Run all kind of tests.
   *
   * @command test
   */
  public function cmdTestExecute(array $suiteNames): CollectionBuilder {
    return $this->getTaskPhpunitRun($suiteNames);
  }

  /**
   * Run PHPUnit tests.
   *
   * @command test:phpunit
   */
  public function cmdTestPhpunitExecute(array $suiteNames): CollectionBuilder {
    return $this->getTaskPhpunitRun($suiteNames);
  }

  /**
   * Git "pre-commit" hook callback.
   *
   * @command githook:pre-commit
   *
   * @hidden
   *
   * @initLintReporters
   */
  public function cmdGithookPreCommitExecute(): CollectionBuilder {
    $this->gitHook = 'pre-commit';

    return $this
      ->collectionBuilder()
      ->addTask($this->taskComposerValidate())
      ->addTask($this->getTaskPhpcsLint())
      ->addTask($this->getTaskPhpunitRun(['Unit']));
  }

  /**
   * @return $this
   */
  protected function initEnvironmentTypeAndName() {
    $this->environmentType = getenv($this->getEnvVarName('environment_type')) ?: '';
    $this->environmentName = getenv($this->getEnvVarName('environment_name')) ?: '';

    if (!$this->environmentType) {
      if (getenv('CI') === 'true') {
        // Travis, GitLab and CircleCI.
        $this->environmentType = 'ci';
      }
      elseif (getenv('JENKINS_HOME')) {
        $this->environmentType = 'ci';
        if (!$this->environmentName) {
          $this->environmentName = 'jenkins';
        }
      }
    }

    if (!$this->environmentName && $this->environmentType === 'ci') {
      if (getenv('GITLAB_CI') === 'true') {
        $this->environmentName = 'gitlab';
      }
      elseif (getenv('TRAVIS') === 'true') {
        $this->environmentName = 'travis';
      }
      elseif (getenv('CIRCLECI') === 'true') {
        $this->environmentName = 'circleci';
      }
    }

    if (!$this->environmentType) {
      $this->environmentType = 'dev';
    }

    if (!$this->environmentName) {
      $this->environmentName = 'local';
    }

    return $this;
  }

  protected function getEnvVarName(string $name): string {
    return "{$this->envVarNamePrefix}_" . strtoupper($name);
  }

  /**
   * @return $this
   */
  protected function initEnvVarNamePrefix() {
    $this->envVarNamePrefix = strtoupper(
      str_replace('-', '_', $this->packageName)
    );

    return $this;
  }

  /**
   * @return $this
   */
  protected function initComposerInfo() {
    $composerFileName = getenv('COMPOSER') ?: 'composer.json';
    if ($this->composerInfo || !is_readable($composerFileName)) {
      return $this;
    }

    $this->composerInfo = json_decode(
      file_get_contents($composerFileName) ?: '{}',
      TRUE,
    );
    [$this->packageVendor, $this->packageName] = explode('/', $this->composerInfo['name']);

    if (!empty($this->composerInfo['config']['bin-dir'])) {
      $this->binDir = $this->composerInfo['config']['bin-dir'];
    }

    return $this;
  }

  protected function getTaskPhpcsLint(): CollectionBuilder {
    $options = [
      'failOn' => 'warning',
      'lintReporters' => [
        'lintVerboseReporter' => NULL,
      ],
    ];

    if ($this->environmentType === 'ci'
      && $this->environmentName === 'jenkins'
    ) {
      $options['failOn'] = 'never';
      $options['lintReporters']['lintCheckstyleReporter'] = $this
        ->getContainer()
        ->get('lintCheckstyleReporter')
        ->setDestination("{$this->logDir}/machine/checkstyle/phpcs.drupal.xml");
    }

    if ($this->gitHook === 'pre-commit') {
      return $this
        ->collectionBuilder()
        ->addTask(
          $this
            ->taskPhpcsParseXml()
            ->setAssetNamePrefix('phpcsXml.')
        )
        ->addTask(
          $this
            ->taskGitReadStagedFiles()
            ->setCommandOnly(TRUE)
            ->setWorkingDirectory('.')
            ->deferTaskConfiguration('setPaths', 'phpcsXml.files')
        )
        ->addTask(
          $this
            ->taskPhpcsLintInput($options)
            ->deferTaskConfiguration('setFiles', 'files')
            ->deferTaskConfiguration('setIgnore', 'phpcsXml.exclude-patterns')
        );
    }

    return $this->taskPhpcsLintFiles($options);
  }

  protected function getTaskPhpunitRun(array $suiteNames = []): CollectionBuilder {
    if (!$suiteNames) {
      $suiteNames = ['all'];
    }

    $phpExecutables = array_filter(
      $this->getConfig()->get('php.executables'),
      new ArrayFilterEnabled(),
    );

    $cb = $this->collectionBuilder();
    foreach ($suiteNames as $suiteName) {
      foreach ($phpExecutables as $phpExecutable) {
        $cb->addTask($this->getTaskPhpunitRunPhp($suiteName, $phpExecutable));
      }
    }

    return $cb;
  }

  protected function getTaskPhpunitRunPhp(string $suite, array $php): CollectionBuilder {
    if ($suite === '') {
      $suite = 'all';
    }

    $cmdPattern = '';
    $cmdArgs = [];
    foreach ($php['envVars'] ?? [] as $envName => $envValue) {
      $cmdPattern .= "{$envName}";
      if ($envValue === NULL) {
        $cmdPattern .= ' ';
      }
      else {
        $cmdPattern .= '=%s ';
        $cmdArgs[] = escapeshellarg($envValue);
      }
    }

    $cmdPattern .= '%s';
    $cmdArgs[] = $php['command'];

    $cmdPattern .= ' %s';
    $cmdArgs[] = escapeshellcmd("{$this->binDir}/phpunit");

    $cmdPattern .= ' --verbose';

    if ($suite !== 'all') {
      $cmdPattern .= ' --testsuite %s';
      $cmdArgs[] = escapeshellarg($suite);

      $cmdPattern .= ' --coverage-html=%s';
      $cmdArgs[] = escapeshellarg("{$this->logDir}/human/coverage/$suite/html");

      $cmdPattern .= ' --coverage-clover=%s';
      $cmdArgs[] = escapeshellarg("{$this->logDir}/machine/coverage/$suite/clover.xml");

      $cmdPattern .= ' --coverage-xml=%s';
      $cmdArgs[] = escapeshellarg("{$this->logDir}/machine/coverage/$suite/xml");

      $cmdPattern .= ' --coverage-php=%s';
      $cmdArgs[] = escapeshellarg("{$this->logDir}/machine/coverage-php/$suite.php");

      $cmdPattern .= ' --testdox-html=%s';
      $cmdArgs[] = escapeshellarg("{$this->logDir}/human/unit/junit.$suite.html");

      $cmdPattern .= ' --log-junit=%s';
      $cmdArgs[] = escapeshellarg("{$this->logDir}/machine/unit/junit.$suite.xml");
    }

    return $this
      ->collectionBuilder()
      ->addTask($this->taskExec(vsprintf($cmdPattern, $cmdArgs)));
  }

  protected function getTaskPhpmdLint(): CollectionBuilder {
    $ruleSetName = 'custom';

    $task = $this
      ->taskPhpmdLintFiles()
      ->setInputFile("./rulesets/$ruleSetName.include-pattern.txt")
      ->setRuleSetFileNames([$ruleSetName])
      ->setOutput($this->output());

    $excludeFileName = "./rulesets/$ruleSetName.exclude-pattern.txt";
    if (file_exists($excludeFileName)) {
      $task->addExcludePathsFromFile($excludeFileName);
    }

    return $task;
  }

  protected function errorOutput(): ?OutputInterface {
    $output = $this->output();

    return ($output instanceof ConsoleOutputInterface)
      ? $output->getErrorOutput() : $output;
  }

}
