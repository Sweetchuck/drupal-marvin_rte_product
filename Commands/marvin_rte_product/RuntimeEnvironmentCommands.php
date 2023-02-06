<?php

declare(strict_types = 1);

namespace Drush\Commands\marvin_rte_product;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandResult;
use Drush\Commands\marvin\CommandsBase;
use Robo\Collection\CollectionBuilder;

class RuntimeEnvironmentCommands extends CommandsBase {

  /**
   * @command marvin:runtime-environment:list
   *
   * @option string $format
   *   Default: yaml
   */
  public function cmdMarvinRuntimeEnvironmentListExecute(
    array $options = [
      'format' => 'yaml',
    ]
  ): CommandResult {
    $exitCode = 0;
    $data = $this->getRuntimeEnvironments();

    return CommandResult::dataWithExitCode($data, $exitCode);
  }

  /**
   * @hook on-event marvin:runtime-environment:list
   */
  public function onEventMarvinRuntimeEnvironmentList(): array {
    $list = [];
    $root = $this->getProjectRootDir();
    $config = $this->getConfig()->get('marvin.runtime_environments') ?: [];

    if (!empty($config['host']['enabled'])) {
      $list['host'] = array_replace_recursive(
        [
          'weight' => -99,
          // @todo Store the sites somewhere else,
          // because sites aren't change when the runtime_environment changes.
          'description' => 'Uses the host machine without any virtualization',
        ],
        $config['host'],
      );
    }

    if (!empty($config['ddev']['enabled'])
      && file_exists("$root/.ddev/config.yaml")
    ) {
      $list['ddev'] = [
          'description' => 'Runtime environment provided by DDev',
      ];
    }

    return $list;
  }

  /**
   * @hook validate @marvinRuntimeEnvironmentId
   *
   * @link https://github.com/consolidation/annotated-command#validate-hook
   */
  public function onHookValidateMarvinRuntimeEnvironmentId(CommandData $commandData): void {
    $inputLocators = explode(
      ',',
      trim($commandData->annotationData()->get('marvinRuntimeEnvironmentId')),
    );

    $runtimeEnvironments = $this->getRuntimeEnvironments();

    $input = $commandData->input();
    foreach ($inputLocators as $inputLocator) {
      [$inputType, $inputName] = explode('.', $inputLocator);

      $rteId = $inputType === 'option' ?
        $input->getOption($inputName)
        : $input->getArgument($inputName);

      if (empty($rteId)) {
        continue;
      }

      if (!array_key_exists($rteId, $runtimeEnvironments)) {
        throw new \InvalidArgumentException(
          sprintf(
            'value %s is invalid for %s. List valid values with command: %s',
            $rteId,
            $inputLocator,
            'marvin:runtime-environment:list',
          ),
          1,
        );
      }
    }
  }

  /**
   * @command marvin:runtime-environment:switch
   *
   * @bootstrap none
   *
   * @marvinRuntimeEnvironmentId
   *   argument.rte_id
   */
  public function cmdMarvinRuntimeEnvironmentSwitchExecute(string $rte_id): CollectionBuilder {
    $runtimeEnvironments = $this->getRuntimeEnvironments();

    return $this->delegate(
      'runtime-environment:switch',
      $runtimeEnvironments[$rte_id],
    );
  }

}
