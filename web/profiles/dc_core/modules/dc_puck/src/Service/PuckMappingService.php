<?php

namespace Drupal\dc_puck\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;

/**
 * Maps Puck editor JSON to/from Drupal paragraph entities.
 *
 * Handles both flat fields (string, text) and nested paragraph references
 * (cards, FAQ items, testimonials, etc.) which become Puck array fields.
 */
class PuckMappingService {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StateInterface $state,
  ) {}

  /**
   * Get the configured paragraph reference field name.
   */
  public function getSectionsField(): string {
    return $this->state->get('dc_puck.sections_field', 'field_sections');
  }

  public function getMapping(): array {
    $mapping = $this->state->get('dc_puck.component_map', []);
    if (empty($mapping)) {
      $mapping = $this->buildDefaultMapping();
      $this->state->set('dc_puck.component_map', $mapping);
    }
    return $mapping;
  }

  public function setMapping(array $mapping): void {
    $this->state->set('dc_puck.component_map', $mapping);
  }

  /**
   * Load a node's paragraphs and transform to Puck JSON data.
   */
  public function loadPuckData(NodeInterface $node): array {
    $mapping = $this->getMapping();
    $reverseMap = $this->buildReverseMap($mapping);

    $content = [];
    if ($node->hasField($this->getSectionsField())) {
      foreach ($node->get($this->getSectionsField())->referencedEntities() as $paragraph) {
        $component = $this->paragraphToPuck($paragraph, $reverseMap, $mapping);
        if ($component) {
          $content[] = $component;
        }
      }
    }

    return [
      'content' => $content,
      'root' => [
        'props' => [
          'title' => $node->getTitle(),
        ],
      ],
      'zones' => new \stdClass(),
    ];
  }

  /**
   * Transform a single paragraph entity to a Puck component.
   */
  protected function paragraphToPuck($paragraph, array $reverseMap, array $mapping): ?array {
    $bundle = $paragraph->bundle();
    if (!isset($reverseMap[$bundle])) {
      return NULL;
    }

    $puckType = $reverseMap[$bundle]['puck_type'];
    $fieldMap = $reverseMap[$bundle]['fields'];

    $props = [
      'id' => $puckType . '-' . substr($paragraph->uuid(), 0, 8),
      '_drupalUuid' => $paragraph->uuid(),
      '_drupalRevisionId' => $paragraph->getRevisionId(),
    ];

    foreach ($fieldMap as $drupalField => $puckProp) {
      if (!$paragraph->hasField($drupalField)) {
        continue;
      }

      if ($puckProp['type'] === 'paragraphs') {
        // Nested paragraph reference — load child paragraphs as an array.
        $children = [];
        foreach ($paragraph->get($drupalField)->referencedEntities() as $child) {
          $childBundle = $child->bundle();
          if (!isset($reverseMap[$childBundle])) {
            continue;
          }
          $childFields = $reverseMap[$childBundle]['fields'];
          $childProps = [
            '_drupalUuid' => $child->uuid(),
          ];
          foreach ($childFields as $childDrupalField => $childPuckProp) {
            if (!$child->hasField($childDrupalField)) {
              continue;
            }
            $childProps[$childPuckProp['prop']] = $this->getFieldValue($child, $childDrupalField, $childPuckProp['type']);
          }
          $children[] = $childProps;
        }
        $props[$puckProp['prop']] = $children;
      }
      elseif ($puckProp['type'] === 'boolean') {
        $props[$puckProp['prop']] = (bool) $paragraph->get($drupalField)->value;
      }
      else {
        $props[$puckProp['prop']] = $this->getFieldValue($paragraph, $drupalField, $puckProp['type']);
      }
    }

    return [
      'type' => $puckType,
      'props' => $props,
    ];
  }

  /**
   * Get a field value, handling text fields (extract .value) and plain strings.
   */
  protected function getFieldValue($entity, string $fieldName, string $type): mixed {
    $fieldItem = $entity->get($fieldName)->first();
    if (!$fieldItem) {
      return '';
    }

    // Image fields: return the file URL instead of the file ID.
    if ($type === 'image') {
      $fileId = $fieldItem->target_id ?? NULL;
      if ($fileId) {
        $file = $this->entityTypeManager->getStorage('file')->load($fileId);
        if ($file) {
          $fileUrl = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
          return $fileUrl;
        }
      }
      return '';
    }

    return $fieldItem->value ?? '';
  }

  /**
   * Transform Puck JSON data and save as paragraphs on a node.
   */
  public function savePuckData(NodeInterface $node, array $puckData): void {
    $mapping = $this->getMapping();
    $paragraphStorage = $this->entityTypeManager->getStorage('paragraph');

    // Track existing top-level paragraphs by UUID.
    $existingParagraphs = [];
    if ($node->hasField($this->getSectionsField())) {
      foreach ($node->get($this->getSectionsField())->referencedEntities() as $paragraph) {
        $existingParagraphs[$paragraph->uuid()] = $paragraph;
      }
    }

    $usedUuids = [];
    $newSections = [];

    foreach ($puckData['content'] ?? [] as $component) {
      $puckType = $component['type'] ?? '';
      $props = $component['props'] ?? [];

      if (!isset($mapping[$puckType])) {
        continue;
      }

      $componentMap = $mapping[$puckType];
      $paragraphType = $componentMap['paragraph_type'];
      $fieldMap = $componentMap['fields'];

      // Resolve existing or create new paragraph.
      $drupalUuid = $props['_drupalUuid'] ?? NULL;
      if ($drupalUuid && isset($existingParagraphs[$drupalUuid])) {
        $paragraph = $existingParagraphs[$drupalUuid];
        $usedUuids[] = $drupalUuid;
      }
      else {
        $paragraph = $paragraphStorage->create(['type' => $paragraphType]);
      }

      // Map Puck props to Drupal fields.
      foreach ($fieldMap as $puckProp => $fieldConfig) {
        $drupalField = $fieldConfig['drupal_field'];
        $fieldType = $fieldConfig['type'];
        $value = $props[$puckProp] ?? '';

        if (!$paragraph->hasField($drupalField)) {
          continue;
        }

        if ($fieldType === 'paragraphs') {
          // Nested paragraphs — create/update child entities.
          $childRefs = $this->saveNestedParagraphs(
            $paragraph,
            $drupalField,
            $value,
            $fieldConfig['target_type'] ?? '',
            $mapping
          );
          $paragraph->set($drupalField, $childRefs);
        }
        elseif ($fieldType === 'text') {
          $paragraph->set($drupalField, [
            'value' => $value,
            'format' => 'basic_html',
          ]);
        }
        elseif ($fieldType === 'boolean') {
          $paragraph->set($drupalField, (bool) $value);
        }
        elseif ($fieldType === 'image') {
          // Skip empty or fake image values.
          if (empty($value) || !is_string($value) || !str_starts_with($value, 'http')) {
            continue;
          }
          // Skip obviously fake URLs.
          if (str_contains($value, 'example.com') || str_contains($value, 'placeholder')) {
            continue;
          }
          // Download the image and create a Drupal file entity.
          $file = $this->createFileFromUrl($value, $drupalField);
          if ($file) {
            $paragraph->set($drupalField, [
              'target_id' => $file->id(),
              'alt' => '',
            ]);
          }
        }
        else {
          $paragraph->set($drupalField, $value);
        }
      }

      $paragraph->save();
      $newSections[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }

    // Delete top-level paragraphs that are no longer referenced.
    foreach ($existingParagraphs as $uuid => $paragraph) {
      if (!in_array($uuid, $usedUuids)) {
        $paragraph->delete();
      }
    }

    $node->set($this->getSectionsField(), $newSections);
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('Saved from Design Studio');
    $node->save();
  }

  /**
   * Save nested paragraph array items (cards, FAQ items, etc.).
   */
  protected function saveNestedParagraphs($parentParagraph, string $fieldName, $items, string $targetType, array $mapping): array {
    if (!is_array($items)) {
      return [];
    }

    $paragraphStorage = $this->entityTypeManager->getStorage('paragraph');

    // Find the mapping for the target paragraph type.
    $childMapping = NULL;
    foreach ($mapping as $puckType => $config) {
      if ($config['paragraph_type'] === $targetType) {
        $childMapping = $config;
        break;
      }
    }

    if (!$childMapping) {
      return [];
    }

    // Track existing child paragraphs.
    $existingChildren = [];
    if ($parentParagraph->hasField($fieldName) && !$parentParagraph->get($fieldName)->isEmpty()) {
      foreach ($parentParagraph->get($fieldName)->referencedEntities() as $child) {
        $existingChildren[$child->uuid()] = $child;
      }
    }

    $usedUuids = [];
    $childRefs = [];

    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $childUuid = $item['_drupalUuid'] ?? NULL;
      if ($childUuid && isset($existingChildren[$childUuid])) {
        $child = $existingChildren[$childUuid];
        $usedUuids[] = $childUuid;
      }
      else {
        $child = $paragraphStorage->create(['type' => $targetType]);
      }

      // Map item fields.
      foreach ($childMapping['fields'] as $puckProp => $fieldConfig) {
        $drupalField = $fieldConfig['drupal_field'];
        $value = $item[$puckProp] ?? '';

        if (!$child->hasField($drupalField)) {
          continue;
        }

        if ($fieldConfig['type'] === 'text') {
          $child->set($drupalField, ['value' => $value, 'format' => 'basic_html']);
        }
        elseif ($fieldConfig['type'] === 'boolean') {
          $child->set($drupalField, (bool) $value);
        }
        elseif ($fieldConfig['type'] === 'image') {
          if (empty($value) || !is_string($value) || !str_starts_with($value, 'http')) {
            continue;
          }
          if (str_contains($value, 'example.com') || str_contains($value, 'placeholder')) {
            continue;
          }
          $file = $this->createFileFromUrl($value, $drupalField);
          if ($file) {
            $child->set($drupalField, [
              'target_id' => $file->id(),
              'alt' => '',
            ]);
          }
        }
        else {
          $child->set($drupalField, $value);
        }
      }

      $child->save();
      $childRefs[] = [
        'target_id' => $child->id(),
        'target_revision_id' => $child->getRevisionId(),
      ];
    }

    // Delete removed children.
    foreach ($existingChildren as $uuid => $child) {
      if (!in_array($uuid, $usedUuids)) {
        $child->delete();
      }
    }

    return $childRefs;
  }

  /**
   * Build a reverse map: paragraph_bundle => puck_type + field mappings.
   */
  protected function buildReverseMap(array $mapping): array {
    $reverse = [];
    foreach ($mapping as $puckType => $config) {
      $bundle = $config['paragraph_type'];
      $fields = [];
      foreach ($config['fields'] as $puckProp => $fieldConfig) {
        $drupalField = $fieldConfig['drupal_field'];
        $fields[$drupalField] = [
          'prop' => $puckProp,
          'type' => $fieldConfig['type'],
        ];
      }
      $reverse[$bundle] = [
        'puck_type' => $puckType,
        'fields' => $fields,
      ];
    }
    return $reverse;
  }

  /**
   * Known aliases where the Puck component name differs from the
   * auto-detected PascalCase bundle name.
   */
  const PUCK_NAME_ALIASES = [
    'Quote' => 'Testimonials',
    'Sidebyside' => 'SideBySide',
  ];

  /**
   * Auto-detect paragraph types and build a default mapping.
   */
  protected function buildDefaultMapping(): array {
    $mapping = [];
    $paragraphTypeStorage = $this->entityTypeManager->getStorage('paragraphs_type');
    $fieldConfigStorage = $this->entityTypeManager->getStorage('field_config');

    foreach ($paragraphTypeStorage->loadMultiple() as $type) {
      $bundle = $type->id();
      $label = $type->label();
      $puckType = str_replace(' ', '', ucwords(str_replace('_', ' ', $bundle)));

      $fields = [];
      $fieldConfigs = $fieldConfigStorage->loadByProperties([
        'entity_type' => 'paragraph',
        'bundle' => $bundle,
      ]);

      foreach ($fieldConfigs as $fieldConfig) {
        $fieldName = $fieldConfig->getName();
        if (!str_starts_with($fieldName, 'field_')) {
          continue;
        }

        $puckProp = $this->fieldNameToCamelCase($fieldName);
        $fieldType = $fieldConfig->getType();

        if ($fieldType === 'entity_reference_revisions') {
          // Nested paragraph reference (cards, FAQ items, etc.).
          $handlerSettings = $fieldConfig->getSetting('handler_settings');
          $targetBundles = $handlerSettings['target_bundles'] ?? [];
          $targetType = !empty($targetBundles) ? reset($targetBundles) : '';

          if (!empty($targetType)) {
            $fields[$puckProp] = [
              'drupal_field' => $fieldName,
              'type' => 'paragraphs',
              'target_type' => $targetType,
              'label' => $fieldConfig->getLabel(),
            ];
          }
          else {
            // No target bundle — treat as a skip (unsupported field).
            // This handles edge cases like string[] imported as ERR without bundles.
          }
        }
        elseif (in_array($fieldType, ['text_long', 'text_with_summary'])) {
          $fields[$puckProp] = [
            'drupal_field' => $fieldName,
            'type' => 'text',
            'label' => $fieldConfig->getLabel(),
          ];
        }
        elseif ($fieldType === 'boolean') {
          $fields[$puckProp] = [
            'drupal_field' => $fieldName,
            'type' => 'boolean',
            'label' => $fieldConfig->getLabel(),
          ];
        }
        elseif ($fieldType === 'image') {
          $fields[$puckProp] = [
            'drupal_field' => $fieldName,
            'type' => 'image',
            'label' => $fieldConfig->getLabel(),
          ];
        }
        else {
          $fields[$puckProp] = [
            'drupal_field' => $fieldName,
            'type' => 'string',
            'label' => $fieldConfig->getLabel(),
          ];
        }
      }

      if (!empty($fields)) {
        $mapping[$puckType] = [
          'paragraph_type' => $bundle,
          'label' => $label,
          'fields' => $fields,
        ];
      }
    }

    // Add aliases for Puck names that differ from auto-detected PascalCase.
    foreach (self::PUCK_NAME_ALIASES as $autoName => $puckName) {
      if (isset($mapping[$autoName]) && !isset($mapping[$puckName])) {
        $mapping[$puckName] = $mapping[$autoName];
      }
    }

    return $mapping;
  }

  /**
   * Convert a Drupal field name to a camelCase Puck prop name.
   */
  /**
   * Download an image from a URL and create a Drupal file entity.
   */
  protected function createFileFromUrl(string $url, string $fieldName): ?\Drupal\file\FileInterface {
    try {
      // Extract filename from URL.
      $parsed = parse_url($url);
      $path = $parsed['path'] ?? '';
      $filename = basename($path);
      // Ensure a reasonable filename with extension.
      if (!$filename || !str_contains($filename, '.')) {
        $filename = 'puck-image-' . substr(md5($url), 0, 8) . '.jpg';
      }

      // Download the image.
      $data = @file_get_contents($url);
      if ($data === FALSE) {
        \Drupal::logger('dc_puck')->warning('Failed to download image from @url', ['@url' => $url]);
        return NULL;
      }

      // Save to public files.
      $directory = 'public://puck-images';
      \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

      $file = \Drupal::service('file.repository')->writeData(
        $data,
        $directory . '/' . $filename,
        \Drupal\Core\File\FileExists::Rename
      );

      if ($file) {
        $file->setPermanent();
        $file->save();
        return $file;
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_puck')->error('Error creating file from URL @url: @error', [
        '@url' => $url,
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  protected function fieldNameToCamelCase(string $fieldName): string {
    $name = preg_replace('/^field_/', '', $fieldName);
    return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))));
  }

}
