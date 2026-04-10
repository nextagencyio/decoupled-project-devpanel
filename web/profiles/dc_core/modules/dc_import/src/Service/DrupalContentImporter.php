<?php

namespace Drupal\dc_import\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for importing concise Drupal-friendly JSON configuration.
 */
class DrupalContentImporter {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Field type mapper.
   *
   * @var \Drupal\json_import\Service\FieldTypeMapper
   */
  protected $fieldTypeMapper;

  /**
   * Stores field types by bundle for placeholder image generation.
   *
   * @var array
   */
  protected $fieldTypesByBundle = [];

  /**
   * Tracks bundles that need content translation enabled.
   *
   * @var array
   */
  protected $bundlesNeedingTranslation = [];

  /**
   * Maps content IDs to their translations for linking.
   *
   * @var array
   */
  protected $translationMap = [];

  /**
   * Constructs a DrupalContentImporter object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, FieldTypeMapper $field_type_mapper) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->fieldTypeMapper = $field_type_mapper;
  }

  /**
   * Imports concise configuration with 'model' and 'content'.
   *
   * @param array $data
   *   The decoded JSON data.
   * @param bool $preview_mode
   *   Whether to run in preview mode (do not actually create).
   *
   * @return array
   *   Result array with summary and warnings.
   */
  public function import(array $data, $preview_mode = FALSE) {
    if (!isset($data['model']) && !isset($data['content'])) {
      throw new \InvalidArgumentException('JSON must contain a "model" and/or "content" array.');
    }
    return $this->importConcise($data, $preview_mode);
  }

  /**
   * Imports the concise schema with 'model' and 'content'.
   */
  private function importConcise(array $data, $preview_mode = FALSE) {
    $result = [
      'summary' => [],
      'warnings' => [],
    ];

    $bundle_defs = [];
    if (isset($data['model'])) {
      $model = $data['model'];
      // Support both: an array of bundle defs, or keyed by entity type.
      if (is_array($model) && (isset($model['node']) || isset($model['paragraph']))) {
        foreach (['node', 'paragraph'] as $entity_type) {
          if (!empty($model[$entity_type]) && is_array($model[$entity_type])) {
            foreach ($model[$entity_type] as $def) {
              $def['entity'] = $entity_type;
              $bundle_defs[] = $def;
            }
          }
        }
      } else {
        // Assume $model is a flat array of bundle definitions.
        $bundle_defs = is_array($model) ? $model : [];
      }
    }

    // Create bundles and store field types for placeholder generation.
    foreach ($bundle_defs as $def) {
      $entity_type = $def['entity'] ?? 'node';
      if ($entity_type === 'paragraph') {
        $this->createBundleParagraphConcise($def, $preview_mode, $result);
      } else {
        $this->createBundleNodeConcise($def, $preview_mode, $result);
      }

      // Store field types for later use during content creation.
      $bundle = $def['bundle'];
      $fields = $def['fields'] ?? [];
      foreach ($fields as $field) {
        $field_id = $field['id'] ?? $field['label'] ?? NULL;
        $field_type = $field['type'] ?? 'string';
        if ($field_id) {
          $key = "{$entity_type}.{$bundle}.{$field_id}";
          $this->fieldTypesByBundle[$key] = $field_type;
        }
      }
    }

    // Configure GraphQL Compose after all bundles and fields are created (only if GraphQL modules exist).
    if (\Drupal::moduleHandler()->moduleExists('graphql_compose')) {
      foreach ($bundle_defs as $def) {
        $entity_type = $def['entity'] ?? 'node';
        $bundle = $def['bundle'];
        $this->configureGraphQLCompose($entity_type, $bundle, $preview_mode, $result);
      }
    }

    // Auto-enable dc_puck if any paragraph model has a "puck" configuration key.
    if (!$preview_mode) {
      $this->autoEnablePuck($bundle_defs, $result);
    }

    // Detect and enable languages before creating content.
    if (!empty($data['content']) && is_array($data['content'])) {
      $this->detectAndEnableLanguages($data['content'], $preview_mode, $result);
    }

    // Create content if present.
    if (!empty($data['content']) && is_array($data['content'])) {
      $this->createContentConcise($data['content'], $preview_mode, $result);
    }

    // Clear GraphQL caches after successful import (skip in preview mode).
    if (!$preview_mode) {
      $cleared_caches = $this->clearGraphQLCaches();
      if ($cleared_caches) {
        $result['summary'][] = "Cleared GraphQL caches for schema updates";
      }
    }

    return $result;
  }

  /**
   * Creates a node bundle from concise def.
   */
  private function createBundleNodeConcise(array $def, $preview_mode, array &$result) {
    $id = $def['bundle'];
    $name = $def['label'] ?? $id;
    $description = $def['description'] ?? '';

    if ($preview_mode) {
      $result['summary'][] = "Would create node type: {$name} ({$id})";
    } else {
      $existing = $this->entityTypeManager->getStorage('node_type')->load($id);
      if ($existing) {
        $result['warnings'][] = "Node type '{$id}' already exists, skipping creation";
      } else {
        $node_type = $this->entityTypeManager->getStorage('node_type')->create([
          'type' => $id,
          'name' => $name,
          'description' => $description,
          'new_revision' => TRUE,
          'preview_mode' => 0,  // Disable default Drupal preview (use decoupled preview instead)
          'display_submitted' => TRUE,
        ]);
        $node_type->save();
        $result['summary'][] = "Created node type: {$name} ({$id})";

        // Enable decoupled preview iframe for this content type.
        $this->configureDecoupledPreview($id, $result);
      }
    }

    // Add core body if requested.
    if (!empty($def['body'])) {
      $this->addBodyFieldToContentType($id, $preview_mode, $result);
    }

    // Fields.
    $fields = $def['fields'] ?? [];
    foreach ($fields as $field) {
      // Normalize field keys: label or name.
      if (!isset($field['name']) && isset($field['label'])) {
        $field['name'] = $field['label'];
      }
      $this->createField('node', $id, $field, $preview_mode, $result);
    }

    // Form display with sensible defaults.
    if (!$preview_mode) {
      $this->createFormDisplayConcise('node', $id, $fields, $def['form_display'] ?? NULL, $result);
    }
  }

  /**
   * Creates a paragraph bundle from concise def.
   */
  private function createBundleParagraphConcise(array $def, $preview_mode, array &$result) {
    $id = $def['bundle'];
    $name = $def['label'] ?? $id;
    $description = $def['description'] ?? '';

    if ($preview_mode) {
      $result['summary'][] = "Would create paragraph type: {$name} ({$id})";
    } else {
      $existing = $this->entityTypeManager->getStorage('paragraphs_type')->load($id);
      if ($existing) {
        $result['warnings'][] = "Paragraph type '{$id}' already exists, skipping creation";
      } else {
        $paragraph_type = $this->entityTypeManager->getStorage('paragraphs_type')->create([
          'id' => $id,
          'label' => $name,
          'description' => $description,
        ]);
        $paragraph_type->save();
        $result['summary'][] = "Created paragraph type: {$name} ({$id})";
      }
    }

    $fields = $def['fields'] ?? [];
    foreach ($fields as $field) {
      if (!isset($field['name']) && isset($field['label'])) {
        $field['name'] = $field['label'];
      }
      $this->createField('paragraph', $id, $field, $preview_mode, $result);
    }

    if (!$preview_mode) {
      $this->createFormDisplayConcise('paragraph', $id, $fields, $def['form_display'] ?? NULL, $result);
    }
  }

  /**
   * Creates form display with concise defaults using mapper widget hints.
   */
  private function createFormDisplayConcise($entity_type, $bundle, array $fields, $overrides, array &$result) {
    $form_display_id = "{$entity_type}.{$bundle}.default";
    $form_display_storage = $this->entityTypeManager->getStorage('entity_form_display');
    $existing_display = $form_display_storage->load($form_display_id);
    if ($existing_display) {
      $result['warnings'][] = "Form display for {$entity_type}.{$bundle} already exists, skipping";
      return;
    }

    $display_config = [
      'targetEntityType' => $entity_type,
      'bundle' => $bundle,
      'mode' => 'default',
      'status' => TRUE,
      'content' => [],
      'hidden' => [],
    ];

    $weight = -5;
    if ($entity_type === 'node') {
      $display_config['content']['title'] = [
        'type' => 'string_textfield',
        'weight' => $weight++,
        'region' => 'content',
        'settings' => [
          'size' => 60,
          'placeholder' => '',
        ],
        'third_party_settings' => [],
      ];
    }

    foreach ($fields as $field_config) {
      $field_id = $field_config['id'];
      // Handle reserved fields.
      if ($this->isReservedField($field_id, $entity_type)) {
        $widget_type = 'string_textfield';
        if ($field_id === 'body') {
          $widget_type = 'text_textarea';
        }
        $display_config['content'][$field_id] = [
          'type' => $widget_type,
          'weight' => $weight++,
          'region' => 'content',
          'settings' => [],
          'third_party_settings' => [],
        ];
        continue;
      }

      $drupal = $this->fieldTypeMapper->mapFieldType($field_config);
      if (!$drupal) {
        continue;
      }
      if (!empty($drupal['required'])) {
        $field_config['required'] = TRUE;
      }
      $field_name = 'field_' . $this->sanitizeFieldName($field_id);
      $widget_type = $drupal['widget'] ?? 'string_textfield';

      // Configure widget settings
      $widget_settings = [];

      // Set paragraphs to be collapsed by default
      if ($widget_type === 'paragraphs') {
        $widget_settings = [
          'edit_mode' => 'closed',
          'closed_mode' => 'summary',
          'autocollapse' => 'none',
          'closed_mode_threshold' => 0,
          'add_mode' => 'dropdown',
          'form_display_mode' => 'default',
          'default_paragraph_type' => '',
        ];
      }

      $display_config['content'][$field_name] = [
        'type' => $widget_type,
        'weight' => $weight++,
        'region' => 'content',
        'settings' => $widget_settings,
        'third_party_settings' => [],
      ];
    }

    // Add standard node fields at the end.
    if ($entity_type === 'node') {
      $display_config['content']['uid'] = [
        'type' => 'entity_reference_autocomplete',
        'weight' => $weight++,
        'region' => 'content',
        'settings' => [
          'match_operator' => 'CONTAINS',
          'match_limit' => 10,
          'size' => 60,
          'placeholder' => '',
        ],
        'third_party_settings' => [],
      ];
      $display_config['content']['created'] = [
        'type' => 'datetime_timestamp',
        'weight' => $weight++,
        'region' => 'content',
        'settings' => [],
        'third_party_settings' => [],
      ];
      $display_config['content']['promote'] = [
        'type' => 'boolean_checkbox',
        'weight' => $weight++,
        'region' => 'content',
        'settings' => ['display_label' => TRUE],
        'third_party_settings' => [],
      ];
      $display_config['content']['status'] = [
        'type' => 'boolean_checkbox',
        'weight' => $weight + 10,
        'region' => 'content',
        'settings' => ['display_label' => TRUE],
        'third_party_settings' => [],
      ];
    }

    $form_display = $form_display_storage->create($display_config);
    $form_display->save();
    $result['summary'][] = "Created form display for {$entity_type} {$bundle}";
  }

