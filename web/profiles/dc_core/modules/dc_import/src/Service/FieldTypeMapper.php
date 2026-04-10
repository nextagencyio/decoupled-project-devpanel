<?php

namespace Drupal\dc_import\Service;

/**
 * Service for mapping field types to Drupal field types.
 */
class FieldTypeMapper {

  /**
   * Maps a field configuration to Drupal field type.
   *
   * @param array $field_config
   *   The field configuration.
   *
   * @return array|null
   *   Array with 'type', 'settings', 'cardinality', 'instance_settings', and optional 'widget' and 'required', or NULL if unsupported.
   */
  public function mapFieldType(array $field_config) {
    $type = $field_config['type'];
    $id = $field_config['id'];

    // New concise shorthand format detection (lowercase types or contains []/() or !).
    if (is_string($type) && (preg_match('/[\[\]\(\)!]/', $type) || strtolower($type) === $type)) {
      return $this->mapShorthandType($type, $field_config);
    }

    switch ($type) {
      case 'Text':
        return $this->mapTextField($field_config);

      case 'RichText':
        return [
          'type' => 'text_long',
          'settings' => [],
          'cardinality' => 1,
          'instance_settings' => [],
        ];

      case 'Number':
        return [
          'type' => 'integer',
          'settings' => [],
          'cardinality' => 1,
          'instance_settings' => [
            'min' => $field_config['validations'][0]['range']['min'] ?? '',
            'max' => $field_config['validations'][0]['range']['max'] ?? '',
          ],
        ];

      case 'Date':
        return [
          'type' => 'datetime',
          'settings' => [
            'datetime_type' => 'date',
          ],
          'cardinality' => 1,
          'instance_settings' => [],
        ];

      case 'DateTime':
        return [
          'type' => 'datetime',
          'settings' => [
            'datetime_type' => 'datetime',
          ],
          'cardinality' => 1,
          'instance_settings' => [],
        ];

      case 'Boolean':
        return [
          'type' => 'boolean',
          'settings' => [],
          'cardinality' => 1,
          'instance_settings' => [
            'on_label' => 'On',
            'off_label' => 'Off',
          ],
        ];

      case 'Link':
        return $this->mapLinkField($field_config);

      case 'Array':
        return $this->mapArrayField($field_config);

      case 'Paragraph':
        return $this->mapParagraphField($field_config);

      case 'Location':
      case 'Object':
        // These types are not supported, return null
        return NULL;

      default:
        return NULL;
    }
  }

  /**
   * Maps a Text field with special handling for different text types.
   */
  private function mapTextField(array $field_config) {
    $id = $field_config['id'];

    // Special handling for slug fields
    if (strpos($id, 'slug') !== FALSE || strpos($id, 'url') !== FALSE) {
      return [
        'type' => 'string',
        'settings' => [
          'max_length' => 255,
        ],
        'cardinality' => 1,
        'instance_settings' => [],
      ];
    }

    // Check validations for length limits
    $max_length = 255;
    if (isset($field_config['validations'])) {
      foreach ($field_config['validations'] as $validation) {
        if (isset($validation['size']['max'])) {
          $max_length = $validation['size']['max'];
          break;
        }
      }
    }

    // Use text_long for longer text fields
    if ($max_length > 255) {
      return [
        'type' => 'text_long',
        'settings' => [],
        'cardinality' => 1,
        'instance_settings' => [],
      ];
    }

    return [
      'type' => 'string',
      'settings' => [
        'max_length' => $max_length,
      ],
      'cardinality' => 1,
      'instance_settings' => [],
    ];
  }

