<?php

namespace Drupal\dc_puck\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dc_puck\Service\PuckMappingService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Puck editor settings and component mapping.
 */
class PuckMappingForm extends FormBase {

  public function __construct(
    protected PuckMappingService $mappingService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dc_puck.mapping'),
    );
  }

  public function getFormId(): string {
    return 'dc_puck_mapping_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $mapping = $this->mappingService->getMapping();

    // ── General Settings ──

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Settings'),
      '#open' => TRUE,
    ];

    $form['general']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Puck Editor'),
      '#default_value' => \Drupal::state()->get('dc_puck.enabled', FALSE),
      '#description' => $this->t('When enabled, the Design Studio tab and API endpoints become active for configured content types.'),
    ];

    $form['general']['editor_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Puck Editor URL'),
      '#default_value' => \Drupal::state()->get('dc_puck.editor_url', ''),
      '#description' => $this->t('The URL of your Puck editor app (e.g., http://localhost:3456 or https://puck.example.com).'),
    ];

    $form['general']['sections_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sections Field Name'),
      '#default_value' => \Drupal::state()->get('dc_puck.sections_field', 'field_sections'),
      '#description' => $this->t('The machine name of the paragraph reference field (e.g., field_sections, field_components).'),
      '#required' => TRUE,
    ];

    // ── Content Types ──

    $form['content_types'] = [
      '#type' => 'details',
      '#title' => $this->t('Enabled Content Types'),
      '#open' => TRUE,
    ];

    $form['content_types']['description'] = [
      '#markup' => '<p>' . $this->t('Select which content types show the Design Studio tab and support Puck editor integration.') . '</p>',
    ];

    $enabledTypes = \Drupal::state()->get('dc_puck.enabled_content_types', []);
    $sectionsField = \Drupal::state()->get('dc_puck.sections_field', 'field_sections');
    $nodeTypes = \Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple();

    $typeOptions = [];
    $typeDescriptions = [];
    foreach ($nodeTypes as $type) {
      $bundle = $type->id();
      $typeOptions[$bundle] = $type->label();

      // Check if this type has the sections field.
      $fieldConfig = \Drupal::entityTypeManager()
        ->getStorage('field_config')
        ->load("node.{$bundle}.{$sectionsField}");
      if (!$fieldConfig) {
        $typeDescriptions[$bundle] = $this->t('(missing @field field)', ['@field' => $sectionsField]);
      }
    }

    $form['content_types']['enabled_content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content Types'),
      '#options' => $typeOptions,
      '#default_value' => array_combine($enabledTypes, $enabledTypes) ?: [],
      '#description' => $this->t('Only content types with a @field paragraph reference field can use the Puck editor.', ['@field' => $sectionsField]),
    ];

    // Add warnings for types missing the field.
    foreach ($typeDescriptions as $bundle => $desc) {
      $form['content_types']['enabled_content_types'][$bundle]['#description'] = $desc;
    }

    // ── Component Mapping ──

    $form['mapping_display'] = [
      '#type' => 'details',
      '#title' => $this->t('Component Mapping'),
      '#open' => FALSE,
    ];

    $header = [
      $this->t('Puck Component'),
      $this->t('Paragraph Type'),
      $this->t('Fields'),
    ];
    $rows = [];
    foreach ($mapping as $puckType => $config) {
      $fields = [];
      foreach ($config['fields'] as $puckProp => $fieldConfig) {
        $fields[] = "{$puckProp} → {$fieldConfig['drupal_field']} ({$fieldConfig['type']})";
      }
      $rows[] = [
        $puckType,
        $config['paragraph_type'],
        implode(', ', $fields),
      ];
    }

    $form['mapping_display']['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No paragraph types found. Create paragraph types and fields first, then revisit this page.'),
    ];

    $form['mapping_json'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced: Edit Mapping JSON'),
      '#open' => FALSE,
    ];

    $form['mapping_json']['json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Mapping JSON'),
      '#default_value' => json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      '#rows' => 30,
      '#description' => $this->t('Edit the mapping JSON directly. Be careful — invalid JSON will break the mapping.'),
    ];

    // ── Actions ──

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
    ];

    $form['actions']['regenerate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Regenerate from paragraph types'),
      '#submit' => ['::regenerateMapping'],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $json = $form_state->getValue('json');
    if (!empty($json)) {
      $decoded = json_decode($json, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $form_state->setErrorByName('json', $this->t('Invalid JSON: @error', [
          '@error' => json_last_error_msg(),
        ]));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Save general settings.
    \Drupal::state()->set('dc_puck.enabled', (bool) $form_state->getValue('enabled'));
    \Drupal::state()->set('dc_puck.editor_url', $form_state->getValue('editor_url'));
    \Drupal::state()->set('dc_puck.sections_field', $form_state->getValue('sections_field'));

    // Save enabled content types (filter out unchecked).
    $enabledTypes = array_values(array_filter($form_state->getValue('enabled_content_types')));
    \Drupal::state()->set('dc_puck.enabled_content_types', $enabledTypes);

    // Save mapping JSON.
    $json = $form_state->getValue('json');
    if (!empty($json)) {
      $decoded = json_decode($json, TRUE);
      if ($decoded !== NULL) {
        $this->mappingService->setMapping($decoded);
      }
    }

    $this->messenger()->addStatus($this->t('Puck editor configuration saved.'));
  }

  /**
   * Regenerate mapping from current paragraph types.
   */
  public function regenerateMapping(array &$form, FormStateInterface $form_state): void {
    $this->mappingService->setMapping([]);
    $this->mappingService->getMapping();
    $this->messenger()->addStatus($this->t('Mapping regenerated from paragraph type definitions.'));
  }

}