  /**
   * Creates content entities from concise 'content' array.
   */
  private function createContentConcise(array $content, $preview_mode, array &$result) {
    $created = [];

    // Identify embedded entity IDs (entities that are only referenced within other entities' fields)
    // This dynamically detects entities that are only used as nested references
    $embedded_ids = $this->findEmbeddedEntityIds($content);

    // First pass: create entities without resolving @refs, but skip embedded entities.
    // Embedded entities will be created inline when their parent is processed.
    foreach ($content as $item) {
      // Skip entities that are only embedded within other entities
      if (isset($item['id']) && in_array($item['id'], $embedded_ids)) {
        continue;
      }

      $entity = $this->createConciseEntry($item, $preview_mode, $result);
      if ($entity && isset($item['id'])) {
        $created[$item['id']] = $entity;
      }
    }

    if ($preview_mode) {
      return;
    }

    // Second pass: resolve @refs and taxonomy terms.
    foreach ($content as $item) {
      if (isset($item['id']) && isset($created[$item['id']])) {
        // Debug logging for field_content resolution
        $item_type = $item['type'] ?? 'unknown';
        if ($item_type === 'node.landing') {
          $entity_label = method_exists($created[$item['id']], 'label') ? $created[$item['id']]->label() : 'Unknown';
          error_log("JSON Import Debug: About to resolve references for node '{$entity_label}' (ID: {$created[$item['id']]->id()})");
        }

        $this->resolveConciseReferences($item, $created[$item['id']], $created, $result);
      }
    }

    // Third pass: create translations for items with translation_of.
    $this->createTranslations($content, $created, $result);
  }

  /**
   * Create translations for content items with translation_of.
   *
   * @param array $content
   *   The content array from the import JSON.
   * @param array $created
   *   Map of content IDs to created entities.
   * @param array &$result
   *   The result array to add messages to.
   */
  private function createTranslations(array $content, array $created, array &$result): void {
    if (!\Drupal::moduleHandler()->moduleExists('content_translation')) {
      return;
    }

    foreach ($content as $item) {
      $translation_of = $item['translation_of'] ?? NULL;
      $langcode = $item['langcode'] ?? NULL;

      if (!$translation_of || !$langcode) {
        continue;
      }

      // Find the source entity.
      if (!isset($created[$translation_of])) {
        $result['warnings'][] = "Cannot create translation '{$item['id']}': source entity '{$translation_of}' not found";
        continue;
      }

      $source_entity = $created[$translation_of];

      // Only handle translatable entities (nodes).
      if (!method_exists($source_entity, 'addTranslation')) {
        $result['warnings'][] = "Entity type does not support translations for '{$item['id']}'";
        continue;
      }

      // Check if translation already exists.
      if ($source_entity->hasTranslation($langcode)) {
        $result['warnings'][] = "Translation already exists for '{$translation_of}' in language '{$langcode}'";
        continue;
      }

      $values = $item['values'] ?? [];
      $type = $item['type'] ?? '';
      $parts = explode('.', $type, 2);
      $bundle = $parts[1] ?? NULL;

      // Prepare translation values.
      $translation_values = [
        'title' => $values['title'] ?? $source_entity->label(),
      ];

      // Add field values.
      foreach ($values as $field_id => $value) {
        if ($field_id === 'title') {
          continue;
        }

        if ($this->isReservedField($field_id, 'node')) {
          $translation_values[$field_id] = $this->mapFieldValueConcise($value, $field_id);
        } else {
          $field_name = 'field_' . $this->sanitizeFieldName($field_id);
          if ($source_entity->hasField($field_name)) {
            $translation_values[$field_name] = $this->mapFieldValueConcise($value, $field_id);
          }
        }
      }

      try {
        // Create the translation.
        $translation = $source_entity->addTranslation($langcode, $translation_values);
        $source_entity->save();

        // Handle path alias for translation if specified.
        if (isset($item['path']) && !empty($item['path'])) {
          $this->createPathAlias($translation, $item['path'], $result);
        }

        $result['summary'][] = "Created translation ({$langcode}) for '{$translation_of}': {$translation_values['title']}";

        // Store the translation in created map for potential references.
        $created[$item['id']] = $translation;
      }
      catch (\Exception $e) {
        $result['warnings'][] = "Failed to create translation '{$item['id']}': " . $e->getMessage();
      }
    }
  }

  /**
   * Process field_content @ references specifically.
   */
  private function processFieldContentReferences($entity, $refs, $created, &$result, $field_name) {
    $field_definition = $entity->getFieldDefinition($field_name);
    $field_type = $field_definition->getType();
    $items = [];
    $resolved_refs = [];

    error_log("JSON Import Debug: Processing field_content references for entity ID {$entity->id()}: " . json_encode($refs));

    foreach ($refs as $ref_string) {
      $ref = substr($ref_string, 1); // Remove @
      if (isset($created[$ref])) {
        $ref_entity = $created[$ref];
        $resolved_refs[] = $ref;

        if ($field_type === 'entity_reference_revisions') {
          $items[] = [
            'target_id' => (int) $ref_entity->id(),
            'target_revision_id' => (int) $ref_entity->getRevisionId(),
          ];
        } elseif ($field_type === 'entity_reference') {
          $items[] = [
            'target_id' => (int) $ref_entity->id(),
          ];
        } else {
          $items[] = (int) $ref_entity->id();
        }
      } else {
        $result['warnings'][] = "Could not resolve field_content reference: {$ref_string}";
        error_log("JSON Import Debug: Could not resolve field_content reference '{$ref}' - not found in created entities");
      }
    }

    if (!empty($items)) {
      if ($field_type === 'entity_reference_revisions') {
        $field_list = $entity->get($field_name);
        $field_list->setValue($items);
        $entity->save();
        $result['summary'][] = "Resolved field_content references: [" . implode(', ', $resolved_refs) . "]";
        error_log("JSON Import Debug: Successfully set field_content with " . count($items) . " references");
      } else {
        $entity->set($field_name, $items);
        $entity->save();
        $result['summary'][] = "Resolved field_content references: [" . implode(', ', $resolved_refs) . "]";
      }
    } else {
      error_log("JSON Import Debug: No field_content references could be resolved");
    }
  }

  /**
   * Find entity IDs that are embedded within other entities' field arrays.
   */
  private function findEmbeddedEntityIds(array $content): array {
    $embedded_ids = [];

    foreach ($content as $item) {
      $values = $item['values'] ?? [];
      foreach ($values as $field_value) {
        if (is_array($field_value)) {
          $this->collectEmbeddedIds($field_value, $embedded_ids);
        }
      }
    }

    return array_unique($embedded_ids);
  }

  /**
   * Recursively collect embedded entity IDs from field values.
   */
  private function collectEmbeddedIds($value, array &$embedded_ids): void {
    if (is_array($value)) {
      // Check if this is an embedded entity object
      if (isset($value['id'], $value['type'], $value['values'])) {
        $embedded_ids[] = $value['id'];
        // Recursively check within the embedded entity's values
        foreach ($value['values'] as $nested_value) {
          if (is_array($nested_value)) {
            $this->collectEmbeddedIds($nested_value, $embedded_ids);
          }
        }
      } else {
        // Check if this is an array of items that might contain embedded entities
        foreach ($value as $item) {
          if (is_array($item)) {
            $this->collectEmbeddedIds($item, $embedded_ids);
          }
        }
      }
    }
  }