  /**
   * Maps a Link field (Entry or Asset reference).
   */
  private function mapLinkField(array $field_config) {
    $link_type = $field_config['linkType'] ?? 'Entry';

    if ($link_type === 'Asset') {
      // Asset links become file/image fields
      return [
        'type' => 'image',
        'settings' => [
          'file_directory' => '[date:custom:Y]-[date:custom:m]',
          'file_extensions' => 'png gif jpg jpeg svg webp',
          'max_resolution' => '2048x2048',
          'min_resolution' => '',
          'alt_field' => TRUE,
          'alt_field_required' => TRUE,
          'title_field' => FALSE,
          'title_field_required' => FALSE,
          'default_image' => [
            'uuid' => '',
            'alt' => '',
            'title' => '',
            'width' => NULL,
            'height' => NULL,
          ],
        ],
        'cardinality' => 1,
        'instance_settings' => [],
      ];
    } else {
      // Entry links become entity references
      $target_bundles = [];

      // Extract target content types from validations
      if (isset($field_config['validations'])) {
        foreach ($field_config['validations'] as $validation) {
          if (isset($validation['linkContentType'])) {
            foreach ($validation['linkContentType'] as $content_type) {
              $target_bundles[$content_type] = $content_type;
            }
          }
        }
      }

      return [
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'node',
        ],
        'cardinality' => 1,
        'instance_settings' => [
          'handler' => 'default:node',
          'handler_settings' => [
            'target_bundles' => $target_bundles,
            'sort' => [
              'field' => '_none',
            ],
            'auto_create' => FALSE,
            'auto_create_bundle' => '',
          ],
        ],
      ];
    }
  }

  /**
   * Maps an Array field to multi-value fields.
   */
  private function mapArrayField(array $field_config) {
    $items = $field_config['items'] ?? [];
    $item_type = $items['type'] ?? 'Text';

    // Determine cardinality.
    // Unlimited by default for arrays.
    $cardinality = -1;

    if (isset($field_config['validations'])) {
      foreach ($field_config['validations'] as $validation) {
        if (isset($validation['size']['max'])) {
          $cardinality = (int) $validation['size']['max'];
          break;
        }
      }
    }

    // Create a fake field config for the item type to get the base mapping
    $item_config = [
      'type' => $item_type,
      'id' => $field_config['id'] . '_item',
      'name' => $field_config['name'] . ' Item',
    ];

    // Copy any item validations
    if (isset($items['validations'])) {
      $item_config['validations'] = $items['validations'];
    }

    $base_mapping = $this->mapFieldType($item_config);

    if (!$base_mapping) {
      return NULL;
    }

    // Override cardinality for array
    $base_mapping['cardinality'] = $cardinality;

    return $base_mapping;
  }

  /**
   * Maps a Paragraph field to entity reference revisions.
   */
  private function mapParagraphField(array $field_config) {
    $paragraph_type = $field_config['paragraphType'] ?? 'default';

    return [
      'type' => 'entity_reference_revisions',
      'settings' => [
        'target_type' => 'paragraph',
      ],
      'cardinality' => -1, // Unlimited by default for paragraphs
      'instance_settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => [
          'target_bundles' => [$paragraph_type => $paragraph_type],
          'sort' => [
            'field' => '_none',
          ],
          'auto_create' => FALSE,
          'auto_create_bundle' => $paragraph_type,
        ],
      ],
    ];
  }

  /**
   * Maps a shorthand type string to Drupal field definition.
   *
   * Supported examples:
   * - string, text, rich, int, number, bool, date, datetime, image.
   * - term(tags), term(tags)[], term(tags)!.
   * - paragraph(hero_section), paragraph(hero_section)[]!
   * - ref(node:blog_post), ref(paragraph:hero_section)[]
   */
  private function mapShorthandType(string $type, array $field_config) {
    $original = $type;

    $required = FALSE;
    if (substr($type, -1) === '!') {
      $required = TRUE;
      $type = substr($type, 0, -1);
    }

    $cardinality = 1;
    if (substr($type, -2) === '[]') {
      $cardinality = -1;
      $type = substr($type, 0, -2);
    }

    $base = $type;
    $param = NULL;
    if (preg_match('/^([a-z_]+)\(([^)]+)\)$/', $type, $m)) {
      $base = $m[1];
      $param = $m[2];
    }

    switch ($base) {
      case 'string':
        return [
          'type' => 'string',
          'settings' => [
            'max_length' => 255,
          ],
          'cardinality' => $cardinality,
          'instance_settings' => [],
          'required' => $required,
          'widget' => 'string_textfield',
        ];

      case 'text':
      case 'rich':
        return [
          'type' => 'text_long',
          'settings' => [],
          'cardinality' => $cardinality,
          'instance_settings' => [],
          'required' => $required,
          'widget' => 'text_textarea',
        ];

      case 'int':
      case 'integer':
        return [
          'type' => 'integer',
          'settings' => [],
          'cardinality' => $cardinality,
          'instance_settings' => [],
          'required' => $required,
          'widget' => 'number',
        ];

      case 'number':
        return [
          'type' => 'decimal',
          'settings' => [
            'precision' => 10,
            'scale' => 2,
          ],
          'cardinality' => $cardinality,
          'instance_settings' => [],
          'required' => $required,
          'widget' => 'number',
        ];

      case 'bool':
        return [
          'type' => 'boolean',
          'settings' => [],
          'cardinality' => $cardinality,
          'instance_settings' => [
            'on_label' => 'On',
            'off_label' => 'Off',
          ],
          'required' => $required,
          'widget' => 'boolean_checkbox',
        ];

      case 'date':
        return [
          'type' => 'datetime',
          'settings' => [
            'datetime_type' => 'date',
          ],
          'cardinality' => $cardinality,
          'instance_settings' => [],
          'required' => $required,
          'widget' => 'datetime_default',
        ];

      case 'datetime':
        return [
          'type' => 'datetime',
          'settings' => [
            'datetime_type' => 'datetime',
          ],
          'cardinality' => $cardinality,
          'instance_settings' => [],
          'required' => $required,
          'widget' => 'datetime_default',
        ];

      case 'select':
        // select(option1|option2|option3) → list_string with allowed values.
        $allowed_values = [];
        if ($param) {
          $options = preg_split('/[|,]/', $param);
          foreach ($options as $option) {
            $option = trim($option);
            if ($option) {
              // Drupal expects allowed_values as key => label pairs.
              $label = ucwords(str_replace(['_', '-'], ' ', $option));
              $allowed_values[$option] = $label;
            }
          }
        }
        return [
          'type' => 'list_string',
          'settings' => [
            'allowed_values' => $allowed_values,
          ],
          'cardinality' => $cardinality,
          'instance_settings' => [],
          'required' => $required,
          'widget' => 'options_select',
        ];

      case 'image':
        return [
          'type' => 'image',
          'settings' => [
            'file_directory' => '[date:custom:Y]-[date:custom:m]',
            'file_extensions' => 'png gif jpg jpeg svg webp',
            'max_resolution' => '2048x2048',
            'min_resolution' => '',
            'alt_field' => TRUE,
            'alt_field_required' => TRUE,
            'title_field' => FALSE,
            'title_field_required' => FALSE,
            'default_image' => [
              'uuid' => '',
              'alt' => '',
              'title' => '',
              'width' => NULL,
              'height' => NULL,
            ],
          ],
          'cardinality' => $cardinality,
          'instance_settings' => [],
          'required' => $required,
          'widget' => 'image_image',
        ];

      case 'paragraph':
        // Support multiple paragraph types separated by | or , (e.g., "hero|card_group|sidebyside")
        $target_bundles = [];
        if ($param) {
          $types = preg_split('/[|,]/', $param);
          foreach ($types as $type) {
            $type = trim($type);
            if ($type) {
              $target_bundles[$type] = $type;
            }
          }
        }
        // If no types specified, allow all paragraph types (empty target_bundles with negate=true)
        $first_bundle = !empty($target_bundles) ? array_key_first($target_bundles) : '';
        return [
          'type' => 'entity_reference_revisions',
          'settings' => [
            'target_type' => 'paragraph',
          ],
          'cardinality' => $cardinality,
          'instance_settings' => [
            'handler' => 'default:paragraph',
            'handler_settings' => [
              'negate' => empty($target_bundles) ? 1 : 0,
              'target_bundles' => $target_bundles,
              'target_bundles_drag_drop' => $this->buildDragDropConfig($target_bundles),
              'sort' => [
                'field' => '_none',
              ],
              'auto_create' => FALSE,
              'auto_create_bundle' => $first_bundle,
            ],
          ],
          'required' => $required,
          'widget' => 'paragraphs',
        ];

      case 'term':
        $vocabulary = $param ?: 'tags';
        return [
          'type' => 'entity_reference',
          'settings' => [
            'target_type' => 'taxonomy_term',
          ],
          'cardinality' => $cardinality,
          'instance_settings' => [
            'handler' => 'default:taxonomy_term',
            'handler_settings' => [
              'target_bundles' => [$vocabulary => $vocabulary],
              'sort' => [
                'field' => '_none',
              ],
              'auto_create' => FALSE,
              'auto_create_bundle' => $vocabulary,
            ],
          ],
          'required' => $required,
          'widget' => 'entity_reference_autocomplete',
        ];

      case 'ref':
        // ref(node:bundle) or ref(paragraph:bundle) or ref(paragraph:hero|card|sidebyside).
        $target_type = 'node';
        $bundle = NULL;
        if ($param && strpos($param, ':') !== FALSE) {
          [$target_type, $bundle] = explode(':', $param, 2);
        }
        if ($target_type === 'paragraph') {
          // Support multiple paragraph types separated by | or ,
          $target_bundles = [];
          if ($bundle) {
            $types = preg_split('/[|,]/', $bundle);
            foreach ($types as $type) {
              $type = trim($type);
              if ($type) {
                $target_bundles[$type] = $type;
              }
            }
          }
          $first_bundle = !empty($target_bundles) ? array_key_first($target_bundles) : '';
          return [
            'type' => 'entity_reference_revisions',
            'settings' => [
              'target_type' => 'paragraph',
            ],
            'cardinality' => $cardinality,
            'instance_settings' => [
              'handler' => 'default:paragraph',
              'handler_settings' => [
                'negate' => empty($target_bundles) ? 1 : 0,
                'target_bundles' => $target_bundles,
                'target_bundles_drag_drop' => $this->buildDragDropConfig($target_bundles),
                'sort' => [
                  'field' => '_none',
                ],
                'auto_create' => FALSE,
                'auto_create_bundle' => $first_bundle,
              ],
            ],
            'required' => $required,
            'widget' => 'paragraphs',
          ];
        }
        // Default to node.
        return [
          'type' => 'entity_reference',
          'settings' => [
            'target_type' => 'node',
          ],
          'cardinality' => $cardinality,
          'instance_settings' => [
            'handler' => 'default:node',
            'handler_settings' => [
              'target_bundles' => $bundle ? [$bundle => $bundle] : [],
              'sort' => [
                'field' => '_none',
              ],
              'auto_create' => FALSE,
              'auto_create_bundle' => $bundle ?: '',
            ],
          ],
          'required' => $required,
          'widget' => 'entity_reference_autocomplete',
        ];

      default:
        return NULL;
    }
  }

  /**
   * Build drag drop configuration for paragraph types.
   *
   * @param array $target_bundles
   *   Array of target bundle machine names.
   *
   * @return array
   *   Drag drop configuration array.
   */
  private function buildDragDropConfig(array $target_bundles) {
    $config = [];
    $weight = 0;
    foreach ($target_bundles as $bundle) {
      $config[$bundle] = [
        'enabled' => TRUE,
        'weight' => $weight++,
      ];
    }
    return $config;
  }

}
