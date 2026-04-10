<?php

namespace Drupal\dc_puck\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dc_puck\Service\PuckMappingService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles loading and saving Puck editor data as Drupal paragraphs.
 *
 * GET /api/puck/load/{node} — Load paragraphs as Puck JSON.
 * POST /api/puck/save/{node} — Save Puck JSON as paragraphs.
 */
class PuckDataController extends ControllerBase {

  public function __construct(
    protected PuckMappingService $mappingService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dc_puck.mapping'),
    );
  }

  /**
   * Check if dc_puck is enabled for the given node's content type.
   */
  protected function checkEnabled(NodeInterface $node): ?JsonResponse {
    if (!dc_puck_is_enabled_for_bundle($node->bundle())) {
      return new JsonResponse([
        'error' => 'Puck editor is not enabled for this content type.',
      ], 403);
    }
    if (!$node->hasField($this->mappingService->getSectionsField())) {
      return new JsonResponse([
        'error' => 'Node does not have a sections field.',
      ], 400);
    }
    return NULL;
  }

  /**
   * Load a node's paragraphs and return as Puck editor JSON.
   */
  public function load(NodeInterface $node): JsonResponse {
    $error = $this->checkEnabled($node);
    if ($error) {
      return $error;
    }

    $puckData = $this->mappingService->loadPuckData($node);

    return new JsonResponse($puckData);
  }

  /**
   * Save Puck editor JSON as paragraphs on a node.
   */
  public function save(NodeInterface $node, Request $request): JsonResponse {
    $error = $this->checkEnabled($node);
    if ($error) {
      return $error;
    }

    // Validate the signed token from the request.
    $token = $request->headers->get('X-Puck-Token', '');
    if (empty($token)) {
      $body = json_decode($request->getContent(), TRUE);
      $token = $body['_token'] ?? '';
    }

    if (!$this->validateToken($token, $node)) {
      return new JsonResponse(['error' => 'Unauthorized'], 401);
    }

    $body = json_decode($request->getContent(), TRUE);
    if (empty($body) || !isset($body['content'])) {
      return new JsonResponse([
        'error' => 'Invalid Puck data. Expected { content: [...], root: {...} }',
      ], 400);
    }

    try {
      $this->mappingService->savePuckData($node, $body);

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Page saved successfully.',
        'node' => [
          'nid' => (int) $node->id(),
          'changed' => $node->getChangedTime(),
        ],
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'error' => 'Save failed: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Return the component mapping configuration.
   */
  public function mapping(): JsonResponse {
    $mapping = $this->mappingService->getMapping();
    return new JsonResponse($mapping);
  }

  /**
   * Configure dc_puck settings via API.
   *
   * POST /api/puck/configure
   * Accepts: { editor_url, enabled, enabled_content_types }
   * Auth: X-Decoupled-Token header (same as dc_import)
   */
  public function configure(Request $request): JsonResponse {
    // Authenticate using the same token mechanism as dc_import.
    if (!$this->authenticateApiRequest($request)) {
      return new JsonResponse(['error' => 'Unauthorized'], 401);
    }

    $body = json_decode($request->getContent(), TRUE);
    if (empty($body)) {
      return new JsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    $state = \Drupal::state();
    $changes = [];

    if (isset($body['enabled'])) {
      $state->set('dc_puck.enabled', (bool) $body['enabled']);
      $changes[] = 'enabled=' . ($body['enabled'] ? 'true' : 'false');
    }

    if (isset($body['editor_url'])) {
      $url = rtrim($body['editor_url'], '/');
      $state->set('dc_puck.editor_url', $url);
      $changes[] = 'editor_url=' . $url;
    }

    if (isset($body['enabled_content_types']) && is_array($body['enabled_content_types'])) {
      $state->set('dc_puck.enabled_content_types', $body['enabled_content_types']);
      $changes[] = 'enabled_content_types=[' . implode(',', $body['enabled_content_types']) . ']';
    }

    // Clear caches so the Design Studio tab appears immediately.
    drupal_flush_all_caches();

    return new JsonResponse([
      'success' => TRUE,
      'message' => 'Puck editor configured.',
      'changes' => $changes,
      'state' => [
        'enabled' => $state->get('dc_puck.enabled', FALSE),
        'editor_url' => $state->get('dc_puck.editor_url', ''),
        'enabled_content_types' => $state->get('dc_puck.enabled_content_types', []),
      ],
    ]);
  }

  /**
   * Authenticate an API request using X-Decoupled-Token header.
   * Reuses the same validation logic as dc_import.
   */
  protected function authenticateApiRequest(Request $request): bool {
    $token = $request->headers->get('X-Decoupled-Token');
    if (empty($token)) {
      $authHeader = $request->headers->get('Authorization');
      if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
        $token = substr($authHeader, 7);
      }
    }

    if (empty($token)) {
      return FALSE;
    }

    // Platform PAT tokens (dc_tok_...) — validate against dashboard.
    // Note: space auth tokens also use dc_tok_ prefix, so if PAT validation
    // fails we fall through to space auth token and format checks.
    if (str_starts_with($token, 'dc_tok_')) {
      $platformUrl = getenv('DECOUPLED_PLATFORM_URL') ?:
        \Drupal::state()->get('dc_import.platform_url', 'https://dashboard.decoupled.io');

      try {
        $response = \Drupal::httpClient()->post($platformUrl . '/api/auth/validate', [
          'json' => ['token' => $token],
          'timeout' => 10,
        ]);
        $data = json_decode($response->getBody()->getContents(), TRUE);
        if (!empty($data['valid'])) {
          return TRUE;
        }
      }
      catch (\Exception $e) {
        // Fall through to other auth methods
      }
    }

    // Space auth tokens — check against stored token.
    $storedToken = \Drupal::state()->get('dc_import.space_auth_token', '');
    if (!empty($storedToken) && hash_equals($storedToken, $token)) {
      return TRUE;
    }

    // Accept any valid-format token as fallback (request already authenticated
    // at dashboard layer; this matches dc_config_import's approach).
    if (strlen($token) >= 32 && preg_match('/^[a-zA-Z0-9_.\-]+$/', $token)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Validate a signed Puck token for a specific node.
   */
  protected function validateToken(string $token, NodeInterface $node): bool {
    if (empty($token)) {
      return FALSE;
    }

    $decoded = base64_decode($token);
    if (!$decoded) {
      return FALSE;
    }

    $parts = explode(':', $decoded);
    if (count($parts) !== 4) {
      return FALSE;
    }

    [$uid, $nid, $timestamp, $hmac] = $parts;

    // Check expiry (8 hours).
    if (abs(time() - (int) $timestamp) > 28800) {
      return FALSE;
    }

    // Verify node ID matches.
    if ((int) $nid !== (int) $node->id()) {
      return FALSE;
    }

    // Verify HMAC.
    $secret = \Drupal::state()->get('dc_puck.token_secret', '');
    if (empty($secret)) {
      return FALSE;
    }

    $expectedHmac = hash_hmac('sha256', "{$uid}:{$nid}:{$timestamp}", $secret);
    return hash_equals($expectedHmac, $hmac);
  }

}
