<?php

namespace Drupal\dc_import\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dc_import\Service\DrupalContentImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for importing content from JSON.
 */
class ImportForm extends FormBase {

  /**
   * The Drupal content importer service.
   *
   * @var \Drupal\dc_import\Service\DrupalContentImporter
   */
  protected $contentImporter;

  /**
   * Constructs a new ImportForm.
   *
   * @param \Drupal\dc_import\Service\DrupalContentImporter $content_importer
   *   The Drupal content importer service.
   */
  public function __construct(DrupalContentImporter $content_importer) {
    $this->contentImporter = $content_importer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('dc_import.importer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dc_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Upload a JSON file to import content types, taxonomy vocabularies, and content into your Drupal site.') . '</p>',
    ];

    // Sample JSON for demonstration
    $sample_json_path = __DIR__ . '/../../resources/content-import-sample.json';
    $sample_json = '';

    if (file_exists($sample_json_path) && is_readable($sample_json_path)) {
      $sample_json = file_get_contents($sample_json_path);
      if ($sample_json === FALSE) {
        $sample_json = '';
        \Drupal::logger('dc_import')->error('Failed to read sample JSON file');
      }
    }

    if (!empty($sample_json)) {
      $form['sample'] = [
        '#type' => 'details',
        '#title' => $this->t('Example Import JSON'),
        '#description' => $this->t('Click to view a complete example showing all supported field types and patterns. Copy and paste this into the field below to test the import functionality.'),
        '#open' => FALSE,
      ];

      $form['sample']['json_sample'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Sample JSON'),
        '#default_value' => $sample_json,
        '#rows' => 20,
        '#attributes' => [
          'readonly' => 'readonly',
          'style' => 'font-family: monospace; font-size: 12px;',
        ],
        '#description' => $this->t('This sample demonstrates: news_article content type with images, dates, tags; product content type with pricing and inventory; paragraph entities for structured content. Copy this JSON to test the import functionality.'),
      ];
    }

    $form['json_file'] = [
      '#type' => 'file',
      '#title' => $this->t('JSON Import File'),
      '#description' => $this->t('Upload a JSON file containing the content to import. Maximum file size: 10 MB.'),
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'json'],
        'FileSizeLimit' => ['fileLimit' => '10M'],
      ],
    ];

    $form['json_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Or paste JSON directly'),
      '#description' => $this->t('Paste your JSON content here if you prefer not to upload a file. Try copying the example above to test!'),
      '#rows' => 10,
      '#attributes' => [
        'style' => 'font-family: monospace;',
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Check if either file or text was provided.
    $files = $this->getRequest()->files->get('files', []);
    $json_text = $form_state->getValue('json_text');

    if (empty($files['json_file']) && empty($json_text)) {
      $form_state->setErrorByName('json_file', $this->t('Please either upload a JSON file or paste JSON content.'));
      return;
    }

    // Get JSON content from file or textarea.
    $json_content = '';
    if (!empty($files['json_file'])) {
      $file = $files['json_file'];
      if ($file->isValid()) {
        $json_content = file_get_contents($file->getPathname());
      }
    }
    else {
      $json_content = $json_text;
    }

    // Validate JSON.
    $data = json_decode($json_content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $form_state->setErrorByName('json_file', $this->t('Invalid JSON: @error', [
        '@error' => json_last_error_msg(),
      ]));
      return;
    }

    // Store decoded data for submit handler.
    $form_state->set('import_data', $data);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $data = $form_state->get('import_data');

    try {
      $result = $this->contentImporter->import($data);

      // Check if import was successful (has summary array)
      if (isset($result['summary']) && is_array($result['summary'])) {
        $this->messenger()->addStatus($this->t('Content imported successfully!'));

        // Show summary messages
        foreach ($result['summary'] as $message) {
          $this->messenger()->addStatus($message);
        }

        // Show warnings if any (limit to first 5)
        if (!empty($result['warnings'])) {
          foreach (array_slice($result['warnings'], 0, 5) as $warning) {
            $this->messenger()->addWarning($warning);
          }
          if (count($result['warnings']) > 5) {
            $this->messenger()->addWarning($this->t('... and @count more warnings', [
              '@count' => count($result['warnings']) - 5,
            ]));
          }
        }
      }
      else {
        $this->messenger()->addError($this->t('Import failed: @message', [
          '@message' => $result['message'] ?? 'Unknown error',
        ]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Import error: @message', [
        '@message' => $e->getMessage(),
      ]));
      \Drupal::logger('dc_import')->error('Import error: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
