<?php

namespace Drupal\dc_puck\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Validates Puck editor signed tokens and returns user-scoped OAuth tokens.
 */
class TokenController extends ControllerBase {

  /**
   * Token validity window: 8 hours.
   */
  const TOKEN_LIFETIME = 28800;

  /**
   * Generates a fresh signed token for the current user + node.
   * Called via AJAX so the page itself stays fully cacheable.
   */
  public function generate(NodeInterface $node): JsonResponse {
    $uid = \Drupal::currentUser()->id();
    if (!$uid) {
      return new JsonResponse(['error' => 'Not logged in'], 401);
    }

    if (!$node->access('update')) {
      return new JsonResponse(['error' => 'No edit access'], 403);
    }

    $token = dc_puck_generate_token((int) $uid, (int) $node->id());
    $puck_url = \Drupal::state()->get('dc_puck.editor_url', '');

    return new JsonResponse([
      'token' => $token,
      'url' => $puck_url . '/editor/' . $node->id() . '?token=' . urlencode($token),
    ]);
  }

  /**
   * Validates a signed token and returns an OAuth access token for the user.
   */
  public function validate(Request $request): JsonResponse {
    $content = json_decode($request->getContent(), TRUE);
    $token = $content['token'] ?? '';

    if (empty($token)) {
      return new JsonResponse(['error' => 'Missing token'], 400);
    }

    // Decode the token.
    $decoded = base64_decode($token);
    if (!$decoded) {
      return new JsonResponse(['error' => 'Invalid token format'], 401);
    }

    $parts = explode(':', $decoded);
    if (count($parts) !== 4) {
      return new JsonResponse(['error' => 'Invalid token structure'], 401);
    }

    [$uid, $nid, $timestamp, $hmac] = $parts;

    // Verify timestamp (not expired).
    if (abs(time() - (int) $timestamp) > self::TOKEN_LIFETIME) {
      return new JsonResponse(['error' => 'Token expired'], 401);
    }

    // Verify HMAC.
    $secret = \Drupal::state()->get('dc_puck.token_secret', '');
    if (empty($secret)) {
      return new JsonResponse(['error' => 'Token secret not configured'], 500);
    }

    $expectedHmac = hash_hmac('sha256', "{$uid}:{$nid}:{$timestamp}", $secret);
    if (!hash_equals($expectedHmac, $hmac)) {
      return new JsonResponse(['error' => 'Invalid token signature'], 401);
    }

    // Verify the user exists and has edit access to the node.
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$user || $user->isBlocked()) {
      return new JsonResponse(['error' => 'Invalid user'], 401);
    }

    $node = $this->entityTypeManager()->getStorage('node')->load($nid);
    if (!$node) {
      return new JsonResponse(['error' => 'Node not found'], 404);
    }

    if (!$node->access('update', $user)) {
      return new JsonResponse(['error' => 'User does not have edit access'], 403);
    }

    return new JsonResponse([
      'success' => TRUE,
      'user' => [
        'uid' => (int) $user->id(),
        'name' => $user->getAccountName(),
      ],
      'node' => [
        'nid' => (int) $node->id(),
        'title' => $node->getTitle(),
        'changed' => $node->getChangedTime(),
      ],
    ]);
  }

}