    private function createConciseEntry(array $item, $preview_mode, array &$result) {
    $type = $item['type'];
    $parts = explode('.', $type, 2);
    $entity_type = $parts[0] ?? 'node';
    $bundle = $parts[1] ?? NULL;

    // Debug logging for node creation (disabled for performance)
    // if ($entity_type === 'node') {
    //   $item_values = $item['values'] ?? [];
    //   if (isset($item_values['field_content'])) {
    //     error_log("JSON Import Debug: Creating node with field_content: " . json_encode($item_values['field_content']));
    //   }
    // }

    // For simple types without dots, default to paragraph and warn.
    // Common mistake: using "article" instead of "node.article".
    if (!str_contains($type, '.')) {
      $entity_type = 'paragraph';
      $bundle = $type;
      $result['warnings'][] = "Content item '{$item['id']}' has type '{$type}' without a prefix — treating as paragraph. Did you mean 'node.{$type}'? Use 'node.bundle' for content or 'paragraph.bundle' for paragraphs.";
    }

    $values = $item['values'] ?? [];

    // Warn if fields appear to be at the top level instead of inside 'values'.
    if (empty($values) && !empty($item['title'])) {
      $result['warnings'][] = "Content item '{$item['id']}' has 'title' at the top level but no 'values' object. Fields should be inside a 'values' key: {\"type\": \"{$type}\", \"values\": {\"title\": \"...\"}}";
    }

    if ($entity_type === 'paragraph') {
      if ($preview_mode) {
        $result['summary'][] = "Would create paragraph: {$item['id']} (type: {$bundle})";
        return NULL;
      }
      $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
      $data = ['type' => $bundle];
      foreach ($values as $field_id => $value) {
        $data['field_' . $this->sanitizeFieldName($field_id)] = $this->mapFieldValueConcise($value, $field_id);
      }

      // Generate placeholder images for empty image fields.
      $this->fillEmptyImageFields('paragraph', $bundle, $values, $data);

      $paragraph = $paragraph_storage->create($data);
      $paragraph->save();
      $title = $values['title'] ?? $item['id'] ?? 'Untitled';
      $result['summary'][] = "Created paragraph: {$title} (ID: {$paragraph->id()}, type: {$bundle})";
      return $paragraph;
    }

    if ($entity_type === 'media') {
      if ($preview_mode) {
        $result['summary'][] = "Would create media: {$item['id']} (type: {$bundle})";
        return NULL;
      }

      $file_entity = NULL;
      $alt_text = $values['alt'] ?? $values['field_image']['alt'] ?? $values['title'] ?? $item['id'] ?? 'image';
      
      // Try to fetch image from external service (Pexels/Unsplash) if configured
      $image_data = NULL;
      $file_extension = 'png';
      
      if (\Drupal::hasService('drupalx_ai.image_generator')) {
        $image_generator = \Drupal::service('drupalx_ai.image_generator');
        $fetched_image = $image_generator->fetchImage($alt_text);
        
        if ($fetched_image) {
          $image_data = $fetched_image['data'];
          $file_extension = $fetched_image['extension'];
        }
      }
      
      // Fallback to placeholder image if no external image was fetched
      if (!$image_data) {
        $placeholder_path = \Drupal::service('extension.list.module')->getPath('drupalx_ai') . '/files/card.png';
        if (!file_exists($placeholder_path)) {
          // Fallback to json_import placeholder if drupalx_ai one doesn't exist
          $placeholder_path = \Drupal::service('extension.list.module')->getPath('dc_import') . '/resources/placeholder.png';
        }

        if (file_exists($placeholder_path)) {
          $image_data = file_get_contents($placeholder_path);
          $file_extension = 'png';
        }
      }

      if ($image_data) {
        // Create a unique filename to avoid conflicts
        $safe_filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($alt_text)) . '.' . $file_extension;
        $destination = 'public://ai-generated/' . $safe_filename;

        // Ensure directory exists
        $directory = dirname($destination);
        \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

        // Save image data to destination
        $file_entity = \Drupal::service('file.repository')->writeData(
          $image_data,
          $destination,
          \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE
        );
      }

      $media_storage = $this->entityTypeManager->getStorage('media');
      $media_data = [
        'bundle' => $bundle,
        'name' => $values['alt'] ?? $values['title'] ?? $item['id'] ?? 'Untitled Media',
        'status' => 1,
        'uid' => 1,
      ];

      // Handle media-specific fields with actual file
      if ($file_entity) {
        // Try to get alt text from various sources
        $alt_text = $values['alt'] ?? $values['field_image']['alt'] ?? $values['title'] ?? $item['id'] ?? 'Image';
        
        $media_data['field_image'] = [
          'target_id' => $file_entity->id(),
          'alt' => $alt_text,
        ];
      }

      $media = $media_storage->create($media_data);
      $media->save();
      $result['summary'][] = "Created media: {$media_data['name']} (ID: {$media->id()}, type: {$bundle})";
      return $media;
    }

    // Default: node.
    // Skip if this is a translation - it will be created in the translation pass.
    if (!empty($item['translation_of'])) {
      if ($preview_mode) {
        $result['summary'][] = "Would create translation of {$item['translation_of']}: {$item['id']} (type: {$bundle}, lang: {$item['langcode']})";
      }
      return NULL; // Will be handled in resolveConciseReferences.
    }

    if ($preview_mode) {
      $langcode = $item['langcode'] ?? 'en';
      $result['summary'][] = "Would create node: {$item['id']} (type: {$bundle}, lang: {$langcode})";
      return NULL;
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $node_data = [
      'type' => $bundle,
      'title' => $values['title'] ?? 'Untitled',
      'status' => 1,
      'uid' => 1,
    ];

    // Set langcode if specified.
    if (!empty($item['langcode'])) {
      $node_data['langcode'] = $item['langcode'];
    }
    foreach ($values as $field_id => $value) {
      if ($field_id === 'title') {
        // Already handled.
        continue;
      }

      // Special handling for field_content: skip @ references during entity creation, handle in second pass
      if ($field_id === 'field_content' && is_array($value)) {
        $all_refs = TRUE;
        foreach ($value as $item) {
          if (!is_string($item) || strlen($item) <= 1 || $item[0] !== '@') {
            $all_refs = FALSE;
            break;
          }
        }
        if ($all_refs) {
          // Skip field_content during entity creation, handle in second pass
          continue;
        }
      }

      // Auto-create the body field instance if content uses it but the model didn't declare body: true.
      if ($field_id === 'body') {
        $this->addBodyFieldToContentType($bundle, FALSE, $result);
      }

      if ($this->isReservedField($field_id, 'node')) {
        $node_data[$field_id] = $this->mapFieldValueConcise($value, $field_id);
      } else {
        $node_data['field_' . $this->sanitizeFieldName($field_id)] = $this->mapFieldValueConcise($value, $field_id);
      }
    }

    // Generate placeholder images for empty image fields.
    $this->fillEmptyImageFields('node', $bundle, $values, $node_data);

    // Check for existing node to avoid duplicates on re-import.
    $existing_node = NULL;
    $path = $item['path'] ?? NULL;
    if ($path) {
      // Look up by path alias first (most reliable).
      $alias_path = '/' . ltrim($path, '/');
      $path_alias_storage = $this->entityTypeManager->getStorage('path_alias');
      $aliases = $path_alias_storage->loadByProperties(['alias' => $alias_path]);
      if (!empty($aliases)) {
        $alias = reset($aliases);
        $source = $alias->getPath();
        if (preg_match('#^/node/(\d+)$#', $source, $matches)) {
          $existing_node = $node_storage->load($matches[1]);
        }
      }
    }
    if (!$existing_node) {
      // Fall back to title + bundle match.
      $query = $node_storage->getQuery()
        ->condition('type', $bundle)
        ->condition('title', $node_data['title'])
        ->accessCheck(FALSE)
        ->range(0, 1);
      $existing_ids = $query->execute();
      if (!empty($existing_ids)) {
        $existing_node = $node_storage->load(reset($existing_ids));
      }
    }

    if ($existing_node) {
      $result['summary'][] = "Skipped node: {$node_data['title']} (already exists as ID: {$existing_node->id()})";
      return $existing_node;
    }

    $node = $node_storage->create($node_data);

    // If a path is specified, disable pathauto so it doesn't override
    // our explicit alias with an auto-generated one.
    if (!empty($path) && $node->hasField('path')) {
      $node->set('path', ['alias' => '/' . ltrim($path, '/'), 'pathauto' => FALSE]);
    }

    $node->save();

    if (!empty($path)) {
      $result['summary'][] = "Created node: {$node_data['title']} (ID: {$node->id()}, type: {$bundle}, path: {$path})";
    } else {
      $result['summary'][] = "Created node: {$node_data['title']} (ID: {$node->id()}, type: {$bundle})";
    }
    return $node;
  }

