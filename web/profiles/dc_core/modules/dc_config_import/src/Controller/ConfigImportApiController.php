<?php

namespace Drupal\dc_config_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dc_config_import\Service\DrupalConfigImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST API controller for dc_config_import.
 */
class ConfigImportApiController extends ControllerBase {

  /**
   * The config importer service.
   *
   * @var \Drupal\dc_config_import\Service\DrupalConfigImporter
   */
  protected $importer;

  /**
   * Constructs a new ConfigImportApiController.
   */
  public function __construct(DrupalConfigImporter $importer) {
    $this->importer = $importer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('dc_config_import.importer')
    );
  }

  /**
   * Import configuration from YAML via REST API.
   */
  public function import(Request $request) {
    try {
      if (!$this->authenticateRequest($request)) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Authentication required. Please provide a valid Decoupled Drupal personal access token.',
          'format' => 'X-Decoupled-Token: dc_tok_...',
        ], 401);
      }

      $content = $request->getContent();
      if (empty($content)) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Request body cannot be empty',
        ], 400);
      }

      $data = json_decode($content, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Invalid JSON: ' . json_last_error_msg(),
        ], 400);
      }

      if (!isset($data['configs']) || !is_array($data['configs'])) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Invalid JSON structure. Expected "configs" array with objects containing "name" and "yaml" keys.',
          'example' => [
            'configs' => [
              ['name' => 'image.style.wide', 'yaml' => "langcode: en\nstatus: true\nname: wide\nlabel: Wide\neffects:\n  - id: image_scale\n    data:\n      width: 1200\n      height: null\n      upscale: false"],
            ],
          ],
        ], 400);
      }

      $preview = $request->query->get('preview') === 'true';

      $result = $this->importer->import($data, $preview);

      return new JsonResponse($result);

    }
    catch (\Exception $e) {
      \Drupal::logger('dc_config_import')->error('Config import error: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => false,
        'error' => 'Config import failed: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Get service status.
   */
  public function status() {
    return new JsonResponse([
      'success' => true,
      'service' => 'Decoupled Config Import API',
      'version' => '1.0.0',
      'endpoints' => [
        'POST /api/dc-config-import' => 'Import Drupal configuration YAML',
        'GET /api/dc-config-import/status' => 'Get service status',
        'GET /api/dc-config-import/allowed-types' => 'Get allowed config type prefixes',
      ],
      'authentication' => [
        'required' => 'X-Decoupled-Token: dc_tok_... OR OAuth Bearer token',
      ],
    ]);
  }

  /**
   * Get allowed config type prefixes.
   */
  public function allowedTypes(Request $request) {
    if (!$this->authenticateRequest($request)) {
      return new JsonResponse([
        'success' => false,
        'error' => 'Authentication required.',
      ], 401);
    }

    return new JsonResponse([
      'success' => true,
      'allowed_prefixes' => $this->importer->getAllowedPrefixes(),
      'blocked_prefixes' => $this->importer->getBlockedPrefixes(),
      'note' => 'Config names must match an allowed prefix and must NOT match a blocked prefix.',
    ]);
  }

  /**
   * Authenticate the request via X-Decoupled-Token header.
   *
   * Accepts both platform PAT tokens (dc_tok_...) and OAuth access tokens.
   * All tokens are sent via X-Decoupled-Token to avoid conflicts with
   * Drupal's simple_oauth module intercepting Authorization: Bearer headers.
   */
  private function authenticateRequest(Request $request) {
    // Development mode bypass for .ddev.site domains.
    $skipAuth = getenv('DECOUPLED_SKIP_AUTH') === 'true' ||
                \Drupal::state()->get('dc_config_import.skip_auth', FALSE) ||
                str_contains($_SERVER['HTTP_HOST'] ?? '', '.ddev.site');

    if ($skipAuth) {
      \Drupal::logger('dc_config_import')->info('Authentication skipped - development mode');
      return TRUE;
    }

    // All tokens come via X-Decoupled-Token header.
    $token = $request->headers->get('X-Decoupled-Token');
    if (empty($token)) {
      return FALSE;
    }

    // Try platform PAT validation first (dc_tok_ tokens).
    if (str_starts_with($token, 'dc_tok_')) {
      return $this->validatePlatformToken($token);
    }

    // Otherwise treat as OAuth access token (format check).
    if (strlen($token) >= 32 && preg_match('/^[a-zA-Z0-9_.-]+$/', $token)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Validate platform personal access token against dashboard API.
   */
  private function validatePlatformToken($token) {
    $platformUrl = getenv('DECOUPLED_PLATFORM_URL') ?:
                   \Drupal::state()->get('dc_config_import.platform_url', 'https://dashboard.decoupled.io');

    if (str_contains($_SERVER['HTTP_HOST'] ?? '', '.ddev.site')) {
      $platformUrl = 'http://host.docker.internal:3333';
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $platformUrl . '/api/auth/validate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Bearer ' . $token,
      'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
      \Drupal::logger('dc_config_import')->warning('Token validation failed: @error', ['@error' => $error]);
      return FALSE;
    }

    if ($httpCode === 200) {
      $data = json_decode($response, TRUE);
      if (isset($data['valid']) && $data['valid'] === TRUE) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
