<?php

namespace Drupal\dc_login\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST API controller for generating one-time login URLs.
 */
class LoginApiController extends ControllerBase {

  /**
   * Generate a one-time login URL.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing the login URL.
   */
  public function generate(Request $request) {
    try {
      // Check authentication
      if (!$this->authenticateRequest($request)) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Authentication required. Please provide a valid Decoupled Drupal personal access token.',
          'format' => 'X-Decoupled-Token: dc_tok_... OR Authorization: Bearer <oauth_token>',
        ], 401);
      }

      // Check method
      if ($request->getMethod() !== 'POST') {
        return new JsonResponse([
          'success' => false,
          'error' => 'Only POST method is allowed',
        ], 405);
      }

      // Get request data
      $content = $request->getContent();
      $data = !empty($content) ? json_decode($content, TRUE) : [];

      // Get user ID (default to user 1 if not specified)
      $uid = $data['uid'] ?? 1;

      // Load the user
      $user = User::load($uid);
      if (!$user) {
        return new JsonResponse([
          'success' => false,
          'error' => "User with ID {$uid} not found",
        ], 404);
      }

      // Generate the one-time login URL
      $timestamp = \Drupal::time()->getRequestTime();
      $loginUrl = user_pass_reset_url($user);

      // Get the base URL for this site
      global $base_url;
      $siteUrl = $base_url;

      return new JsonResponse([
        'success' => true,
        'login_url' => $loginUrl,
        'user' => [
          'uid' => $user->id(),
          'name' => $user->getAccountName(),
          'email' => $user->getEmail(),
        ],
        'expires' => 'This link expires in 24 hours and can only be used once',
        'site_url' => $siteUrl,
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('dc_login')->error('Login URL generation error: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => false,
        'error' => 'Login URL generation failed: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Get service status.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function status() {
    return new JsonResponse([
      'success' => true,
      'service' => 'Decoupled Login API',
      'version' => '1.0.0',
      'endpoints' => [
        'POST /api/generate-login' => 'Generate one-time login URL',
        'GET /api/generate-login/status' => 'Get service status',
      ],
      'authentication' => [
        'required' => 'X-Decoupled-Token: dc_tok_... (Decoupled Drupal personal access token) OR OAuth Bearer token',
        'oauth' => 'OAuth Bearer tokens supported via Authorization: Bearer <token> header',
      ],
    ]);
  }

  /**
   * Authenticate the request using personal access tokens or OAuth.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   TRUE if authenticated, FALSE otherwise.
   */
  private function authenticateRequest(Request $request) {
    // Development mode bypass for .ddev.site and localhost domains
    $skipAuth = getenv('DECOUPLED_SKIP_AUTH') === 'true' ||
                \Drupal::state()->get('dc_login.skip_auth', FALSE) ||
                ($request->headers->get('X-Decoupled-Dev-Mode') === 'true' && str_contains($_SERVER['HTTP_HOST'] ?? '', '.ddev.site')) ||
                str_contains($_SERVER['HTTP_HOST'] ?? '', '.ddev.site') ||
                str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost');

    if ($skipAuth) {
      \Drupal::logger('dc_login')->info('Authentication skipped - development mode');
      return TRUE;
    }

    // Try OAuth Bearer token authentication
    $authHeader = $request->headers->get('Authorization');
    if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
      $oauthToken = substr($authHeader, 7); // Remove 'Bearer ' prefix
      if ($this->validateOAuthToken($oauthToken)) {
        return TRUE;
      }
    }

    // Decoupled Personal Access Token authentication
    $token = $request->headers->get('X-Decoupled-Token');
    if ($token && str_starts_with($token, 'dc_tok_')) {
      if ($this->validatePlatformToken($token)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Validate platform personal access token.
   *
   * @param string $token
   *   The platform token to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  private function validatePlatformToken($token) {
    // Get platform URL from environment or settings
    $platformUrl = getenv('DECOUPLED_PLATFORM_URL') ?:
                   \Drupal::state()->get('dc_login.platform_url', 'https://dashboard.decoupled.io');

    // For local development, support localhost
    if (str_contains($_SERVER['HTTP_HOST'] ?? '', '.ddev.site') || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost')) {
      $platformUrl = 'http://host.docker.internal:3333';
    }

    \Drupal::logger('dc_login')->info('Attempting token validation against: @url', ['@url' => $platformUrl . '/api/auth/validate']);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $platformUrl . '/api/auth/validate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Bearer ' . $token,
      'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local development

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
      \Drupal::logger('dc_login')->warning('Platform token validation failed: @error', [
        '@error' => $error
      ]);
      return FALSE;
    }

    if ($httpCode === 200) {
      $data = json_decode($response, TRUE);
      if (isset($data['valid']) && $data['valid'] === TRUE) {
        \Drupal::logger('dc_login')->info('Platform token validated successfully');
        return TRUE;
      }
    }

    \Drupal::logger('dc_login')->warning('Platform token validation failed: HTTP @code', [
      '@code' => $httpCode
    ]);
    return FALSE;
  }

  /**
   * Validate OAuth Bearer token using Drupal's OAuth system.
   *
   * @param string $token
   *   The OAuth token to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  private function validateOAuthToken($token) {
    // Check if OAuth module is enabled
    if (!\Drupal::moduleHandler()->moduleExists('simple_oauth')) {
      return FALSE;
    }

    try {
      // Get the OAuth2 server
      $authServer = \Drupal::service('simple_oauth.server.authorization_server');

      // Validate the token
      $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
      $request->headers->set('Authorization', 'Bearer ' . $token);

      $psrRequest = \Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory::createRequest($request);
      $psrRequest = $psrRequest->withHeader('Authorization', 'Bearer ' . $token);

      // Try to validate the token by attempting to get it
      $tokenRepository = \Drupal::service('simple_oauth.repositories.access_token');
      $tokenEntity = $tokenRepository->getAccessTokenEntity($token);

      if ($tokenEntity && !$tokenEntity->isRevoked()) {
        \Drupal::logger('dc_login')->info('OAuth token validated successfully');
        return TRUE;
      }
    } catch (\Exception $e) {
      \Drupal::logger('dc_login')->warning('OAuth token validation failed: @error', [
        '@error' => $e->getMessage()
      ]);
    }

    return FALSE;
  }

}