  /**
   * Map field values for concise format.
   */
  private function mapFieldValueConcise($value, $field_id) {
    if ($value === NULL) {
      return NULL;
    }

    // Debug logging for field_content specifically (disabled for performance)
    // if ($field_id === 'field_content' || $field_id === 'content') {
    //   error_log("JSON Import Debug: mapFieldValueConcise for field '{$field_id}' with value: " . json_encode($value));
    // }

    // Reference marker like @foo.
    if (is_string($value) && strlen($value) > 1 && $value[0] === '@') {
      return NULL; // Will resolve later.
    }
    // Handle image field objects with URI.
    // Distinguish from link fields by checking if URI looks like an image URL
    // (http/https URLs, module:// paths, or file paths with image extensions).
    if (is_array($value) && isset($value['uri'])) {
      $uri = $value['uri'];
      $is_image_uri = FALSE;

      // Check for external image URLs (http/https)
      if (strpos($uri, 'http://') === 0 || strpos($uri, 'https://') === 0) {
        // Check for common image hosting services or image extensions
        $image_patterns = [
          'unsplash.com',
          'pexels.com',
          'images.',
          'img.',
          'cdn.',
          '.jpg',
          '.jpeg',
          '.png',
          '.gif',
          '.webp',
          '.svg',
        ];
        foreach ($image_patterns as $pattern) {
          if (stripos($uri, $pattern) !== FALSE) {
            $is_image_uri = TRUE;
            break;
          }
        }
      }
      // Check for module:// paths or file paths with image extensions
      elseif (strpos($uri, 'module://') === 0 || strpos($uri, '/') === 0) {
        $image_extensions = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg'];
        foreach ($image_extensions as $ext) {
          if (stripos($uri, $ext) !== FALSE) {
            $is_image_uri = TRUE;
            break;
          }
        }
      }

      if ($is_image_uri) {
        return $this->handleImageFieldValue($value, $field_id);
      }

      // If not an image URI but has 'alt' property, it's likely still an image field
      if (isset($value['alt'])) {
        return $this->handleImageFieldValue($value, $field_id);
      }

      // Otherwise treat as link field (has uri and title but not image-like)
      if (isset($value['title'])) {
        return $value; // Link fields can be passed through as-is
      }
    }
    // Arrays of scalars -> [{value: item}].
    if (is_array($value)) {
      // If already an associative array for rich text body etc, pass through.
      $is_assoc = array_keys($value) !== range(0, count($value) - 1);
      if ($is_assoc) {
        return $value;
      }

      // Check if this is an array of image objects (each item has 'uri' property)
      $is_image_array = !empty($value) && is_array($value[0]) && isset($value[0]['uri']);
      if ($is_image_array) {
        $processed_images = [];
        foreach ($value as $image_item) {
          if (is_array($image_item) && isset($image_item['uri'])) {
            $processed_image = $this->handleImageFieldValue($image_item, $field_id);
            if ($processed_image) {
              $processed_images[] = $processed_image;
            }
          }
        }
        return $processed_images;
      }

      // Check if this is an array of embedded entity objects (each item has 'id', 'type', 'values')
      $is_entity_array = !empty($value) && is_array($value[0]) && isset($value[0]['id'], $value[0]['type'], $value[0]['values']);
      if ($is_entity_array) {
        return NULL; // Will be handled in resolveConciseReferences
      }

      // Special handling for field_content arrays of @ references
      if (($field_id === 'field_content' || $field_id === 'content') && !empty($value)) {
        $all_refs = TRUE;
        foreach ($value as $item) {
          if (!is_string($item) || strlen($item) <= 1 || $item[0] !== '@') {
            $all_refs = FALSE;
            break;
          }
        }
        if ($all_refs) {
          // Return the array as-is for @ reference resolution in second pass
          return $value; // Keep @ references intact for second pass resolution
        }
      }

      return array_map(function ($item) {
        return ['value' => $item];
      }, $value);
    }
    if (is_bool($value)) {
      return $value ? 1 : 0;
    }
    // Heuristic for date fields by id.
    if ($field_id === 'publish_date' || strpos($field_id, 'date') !== FALSE) {
      if (is_string($value)) {
        $timestamp = strtotime($value);
        if ($timestamp !== FALSE) {
          return date('Y-m-d\\TH:i:s', $timestamp);
        }
      }
    }
    // Default: simple value for string/text fields.
    return ['value' => $value, 'format' => 'basic_html'];
  }

  /**
   * Resolve @refs and taxonomy terms after entity creation.
   */
  private function resolveConciseReferences(array $item, $entity, array $created, array &$result) {
    $values = $item['values'] ?? [];
    $type = $item['type'];
    $parts = explode('.', $type, 2);
    $entity_type = $parts[0] ?? 'node';
    $bundle = $parts[1] ?? NULL;

    // Debug logging for nodes (disabled for performance)
    // if ($entity_type === 'node') {
    //   $entity_label = method_exists($entity, 'label') ? $entity->label() : 'Unknown';
    //   error_log("JSON Import Debug: Processing node '{$entity_label}' values: " . json_encode(array_keys($values)));
    //   if (isset($values['field_content'])) {
    //     error_log("JSON Import Debug: Node field_content value in second pass: " . json_encode($values['field_content']));
    //   } else {
    //     error_log("JSON Import Debug: Node field_content value is NOT present in second pass values");
    //   }
    // }

    foreach ($values as $field_id => $value) {
      // Special handling for field_content which is already prefixed
      if ($field_id === 'field_content') {
        $drupal_field_name = 'field_content';
      } else {
        $drupal_field_name = $this->isReservedField($field_id, $entity_type) ? $field_id : 'field_' . $this->sanitizeFieldName($field_id);
      }

      // Debug logging for ALL fields on nodes (disabled for performance)
      // if ($entity_type === 'node') {
      //   $entity_label = method_exists($entity, 'label') ? $entity->label() : 'Unknown';
      //   error_log("JSON Import Debug: Node '{$entity_label}' processing field '{$field_id}' -> '{$drupal_field_name}' with value: " . json_encode($value));
      // }

      if (!$entity->hasField($drupal_field_name)) {
        if ($drupal_field_name === 'field_content') {
          error_log("JSON Import Debug: Entity does not have field_content field!");
        }
        continue;
      }

      // Additional debug for field_content field definition (disabled for performance)
      // if ($drupal_field_name === 'field_content') {
      //   $field_definition = $entity->getFieldDefinition($drupal_field_name);
      //   $field_type = $field_definition->getType();
      //   error_log("JSON Import Debug: field_content field type: " . $field_type);
      // }

      // Get field definition once for type and settings.
      $field_definition = $entity->getFieldDefinition($drupal_field_name);

      // Handle references marked with @.
      if (is_string($value) && strlen($value) > 1 && $value[0] === '@') {
        // Debug logging for single @ references in field_content
        if ($drupal_field_name === 'field_content') {
          error_log("JSON Import Debug: field_content processing single @ reference: " . $value);
        }

        $ref = substr($value, 1);
        if (isset($created[$ref])) {
          $field_type = $field_definition->getType();
          $ref_entity = $created[$ref];
          $cardinality = (int) $field_definition->getFieldStorageDefinition()->getCardinality();

          if ($field_type === 'entity_reference_revisions') {
            // Paragraph reference: set using explicit IDs as item list.
            $field_list = $entity->get($drupal_field_name);
            $item = [
              'target_id' => (int) $ref_entity->id(),
              'target_revision_id' => (int) $ref_entity->getRevisionId(),
            ];
            $field_list->setValue([$item]);
            $entity->save();
            $result['summary'][] = "Resolved paragraph reference: {$field_id} -> {$ref}";
          } elseif ($field_type === 'entity_reference') {
            // Node or term reference by target_id.
            $item = [
              'target_id' => (int) $ref_entity->id(),
            ];
            $entity->set($drupal_field_name, $cardinality === 1 ? $item : [$item]);
            $entity->save();
            $result['summary'][] = "Resolved entity reference: {$field_id} -> {$ref}";
          } else {
            // Fallback: best-effort assign ID.
            $entity->set($drupal_field_name, (int) $ref_entity->id());
            $entity->save();
            $result['summary'][] = "Resolved reference (fallback): {$field_id} -> {$ref}";
          }
        } else {
          $result['warnings'][] = "Could not resolve reference {$field_id} -> {$ref}";
        }
        continue;
      }

            // Handle arrays of references marked with @.
      if (is_array($value) && !empty($value)) {
        // Debug logging for field_content arrays
        if ($drupal_field_name === 'field_content') {
          error_log("JSON Import Debug: field_content is array with " . count($value) . " items: " . json_encode($value));
        }

        $all_refs = TRUE;
        $refs_to_process = [];

        foreach ($value as $item) {
          // Handle both direct @ references and value-wrapped @ references
          $ref_string = '';
          if (is_string($item) && strlen($item) > 1 && $item[0] === '@') {
            $ref_string = $item;
          } elseif (is_array($item) && isset($item['value']) && is_string($item['value']) && strlen($item['value']) > 1 && $item['value'][0] === '@') {
            $ref_string = $item['value'];
          } else {
            $all_refs = FALSE;
            break;
          }
          $refs_to_process[] = $ref_string;
        }

        // Debug logging for field_content all_refs check
        if ($drupal_field_name === 'field_content') {
          error_log("JSON Import Debug: field_content all_refs check result: " . ($all_refs ? 'TRUE' : 'FALSE'));
        }

        if ($all_refs) {
          $field_type = $field_definition->getType();
          $items = [];
          $resolved_refs = [];

          // Debug logging for field_content
          if ($drupal_field_name === 'field_content') {
            $entity_label = method_exists($entity, 'label') ? $entity->label() : 'Unknown';
            error_log("JSON Import Debug: Processing field_content for entity '{$entity_label}' (ID: {$entity->id()}) with " . count($refs_to_process) . " references: " . implode(', ', $refs_to_process));
          }

          foreach ($refs_to_process as $item) {
            $ref = substr($item, 1);
            if (isset($created[$ref])) {
              $ref_entity = $created[$ref];
              $resolved_refs[] = $ref;

              if ($field_type === 'entity_reference_revisions') {
                // Paragraph reference: set using explicit IDs as item list.
                $items[] = [
                  'target_id' => (int) $ref_entity->id(),
                  'target_revision_id' => (int) $ref_entity->getRevisionId(),
                ];
              } elseif ($field_type === 'entity_reference') {
                // Node or term reference by target_id.
                $items[] = [
                  'target_id' => (int) $ref_entity->id(),
                ];
              } else {
                // Fallback: best-effort assign ID.
                $items[] = (int) $ref_entity->id();
              }
            } else {
              $result['warnings'][] = "Could not resolve reference {$field_id} -> {$ref}";

              // Debug logging for field_content
              if ($drupal_field_name === 'field_content') {
                error_log("JSON Import Debug: Could not resolve field_content reference '{$ref}' - not found in created entities");
              }
            }
          }

          if (!empty($items)) {
            if ($field_type === 'entity_reference_revisions') {
              $field_list = $entity->get($drupal_field_name);
              $field_list->setValue($items);
              $entity->save();
              $result['summary'][] = "Resolved paragraph references: {$field_id} -> [" . implode(', ', $resolved_refs) . "]";
            } else {
              $entity->set($drupal_field_name, $items);
              $entity->save();
              $result['summary'][] = "Resolved entity references: {$field_id} -> [" . implode(', ', $resolved_refs) . "]";
            }
          }
          continue;
        }
      }

      // Handle arrays of embedded entity objects (each item has 'id', 'type', 'values')
      if (is_array($value) && !empty($value)) {
        $all_embedded_entities = TRUE;
        foreach ($value as $item) {
          if (!is_array($item) || !isset($item['id'], $item['type'], $item['values'])) {
            $all_embedded_entities = FALSE;
            break;
          }
        }

        if ($all_embedded_entities) {
          $field_type = $field_definition->getType();
          $embedded_entities = [];
          $created_embedded = [];

          // Define sub-component types for embedded entity creation as well
          $sub_component_types = [
            'paragraph.card',
            'paragraph.accordion_item',
            'paragraph.carousel_item',
            'paragraph.bullet',
            'paragraph.pricing_card',
          ];

          foreach ($value as $embedded_item) {
            // Create the embedded entity (sub-components are allowed here since they're embedded)
            $embedded_entity = $this->createConciseEntry($embedded_item, FALSE, $result);
            if ($embedded_entity && isset($embedded_item['id'])) {
              $created_embedded[$embedded_item['id']] = $embedded_entity;
              // Also add to main created array to track globally
              $created[$embedded_item['id']] = $embedded_entity;

              if ($field_type === 'entity_reference_revisions') {
                // Paragraph reference: set using explicit IDs as item list.
                $embedded_entities[] = [
                  'target_id' => (int) $embedded_entity->id(),
                  'target_revision_id' => (int) $embedded_entity->getRevisionId(),
                ];
              } elseif ($field_type === 'entity_reference') {
                // Node or term reference by target_id.
                $embedded_entities[] = [
                  'target_id' => (int) $embedded_entity->id(),
                ];
              } else {
                // Fallback: best-effort assign ID.
                $embedded_entities[] = (int) $embedded_entity->id();
              }
            }
          }

          if (!empty($embedded_entities)) {
            if ($field_type === 'entity_reference_revisions') {
              $field_list = $entity->get($drupal_field_name);
              $field_list->setValue($embedded_entities);
              $entity->save();
              $result['summary'][] = "Created and resolved embedded paragraphs: {$field_id} (" . count($embedded_entities) . " items)";
            } else {
              $entity->set($drupal_field_name, $embedded_entities);
              $entity->save();
              $result['summary'][] = "Created and resolved embedded entities: {$field_id} (" . count($embedded_entities) . " items)";
            }

            // Now resolve any @references within the embedded entities
            foreach ($value as $embedded_item) {
              if (isset($embedded_item['id']) && isset($created_embedded[$embedded_item['id']])) {
                $this->resolveConciseReferences($embedded_item, $created_embedded[$embedded_item['id']], $created, $result);
              }
            }
          }
          continue;
        }
      }

      // Handle taxonomy term creation for term() fields: if strings provided.
      $type_storage_settings = $field_definition->getSettings();
      $target_type = $type_storage_settings['target_type'] ?? NULL;
      if ($target_type === 'taxonomy_term') {
        $handler_settings = $field_definition->getSetting('handler_settings') ?: [];
        $target_bundles = $handler_settings['target_bundles'] ?? [];
        $vocab = is_array($target_bundles) ? array_key_first($target_bundles) : NULL;
        if ($vocab) {
          $term_ids = [];
          $values_list = is_array($value) && array_keys($value) === range(0, count($value) - 1) ? $value : [$value];
          foreach ($values_list as $term_value) {
            $tid = NULL;
            if (is_array($term_value)) {
              if (!empty($term_value['tid'])) {
                $tid = (int) $term_value['tid'];
              } elseif (!empty($term_value['name'])) {
                $tid = $this->ensureTerm($vocab, $term_value['name']);
              }
            } elseif (is_string($term_value)) {
              $tid = $this->ensureTerm($vocab, $term_value);
            }
            if ($tid) {
              $term_ids[] = ['target_id' => $tid];
            }
          }
          if (!empty($term_ids)) {
            $entity->set($drupal_field_name, $term_ids);
            $entity->save();
            $result['summary'][] = "Assigned taxonomy terms on {$field_id}";
          }
        }
      }
    }
  }

