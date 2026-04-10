<?php

namespace Drupal\dc_config_import\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Service for importing Drupal configuration from YAML.
 */
class DrupalConfigImporter {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Default allowed config prefixes.
   *
   * @var array
   */
  protected const DEFAULT_ALLOWED_PREFIXES = [
    'views.view.',
    'image.style.',
    'system.menu.',
    'node.type.',
    'field.field.',
    'field.storage.',
    'core.entity_view_display.',
    'core.entity_form_display.',
    'core.entity_view_mode.',
    'core.entity_form_mode.',
    'taxonomy.vocabulary.',
    'block.block.',
    'user.role.',
    'filter.format.',
    'editor.editor.',
    'core.base_field_override.',
    'pathauto.pattern.',
    'metatag.metatag_defaults.',
    'responsive_image.styles.',
    'graphql_compose.settings',
    'decoupled_preview_iframe.',
    'dc_revalidate.',
  ];

  /**
   * Config names that are always blocked.
   *
   * @var array
   */
  protected const BLOCKED_PREFIXES = [
    'core.extension',
    'system.site',
    'system.performance',
    'system.cron',
    'system.logging',
    'system.file',
    'key_value.',
    'update.settings',
    'dblog.settings',
  ];

  /**
   * Constructs a DrupalConfigImporter object.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Import configuration from the parsed request data.
   *
   * @param array $data
   *   The decoded JSON data with a "configs" array.
   * @param bool $preview
   *   Whether to run in preview mode (validate without applying).
   *
   * @return array
   *   Result array with success, summary, warnings, imported, and skipped.
   */
  public function import(array $data, bool $preview = FALSE): array {
    $result = [
      'success' => true,
      'summary' => [],
      'warnings' => [],
      'imported' => 0,
      'skipped' => 0,
    ];

    $configs = $data['configs'] ?? [];

    if (empty($configs)) {
      $result['warnings'][] = 'No configs provided in request.';
      return $result;
    }

    foreach ($configs as $index => $configItem) {
      $name = $configItem['name'] ?? null;
      $yaml = $configItem['yaml'] ?? null;

      if (empty($name) || empty($yaml)) {
        $result['warnings'][] = "Config item $index: missing 'name' or 'yaml' key. Skipped.";
        $result['skipped']++;
        continue;
      }

      // Validate config name against allowlist/blocklist.
      $nameValidation = $this->validateConfigName($name);
      if ($nameValidation !== TRUE) {
        $result['warnings'][] = "Config '$name': $nameValidation. Skipped.";
        $result['skipped']++;
        continue;
      }

      // Parse and validate YAML.
      try {
        $configData = $this->parseAndValidateYaml($yaml);
      }
      catch (\Exception $e) {
        $result['warnings'][] = "Config '$name': invalid YAML - " . $e->getMessage() . ". Skipped.";
        $result['skipped']++;
        continue;
      }

      // Import the config.
      if ($preview) {
        $existing = $this->configFactory->get($name)->getRawData();
        $action = empty($existing) ? 'would create' : 'would update';
        $result['summary'][] = "Config '$name' $action (preview mode).";
        $result['imported']++;
      }
      else {
        try {
          $this->importSingleConfig($name, $configData);
          $existing = $this->configFactory->get($name)->getRawData();
          // Check if config existed before by looking at whether we had data prior.
          $result['summary'][] = "Imported '$name' successfully.";
          $result['imported']++;
        }
        catch (\Exception $e) {
          $result['warnings'][] = "Config '$name': import failed - " . $e->getMessage();
          $result['skipped']++;
        }
      }
    }

    // Clear caches after import (skip in preview mode).
    if (!$preview && $result['imported'] > 0) {
      drupal_flush_all_caches();
      $result['summary'][] = 'Cleared all caches after config import.';
    }

    return $result;
  }

  /**
   * Validate a config name against the allowlist and blocklist.
   *
   * @param string $name
   *   The config name (e.g., "views.view.article_listing").
   *
   * @return true|string
   *   TRUE if valid, or an error message string if not.
   */
  public function validateConfigName(string $name) {
    // Check blocked prefixes first (always enforced).
    foreach (self::BLOCKED_PREFIXES as $blocked) {
      if ($name === $blocked || str_starts_with($name, $blocked)) {
        return "blocked config type ('$blocked' is never importable for safety)";
      }
    }

    // Check allowed prefixes.
    $allowedPrefixes = $this->getAllowedPrefixes();
    foreach ($allowedPrefixes as $prefix) {
      if ($name === $prefix || str_starts_with($name, $prefix)) {
        return TRUE;
      }
    }

    return "config type not in allowed list. Use the /api/dc-config-import/allowed-types endpoint to see allowed prefixes";
  }

  /**
   * Parse and validate a YAML string.
   *
   * @param string $yaml
   *   The YAML string.
   *
   * @return array
   *   The parsed YAML as an associative array.
   *
   * @throws \Exception
   *   If the YAML is invalid or not an array.
   */
  public function parseAndValidateYaml(string $yaml): array {
    try {
      $parsed = Yaml::parse($yaml);
    }
    catch (ParseException $e) {
      throw new \Exception('YAML parse error: ' . $e->getMessage());
    }

    if (!is_array($parsed)) {
      throw new \Exception('YAML must parse to an associative array, got ' . gettype($parsed));
    }

    return $parsed;
  }

  /**
   * Import a single config object.
   *
   * @param string $name
   *   The config name.
   * @param array $data
   *   The config data as an associative array.
   */
  protected function importSingleConfig(string $name, array $data): void {
    $config = $this->configFactory->getEditable($name);
    $config->setData($data);
    $config->save();

    \Drupal::logger('dc_config_import')->info("Imported config: @name", [
      '@name' => $name,
    ]);
  }

  /**
   * Get the current allowed config prefixes.
   *
   * Reads from Drupal state, falling back to sensible defaults.
   *
   * @return array
   *   Array of allowed config name prefixes.
   */
  public function getAllowedPrefixes(): array {
    $custom = \Drupal::state()->get('dc_config_import.allowed_prefixes');
    if (is_array($custom) && !empty($custom)) {
      return $custom;
    }
    return self::DEFAULT_ALLOWED_PREFIXES;
  }

  /**
   * Get the blocked config prefixes (always enforced).
   *
   * @return array
   *   Array of blocked config name prefixes.
   */
  public function getBlockedPrefixes(): array {
    return self::BLOCKED_PREFIXES;
  }

}
