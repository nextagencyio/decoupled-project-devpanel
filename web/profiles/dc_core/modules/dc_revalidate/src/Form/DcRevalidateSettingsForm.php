<?php

namespace Drupal\dc_revalidate\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Dc Revalidate settings.
 */
class DcRevalidateSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['dc_revalidate.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dc_revalidate_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('dc_revalidate.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable revalidation'),
      '#description' => $this->t('When enabled, Next.js revalidation will be triggered when nodes are saved.'),
      '#default_value' => $config->get('enabled') ?? FALSE,
    ];

    $form['frontend_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Frontend URL'),
      '#description' => $this->t('The base URL of your Next.js frontend (e.g., https://example.com).'),
      '#default_value' => $config->get('frontend_url') ?? '',
      '#required' => FALSE,
      '#states' => [
        'required' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['revalidate_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Revalidation Secret'),
      '#description' => $this->t('The secret key used to authenticate revalidation requests. This should match DRUPAL_REVALIDATE_SECRET in your Next.js environment.'),
      '#default_value' => $config->get('revalidate_secret') ?? '',
      '#required' => FALSE,
      '#states' => [
        'required' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('enabled')) {
      if (empty($form_state->getValue('frontend_url'))) {
        $form_state->setErrorByName('frontend_url', $this->t('Frontend URL is required when revalidation is enabled.'));
      }
      if (empty($form_state->getValue('revalidate_secret'))) {
        $form_state->setErrorByName('revalidate_secret', $this->t('Revalidation secret is required when revalidation is enabled.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('dc_revalidate.settings')
      ->set('enabled', $form_state->getValue('enabled'))
      ->set('frontend_url', $form_state->getValue('frontend_url'))
      ->set('revalidate_secret', $form_state->getValue('revalidate_secret'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}