  /**
   * Ensure a taxonomy term exists by name in a vocabulary, return tid.
   */
  private function ensureTerm(string $vocabulary, string $name) {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing = $term_storage->loadByProperties(['vid' => $vocabulary, 'name' => $name]);
    if (!empty($existing)) {
      /** @var \Drupal\taxonomy\Entity\Term $term */
      $term = reset($existing);
      return (int) $term->id();
    }
    $term = $term_storage->create([
      'vid' => $vocabulary,
      'name' => $name,
    ]);
    $term->save();
    return (int) $term->id();
  }

  /**
   * Creates a field for an entity type.
   */
  private function createField($entity_type, $bundle, array $field_config, $preview_mode, array &$result) {
    $field_id = $field_config['id'];
    $field_label = $field_config['name'];
    $field_type = $field_config['type'];
    $required = $field_config['required'] ?? FALSE;

    // Handle reserved field names.
    if ($this->isReservedField($field_id, $entity_type)) {
      if ($field_id === 'body' && $entity_type === 'node') {
        // Special handling for body field. Need to add it to the content type.
        $this->addBodyFieldToContentType($bundle, $preview_mode, $result);
      } else {
        if ($preview_mode) {
          $result['summary'][] = "Would use existing reserved field: {$field_label} ({$field_id}) for {$entity_type} {$bundle}";
        } else {
          $result['summary'][] = "Using existing reserved field: {$field_label} ({$field_id}) for {$entity_type} {$bundle}";
        }
      }
      return;
    }

    // Convert camelCase and other formats to valid machine name.
    $field_name = 'field_' . $this->sanitizeFieldName($field_id);

    // Get Drupal field type mapping.
    $drupal_field_info = $this->fieldTypeMapper->mapFieldType($field_config);
    if (!$drupal_field_info) {
      $result['warnings'][] = "Unsupported field type '{$field_type}' for field '{$field_id}', skipping";
      return;
    }

    $drupal_field_type = $drupal_field_info['type'];
    $field_settings = $drupal_field_info['settings'] ?? [];
    $cardinality = $drupal_field_info['cardinality'] ?? 1;

    if ($preview_mode) {
      $result['summary'][] = "Would create field: {$field_label} ({$field_name}) of type {$drupal_field_type} for {$entity_type} {$bundle}";
      return;
    }

    // Create field storage.
    $field_storage_id = "{$entity_type}.{$field_name}";
    $field_storage = $this->entityTypeManager->getStorage('field_storage_config')->load($field_storage_id);
    if (!$field_storage) {
      $field_storage = $this->entityTypeManager->getStorage('field_storage_config')->create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => $drupal_field_type,
        'cardinality' => $cardinality,
        'settings' => $field_settings,
      ]);
      $field_storage->save();
    }

    // Create field instance.
    $field_id_full = "{$entity_type}.{$bundle}.{$field_name}";
    $field = $this->entityTypeManager->getStorage('field_config')->load($field_id_full);
    if (!$field) {
      $field = $this->entityTypeManager->getStorage('field_config')->create([
        'field_storage' => $field_storage,
        'bundle' => $bundle,
        'label' => $field_label,
        'required' => $required,
        'settings' => $drupal_field_info['instance_settings'] ?? [],
      ]);
      $field->save();
      $result['summary'][] = "Created field: {$field_label} ({$field_name}) for {$entity_type} {$bundle}";
    } else {
      $result['warnings'][] = "Field '{$field_name}' already exists for {$entity_type} {$bundle}, skipping";
    }
  }

  /**
   * Sanitizes a field ID to create a valid Drupal machine name.
   */
  private function sanitizeFieldName($field_id) {
    // Convert camelCase to snake_case.
    $field_name = preg_replace('/([a-z])([A-Z])/', '$1_$2', $field_id);
    // Convert to lowercase.
    $field_name = strtolower($field_name);
    // Replace invalid characters with underscores.
    $field_name = preg_replace('/[^a-z0-9_]/', '_', $field_name);
    // Remove multiple consecutive underscores.
    $field_name = preg_replace('/_+/', '_', $field_name);
    // Ensure it starts with a letter or underscore.
    if (preg_match('/^[0-9]/', $field_name)) {
      $field_name = '_' . $field_name;
    }
    // Trim underscores from start and end.
    $field_name = trim($field_name, '_');
    // Ensure it is not empty and has valid start.
    if (empty($field_name) || !preg_match('/^[a-z_]/', $field_name)) {
      $field_name = 'field_' . uniqid();
    }
    return $field_name;
  }

  /**
   * Checks if a field ID is a reserved field name in Drupal.
   */
  private function isReservedField($field_id, $entity_type) {
    $reserved_fields = [
      'node' => ['title', 'body', 'uid', 'status', 'created', 'changed', 'promote', 'sticky'],
      'paragraph' => [],
    ];
    return in_array($field_id, $reserved_fields[$entity_type] ?? []);
  }

  /**
   * Adds the body field to a node content type.
   */
  private function addBodyFieldToContentType($bundle, $preview_mode, array &$result) {
    if ($preview_mode) {
      $result['summary'][] = "Would add body field to node type: {$bundle}";
      return;
    }
    // Check if body field already exists for this bundle.
    $field_config_id = "node.{$bundle}.body";
    $field_storage = $this->entityTypeManager->getStorage('field_config');
    $existing_field = $field_storage->load($field_config_id);
    if ($existing_field) {
      // Body field already exists — ensure it's visible on the form display.
      $this->ensureBodyOnFormDisplay($bundle);
      return;
    }
    // Get or create the body field storage.
    $body_storage = $this->entityTypeManager->getStorage('field_storage_config')->load('node.body');
    if (!$body_storage) {
      // Create body field storage if it doesn't exist (clean profile).
      $body_storage = $this->entityTypeManager->getStorage('field_storage_config')->create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'type' => 'text_with_summary',
        'cardinality' => 1,
        'settings' => [],
      ]);
      $body_storage->save();
      $result['summary'][] = "Created body field storage for nodes";
    }
    // Create the body field instance.
    $field_config = $this->entityTypeManager->getStorage('field_config')->create([
      'field_storage' => $body_storage,
      'bundle' => $bundle,
      'label' => 'Body',
      'description' => '',
      'required' => FALSE,
      'settings' => [
        'display_summary' => TRUE,
        'required_summary' => FALSE,
      ],
    ]);
    $field_config->save();
    $result['summary'][] = "Added body field to node type: {$bundle}";

    // Ensure body is visible on the form display.
    $this->ensureBodyOnFormDisplay($bundle);
  }

  /**
   * Ensures the body field is visible on the form display.
   */
  private function ensureBodyOnFormDisplay($bundle) {
    $form_display = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load("node.{$bundle}.default");
    if (!$form_display) {
      return;
    }
    $body_component = $form_display->getComponent('body');
    if (!$body_component) {
      $form_display->setComponent('body', [
        'type' => 'text_textarea_with_summary',
        'weight' => 10,
        'region' => 'content',
        'settings' => [
          'rows' => 9,
          'summary_rows' => 3,
          'placeholder' => '',
          'show_summary' => FALSE,
        ],
        'third_party_settings' => [],
      ]);
      $form_display->save();
    }
  }

  /**
   * Configure GraphQL Compose settings for a content type.
   *
   * @param string $entity_type
   *   The entity type ('node' or 'paragraph').
   * @param string $bundle
   *   The bundle/content type machine name.
   * @param bool $preview_mode
   *   Whether this is preview mode.
   * @param array &$result
   *   The result array to add messages to.
   */
  private function configureGraphQLCompose(string $entity_type, string $bundle, bool $preview_mode, array &$result): void {
    if ($preview_mode) {
      $result['summary'][] = "Would configure GraphQL Compose for {$entity_type}.{$bundle}";
      return;
    }

    // Check if GraphQL Compose is available.
    if (!\Drupal::moduleHandler()->moduleExists('graphql_compose')) {
      $result['warnings'][] = "GraphQL Compose module not found, skipping GraphQL configuration for {$entity_type}.{$bundle}";
      return;
    }

    $config_name = "graphql_compose.settings";
    $config = $this->configFactory->getEditable($config_name);

    // Get current settings.
    $entity_config = $config->get('entity_config') ?: [];
    $field_config = $config->get('field_config') ?: [];

    // Configure the entity type and bundle in entity_config.
    if (!isset($entity_config[$entity_type])) {
      $entity_config[$entity_type] = [];
    }

    // Enable all the main GraphQL options.
    $entity_config[$entity_type][$bundle] = [
      'enabled' => TRUE,
      'query_load_enabled' => TRUE,
      'edges_enabled' => TRUE,
    ];

    // Enable routes for nodes only.
    if ($entity_type === 'node') {
      $entity_config[$entity_type][$bundle]['routes_enabled'] = TRUE;
    }

    // Configure field_config section.
    if (!isset($field_config[$entity_type])) {
      $field_config[$entity_type] = [];
    }
    if (!isset($field_config[$entity_type][$bundle])) {
      $field_config[$entity_type][$bundle] = [];
    }

    // Get all fields for this bundle and enable them.
    $field_definitions = $this->entityTypeManager->getStorage('field_config')->loadByProperties([
      'entity_type' => $entity_type,
      'bundle' => $bundle,
    ]);

    // Enable base fields for nodes.
    if ($entity_type === 'node') {
      $base_fields = ['body', 'title', 'created', 'changed', 'status', 'path'];
      foreach ($base_fields as $base_field) {
        $field_config[$entity_type][$bundle][$base_field] = ['enabled' => TRUE];
      }
    }

    // Enable all custom fields.
    foreach ($field_definitions as $field_definition) {
      $field_name = $field_definition->getName();
      $field_config[$entity_type][$bundle][$field_name] = ['enabled' => TRUE];
    }

    // Save both configurations.
    $config->set('entity_config', $entity_config);
    $config->set('field_config', $field_config);
    $config->save();

    $result['summary'][] = "Configured GraphQL Compose for {$entity_type}.{$bundle} with all fields enabled";
  }

  /**
   * Configure decoupled preview iframe for a node content type.
   *
   * @param string $bundle
   *   The bundle/content type machine name.
   * @param array &$result
   *   The result array to add messages to.
   */
  private function configureDecoupledPreview(string $bundle, array &$result): void {
    // Check if decoupled_preview_iframe module is available.
    if (!\Drupal::moduleHandler()->moduleExists('decoupled_preview_iframe')) {
      return;
    }

    $config = $this->configFactory->getEditable('decoupled_preview_iframe.settings');

    // Get current preview_types configuration.
    $preview_types = $config->get('preview_types') ?: [];

    // Ensure node array exists.
    if (!isset($preview_types['node'])) {
      $preview_types['node'] = [];
    }

    // Add this bundle if not already present.
    if (!isset($preview_types['node'][$bundle])) {
      $preview_types['node'][$bundle] = $bundle;
      $config->set('preview_types', $preview_types);
      $config->save();
      $result['summary'][] = "Enabled decoupled preview iframe for node type: {$bundle}";
    }
  }

  /**
   * Detect languages used in content and enable them in Drupal.
   *
   * @param array $content
   *   The content array from the import JSON.
   * @param bool $preview_mode
   *   Whether this is preview mode.
   * @param array &$result
   *   The result array to add messages to.
   */
  private function detectAndEnableLanguages(array $content, bool $preview_mode, array &$result): void {
    $languages_needed = [];
    $bundles_with_translations = [];

    // Scan content for langcode fields and translation_of references.
    foreach ($content as $item) {
      $langcode = $item['langcode'] ?? NULL;
      $translation_of = $item['translation_of'] ?? NULL;

      if ($langcode && $langcode !== 'en') {
        $languages_needed[$langcode] = TRUE;
      }

      // Track bundles that have translations.
      if ($translation_of) {
        $type = $item['type'] ?? '';
        $parts = explode('.', $type, 2);
        $entity_type = $parts[0] ?? 'node';
        $bundle = $parts[1] ?? NULL;

        if ($entity_type === 'node' && $bundle) {
          $bundles_with_translations[$bundle] = TRUE;
        }

        // Also track the translation relationship for later.
        $this->translationMap[$item['id']] = $translation_of;
      }
    }

    if (empty($languages_needed) && empty($bundles_with_translations)) {
      return;
    }

    // Enable the language module if not already enabled.
    if (!$preview_mode && !\Drupal::moduleHandler()->moduleExists('language')) {
      try {
        \Drupal::service('module_installer')->install(['language']);
        $result['summary'][] = "Enabled language module";
      }
      catch (\Exception $e) {
        $result['warnings'][] = "Failed to enable language module: " . $e->getMessage();
        return;
      }
    }

    // Enable the content_translation module if needed.
    if (!$preview_mode && !empty($bundles_with_translations) && !\Drupal::moduleHandler()->moduleExists('content_translation')) {
      try {
        \Drupal::service('module_installer')->install(['content_translation']);
        $result['summary'][] = "Enabled content_translation module";
      }
      catch (\Exception $e) {
        $result['warnings'][] = "Failed to enable content_translation module: " . $e->getMessage();
      }
    }

    // Enable each required language.
    foreach (array_keys($languages_needed) as $langcode) {
      $this->enableLanguage($langcode, $preview_mode, $result);
    }

    // Enable content translation for bundles that need it.
    foreach (array_keys($bundles_with_translations) as $bundle) {
      $this->enableContentTranslationForBundle('node', $bundle, $preview_mode, $result);
      $this->bundlesNeedingTranslation["node.{$bundle}"] = TRUE;
    }
  }

  /**
   * Enable a language in Drupal.
   *
   * @param string $langcode
   *   The language code (e.g., 'es', 'fr', 'de').
   * @param bool $preview_mode
   *   Whether this is preview mode.
   * @param array &$result
   *   The result array to add messages to.
   */
  private function enableLanguage(string $langcode, bool $preview_mode, array &$result): void {
    if ($preview_mode) {
      $result['summary'][] = "Would enable language: {$langcode}";
      return;
    }

    // Check if language already exists.
    $language_manager = \Drupal::languageManager();
    $languages = $language_manager->getLanguages();

    if (isset($languages[$langcode])) {
      return; // Language already enabled.
    }

    // Create the language from predefined list.
    try {
      $language = \Drupal\language\Entity\ConfigurableLanguage::createFromLangcode($langcode);
      $language->save();
      $result['summary'][] = "Enabled language: {$langcode} ({$language->getName()})";
    }
    catch (\Exception $e) {
      $result['warnings'][] = "Failed to enable language '{$langcode}': " . $e->getMessage();
    }
  }

  /**
   * Enable content translation for a bundle.
   *
   * @param string $entity_type
   *   The entity type (e.g., 'node').
   * @param string $bundle
   *   The bundle machine name.
   * @param bool $preview_mode
   *   Whether this is preview mode.
   * @param array &$result
   *   The result array to add messages to.
   */
  private function enableContentTranslationForBundle(string $entity_type, string $bundle, bool $preview_mode, array &$result): void {
    if ($preview_mode) {
      $result['summary'][] = "Would enable content translation for {$entity_type}.{$bundle}";
      return;
    }

    if (!\Drupal::moduleHandler()->moduleExists('content_translation')) {
      return;
    }

    // Use the language settings config entity API instead of raw config.
    // Support both storage IDs used across Drupal/core variants.
    $language_settings_storage = NULL;
    if ($this->entityTypeManager->hasDefinition('language_content_settings')) {
      $language_settings_storage = 'language_content_settings';
    }
    elseif ($this->entityTypeManager->hasDefinition('content_language_settings')) {
      $language_settings_storage = 'content_language_settings';
    }

    if (!$language_settings_storage) {
      $result['warnings'][] = "Skipped content translation enablement for {$entity_type}.{$bundle}: language settings entity type is unavailable";
      return;
    }

    $config_id = "{$entity_type}.{$bundle}";

    /** @var \Drupal\language\ContentLanguageSettingsInterface $content_language_settings */
    $content_language_settings = $this->entityTypeManager
      ->getStorage($language_settings_storage)
      ->load($config_id);

    if (!$content_language_settings) {
      // Create new content language settings entity.
      $content_language_settings = $this->entityTypeManager
        ->getStorage($language_settings_storage)
        ->create([
          'id' => $config_id,
          'target_entity_type_id' => $entity_type,
          'target_bundle' => $bundle,
        ]);
    }

    // Check if already enabled for content translation.
    if ($content_language_settings->getThirdPartySetting('content_translation', 'enabled')) {
      return; // Already enabled.
    }

    // Enable content translation for this bundle.
    $content_language_settings->setDefaultLangcode('site_default');
    $content_language_settings->setLanguageAlterable(TRUE);
    $content_language_settings->setThirdPartySetting('content_translation', 'enabled', TRUE);
    $content_language_settings->setThirdPartySetting('content_translation', 'bundle_settings', [
      'untranslatable_fields_hide' => '0',
    ]);
    $content_language_settings->save();

    // Clear caches so Drupal recognizes the new translation settings.
    \Drupal::entityTypeManager()->clearCachedDefinitions();
    drupal_static_reset();

    $result['summary'][] = "Enabled content translation for {$entity_type}.{$bundle}";
  }

  /**
   * Create a URL alias for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   * @param string $path
   *   The desired path alias.
   * @param array &$result
   *   The result array to add messages to.
   */
  private function createPathAlias($node, string $path, array &$result): void {
    // Check if path alias module is available.
    if (!\Drupal::moduleHandler()->moduleExists('path_alias')) {
      $result['warnings'][] = "Path alias module not available, cannot set path for node {$node->id()}";
      return;
    }

    // Get the path alias storage.
    $path_alias_storage = $this->entityTypeManager->getStorage('path_alias');

    // Create the path alias.
    $path_alias = $path_alias_storage->create([
      'path' => '/node/' . $node->id(),
      'alias' => $path,
      'langcode' => $node->language()->getId(),
    ]);

    try {
      $path_alias->save();
      $result['summary'][] = "Created path alias: {$path} for node {$node->id()}";
    } catch (\Exception $e) {
      $result['warnings'][] = "Failed to create path alias '{$path}' for node {$node->id()}: " . $e->getMessage();
    }
  }

  /**
   * Handle image field values with URI references.
   *
   * @param array $value
   *   Array containing uri, alt, title, etc.
   * @param string $field_id
   *   The field identifier.
   *
   * @return array|null
   *   File entity reference array or NULL.
   */
  private function handleImageFieldValue(array $value, string $field_id) {
    $uri = $value['uri'] ?? NULL;
    if (!$uri) {
      \Drupal::logger('dc_import')->warning('No URI provided for image field @field_id', ['@field_id' => $field_id]);
      return NULL;
    }

    \Drupal::logger('dc_import')->info('Processing image field @field_id with URI: @uri', [
      '@field_id' => $field_id,
      '@uri' => $uri
    ]);

    $source_path = NULL;
    $filename = NULL;

    // Handle different URI schemes.
    if (strpos($uri, 'module://') === 0) {
      // Module resource: module://module_name/path/to/file.ext
      $path_parts = explode('/', substr($uri, 9)); // Remove 'module://'
      $module_name = array_shift($path_parts);
      $relative_path = implode('/', $path_parts);

      $module_path = \Drupal::service('extension.list.module')->getPath($module_name);
      $source_path = $module_path . '/' . $relative_path;
      $filename = basename($relative_path);
    } elseif (strpos($uri, '/') === 0) {
      // Relative path from Drupal root (e.g., /modules/custom/dc_import/resources/placeholder.png)
      $source_path = \Drupal::root() . $uri;
      $filename = basename($uri);
    } elseif (strpos($uri, 'http://') === 0 || strpos($uri, 'https://') === 0) {
      // HTTP/HTTPS URL - download the file
      $filename = basename(parse_url($uri, PHP_URL_PATH)) ?: 'imported_image_' . uniqid();
      $temp_file = \Drupal::service('file_system')->tempnam('temporary://', 'import_');

      // Download the file
      $context = stream_context_create([
        'http' => [
          'timeout' => 30,
          'user_agent' => 'Drupal dc_import module'
        ]
      ]);

      if (copy($uri, $temp_file, $context)) {
        $source_path = $temp_file;

        // Check if filename has a valid image extension, if not detect from MIME type
        $has_extension = preg_match('/\.(jpe?g|png|gif|webp|svg)$/i', $filename);
        if (!$has_extension && function_exists('mime_content_type')) {
          $mime_type = mime_content_type($temp_file);
          $extension_map = [
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            'image/svg+xml' => '.svg',
          ];
          if (isset($extension_map[$mime_type])) {
            $filename .= $extension_map[$mime_type];
            \Drupal::logger('dc_import')->info('Detected MIME type @mime for @uri, adding extension: @filename', [
              '@mime' => $mime_type,
              '@uri' => $uri,
              '@filename' => $filename,
            ]);
          } else {
            // Default to .jpg for unknown image types
            $filename .= '.jpg';
            \Drupal::logger('dc_import')->warning('Unknown MIME type @mime for @uri, defaulting to .jpg', [
              '@mime' => $mime_type,
              '@uri' => $uri,
            ]);
          }
        }
      }
    } else {
      // Assume it's a local file path
      $source_path = $uri;
      $filename = basename($uri);
    }

    if (!$source_path || !file_exists($source_path)) {
      \Drupal::logger('dc_import')->warning('Image source not found or accessible: @path for field @field_id', [
        '@path' => $source_path,
        '@field_id' => $field_id
      ]);
      return NULL;
    }

    // Create the file entity.
    $file_storage = $this->entityTypeManager->getStorage('file');

    // Generate destination path with date-based directory.
    $destination_dir = 'public://' . date('Y-m');
    $destination = $destination_dir . '/' . $filename;

    // Ensure directory exists.
    \Drupal::service('file_system')->prepareDirectory($destination_dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

    // Copy file to destination.
    $file_uri = \Drupal::service('file_system')->copy($source_path, $destination, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);

    // Clean up temp file if it was downloaded
    if (strpos($uri, 'http') === 0 && $source_path !== $uri) {
      unlink($source_path);
    }

    if ($file_uri) {
      // Create file entity.
      $file = $file_storage->create([
        'filename' => $filename,
        'uri' => $file_uri,
        'status' => 1,
        'uid' => 1,
      ]);
      $file->save();

      \Drupal::logger('dc_import')->info('Successfully created file entity ID @file_id for field @field_id', [
        '@file_id' => $file->id(),
        '@field_id' => $field_id
      ]);

      // Return image field value structure.
      return [
        'target_id' => $file->id(),
        'alt' => $value['alt'] ?? '',
        'title' => $value['title'] ?? '',
        'width' => $value['width'] ?? NULL,
        'height' => $value['height'] ?? NULL,
      ];
    }

    \Drupal::logger('dc_import')->warning('Failed to copy file to destination for field @field_id', ['@field_id' => $field_id]);
    return NULL;
  }

  /**
   * Generates a placeholder image for empty image fields.
   *
   * @param string $alt_text
   *   The alt text to use for the image and filename.
   * @param string $field_id
   *   The field ID for logging purposes.
   *
   * @return array|null
   *   The image field value structure, or NULL on failure.
   */
  private function generatePlaceholderImage(string $alt_text, string $field_id) {
    $image_data = NULL;
    $file_extension = 'png';

    // Try to fetch image from external service (Pexels/Unsplash) if configured.
    if (\Drupal::hasService('drupalx_ai.image_generator')) {
      $image_generator = \Drupal::service('drupalx_ai.image_generator');
      $fetched_image = $image_generator->fetchImage($alt_text);

      if ($fetched_image) {
        $image_data = $fetched_image['data'];
        $file_extension = $fetched_image['extension'];
      }
    }

    // Fallback to placeholder image if no external image was fetched.
    if (!$image_data) {
      $placeholder_path = NULL;

      // Try drupalx_ai module's placeholder first (if module exists).
      if (\Drupal::moduleHandler()->moduleExists('drupalx_ai')) {
        $placeholder_path = \Drupal::service('extension.list.module')->getPath('drupalx_ai') . '/files/card.png';
        if (!file_exists($placeholder_path)) {
          $placeholder_path = NULL;
        }
      }

      // Fallback to dc_import placeholder.
      if (!$placeholder_path) {
        $placeholder_path = \Drupal::service('extension.list.module')->getPath('dc_import') . '/resources/placeholder.png';
      }

      if ($placeholder_path && file_exists($placeholder_path)) {
        $image_data = file_get_contents($placeholder_path);
        $file_extension = 'png';
      }
    }

    if (!$image_data) {
      \Drupal::logger('dc_import')->warning('Could not generate placeholder image for field @field_id', ['@field_id' => $field_id]);
      return NULL;
    }

    // Create a unique filename.
    $safe_filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($alt_text)) . '_' . uniqid() . '.' . $file_extension;
    $destination = 'public://ai-generated/' . $safe_filename;

    // Ensure directory exists.
    $directory = dirname($destination);
    \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

    // Save image data to destination.
    $file_entity = \Drupal::service('file.repository')->writeData(
      $image_data,
      $destination,
      \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE
    );

    if ($file_entity) {
      \Drupal::logger('dc_import')->info('Generated placeholder image for field @field_id (file ID: @file_id)', [
        '@field_id' => $field_id,
        '@file_id' => $file_entity->id(),
      ]);

      return [
        'target_id' => $file_entity->id(),
        'alt' => $alt_text,
        'title' => $alt_text,
      ];
    }

    return NULL;
  }

  /**
   * Checks if a field type is an image field.
   *
   * @param string $field_type
   *   The field type string from the model (e.g., 'image', 'image[]').
   *
   * @return bool
   *   TRUE if this is an image field type.
   */
  private function isImageFieldType(string $field_type): bool {
    // Match 'image', 'image!', 'image[]', 'image[]!' etc.
    return preg_match('/^image(\[\])?!?$/', $field_type) === 1;
  }

  /**
   * Fills empty image fields with placeholder images.
   *
   * @param string $entity_type
   *   The entity type (e.g., 'node', 'paragraph').
   * @param string $bundle
   *   The bundle name.
   * @param array $provided_values
   *   The values that were provided in the import JSON.
   * @param array &$entity_data
   *   The entity data array to populate with placeholders.
   */
  private function fillEmptyImageFields(string $entity_type, string $bundle, array $provided_values, array &$entity_data): void {
    // Look for image fields defined in the model that weren't provided.
    foreach ($this->fieldTypesByBundle as $key => $field_type) {
      // Parse the key: entity_type.bundle.field_id
      $parts = explode('.', $key, 3);
      if (count($parts) !== 3) {
        continue;
      }

      list($stored_entity_type, $stored_bundle, $field_id) = $parts;

      // Skip if not for this entity type and bundle.
      if ($stored_entity_type !== $entity_type || $stored_bundle !== $bundle) {
        continue;
      }

      // Skip if not an image field.
      if (!$this->isImageFieldType($field_type)) {
        continue;
      }

      // Check if value was provided.
      if (isset($provided_values[$field_id]) && !empty($provided_values[$field_id])) {
        $provided_value = $provided_values[$field_id];

        $drupal_field_name = 'field_' . $this->sanitizeFieldName($field_id);
        $title = $provided_values['title'] ?? $bundle;

        // If a string URL was provided for an image field, download it.
        if (is_string($provided_value) && (strpos($provided_value, 'http://') === 0 || strpos($provided_value, 'https://') === 0)) {
          $alt_text = ucwords(str_replace('_', ' ', $field_id)) . ' for ' . $title;

          \Drupal::logger('dc_import')->info('Downloading image from URL for field @field_id: @url', [
            '@field_id' => $field_id,
            '@url' => $provided_value,
          ]);

          $image_value = $this->handleImageFieldValue(['uri' => $provided_value, 'alt' => $alt_text], $field_id);

          if ($image_value) {
            // Check if this is a multi-value field (image[]).
            if (strpos($field_type, '[]') !== FALSE) {
              $entity_data[$drupal_field_name] = [$image_value];
            } else {
              $entity_data[$drupal_field_name] = $image_value;
            }
          }
        }
        // Handle array of string URLs for multi-value image fields.
        elseif (is_array($provided_value) && strpos($field_type, '[]') !== FALSE) {
          $image_values = [];
          foreach ($provided_value as $index => $item) {
            if (is_string($item) && (strpos($item, 'http://') === 0 || strpos($item, 'https://') === 0)) {
              $alt_text = ucwords(str_replace('_', ' ', $field_id)) . ' ' . ($index + 1) . ' for ' . $title;

              \Drupal::logger('dc_import')->info('Downloading image from URL for field @field_id[@index]: @url', [
                '@field_id' => $field_id,
                '@index' => $index,
                '@url' => $item,
              ]);

              $image_value = $this->handleImageFieldValue(['uri' => $item, 'alt' => $alt_text], $field_id);
              if ($image_value) {
                $image_values[] = $image_value;
              }
            }
          }
          if (!empty($image_values)) {
            $entity_data[$drupal_field_name] = $image_values;
          }
        }
        // Otherwise, skip - value was already properly provided.
        continue;
      }

      // Generate placeholder for this image field.
      $drupal_field_name = 'field_' . $this->sanitizeFieldName($field_id);

      // Skip if already set in entity_data.
      if (isset($entity_data[$drupal_field_name]) && !empty($entity_data[$drupal_field_name])) {
        continue;
      }

      // Generate a descriptive alt text from field_id and title.
      $title = $provided_values['title'] ?? $bundle;
      $alt_text = ucwords(str_replace('_', ' ', $field_id)) . ' for ' . $title;

      \Drupal::logger('dc_import')->info('Generating placeholder image for empty field @field_id on @bundle', [
        '@field_id' => $field_id,
        '@bundle' => $bundle,
      ]);

      $placeholder_value = $this->generatePlaceholderImage($alt_text, $field_id);

      if ($placeholder_value) {
        // Check if this is a multi-value field (image[]).
        if (strpos($field_type, '[]') !== FALSE) {
          $entity_data[$drupal_field_name] = [$placeholder_value];
        } else {
          $entity_data[$drupal_field_name] = $placeholder_value;
        }
      }
    }
  }


  /**
   * Clear GraphQL-specific caches if GraphQL modules are installed.
   *
   * @return bool
   *   TRUE if caches were cleared or GraphQL modules exist, FALSE if no GraphQL modules found.
   */
  /**
   * Auto-enable dc_puck module if any paragraph model has a "puck" configuration key.
   *
   * When content models include puck editor configuration (the "puck" key on
   * paragraph definitions), this method:
   * 1. Enables dc_puck if installed but not yet enabled
   * 2. Sets dc_puck.enabled = TRUE
   * 3. Adds the node content type to dc_puck.enabled_content_types
   */
  private function autoEnablePuck(array $bundleDefs, array &$result): void {
    $hasPuckConfig = FALSE;
    $nodeBundles = [];

    foreach ($bundleDefs as $def) {
      if (isset($def['puck'])) {
        $hasPuckConfig = TRUE;
      }
      // Collect node bundles (entries without 'entity' key or with entity=node).
      if (!isset($def['entity']) && isset($def['bundle'])) {
        $nodeBundles[] = $def['bundle'];
      }
    }

    if (!$hasPuckConfig || empty($nodeBundles)) {
      return;
    }

    $moduleHandler = \Drupal::moduleHandler();

    // If dc_puck is not installed, nothing to do.
    if (!$moduleHandler->moduleExists('dc_puck')) {
      return;
    }

    // Enable dc_puck.
    \Drupal::state()->set('dc_puck.enabled', TRUE);

    // Add node bundles to enabled content types.
    $enabledTypes = \Drupal::state()->get('dc_puck.enabled_content_types', []);
    $added = [];
    foreach ($nodeBundles as $bundle) {
      if (!in_array($bundle, $enabledTypes)) {
        $enabledTypes[] = $bundle;
        $added[] = $bundle;
      }
    }
    \Drupal::state()->set('dc_puck.enabled_content_types', $enabledTypes);

    if (!empty($added)) {
      $result['summary'][] = 'Enabled Puck editor for content types: ' . implode(', ', $added);
    }
  }

  private function clearGraphQLCaches(): bool {
    // Check if any GraphQL modules are installed before attempting cache clearing.
    $module_handler = \Drupal::moduleHandler();
    $graphql_modules = ['graphql', 'graphql_compose'];
    $has_graphql = FALSE;

    foreach ($graphql_modules as $module) {
      if ($module_handler->moduleExists($module)) {
        $has_graphql = TRUE;
        break;
      }
    }

    if (!$has_graphql) {
      // No GraphQL modules installed, skip cache clearing.
      return FALSE;
    }

    // Use GraphQL Compose's cache clearing function if available.
    if (function_exists('_graphql_compose_cache_flush')) {
      _graphql_compose_cache_flush();
      return TRUE;
    }

    // Fallback: Clear individual GraphQL cache bins.
    $cache_bins = [
      'cache.graphql.apq',
      'cache.graphql.ast',
      'cache.graphql.definitions',
      'cache.graphql.results',
      'cache.graphql_compose.definitions',
    ];

    $cleared_any = FALSE;
    foreach ($cache_bins as $cache_bin) {
      try {
        \Drupal::service($cache_bin)->deleteAll();
        $cleared_any = TRUE;
      } catch (\Exception $e) {
        // Cache service might not exist, continue with others silently.
        // Only log if we actually have GraphQL modules but services are missing.
        continue;
      }
    }

    return $cleared_any;
  }
}
