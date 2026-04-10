<?php

namespace Drupal\dc_import\Service;

// JSON Schema validation classes (if available)
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for validating JSON against the import schema.
 */
class JsonSchemaValidator {

  use StringTranslationTrait;

  /**
   * Validates JSON data against the import schema.
   *
   * @param array $data
   *   The decoded JSON data to validate.
   *
   * @return array
   *   Array with 'valid' boolean and 'errors' array.
   */
  public function validate(array $data): array {
    // Check if the JSON Schema library is available
    if (!class_exists('\JsonSchema\Validator')) {
      return [
        'valid' => TRUE,
        'errors' => [],
        'warnings' => [$this->t('JSON Schema validation library not available. Skipping schema validation.')],
      ];
    }

    try {
      // Load the schema
      $schema = $this->loadSchema();
      if (!$schema) {
        return [
          'valid' => TRUE,
          'errors' => [],
          'warnings' => [$this->t('Could not load schema for validation. Skipping schema validation.')],
        ];
      }

      // Convert data to object for validation
      $data_object = json_decode(json_encode($data));

      // Validate against schema
      $validator = new \JsonSchema\Validator();
      $validator->validate($data_object, $schema, \JsonSchema\Constraints\Constraint::CHECK_MODE_COERCE_TYPES);

      if ($validator->isValid()) {
        return [
          'valid' => TRUE,
          'errors' => [],
          'warnings' => [],
        ];
      }

      // Collect validation errors
      $errors = [];
      foreach ($validator->getErrors() as $error) {
        $property = $error['property'] ? $error['property'] . ': ' : '';
        $errors[] = $property . $error['message'];
      }

      return [
        'valid' => FALSE,
        'errors' => $errors,
        'warnings' => [],
      ];

    } catch (\Exception $e) {
      return [
        'valid' => TRUE,
        'errors' => [],
        'warnings' => [$this->t('Schema validation failed: @error', ['@error' => $e->getMessage()])],
      ];
    }
  }

  /**
   * Loads the JSON schema from the module's resources directory.
   *
   * @return object|null
   *   The schema object or NULL if unable to load.
   */
  private function loadSchema(): ?object {
    // Load schema from local module resources
    $module_path = \Drupal::service('extension.list.module')->getPath('dc_import');
    $local_path = \Drupal::root() . '/' . $module_path . '/resources/schema.json';

    if (is_readable($local_path)) {
      $schema_content = file_get_contents($local_path);
      if ($schema_content !== FALSE) {
        $schema = json_decode($schema_content);
        if (json_last_error() === JSON_ERROR_NONE) {
          return $schema;
        }
      }
    }

    return NULL;
  }

}