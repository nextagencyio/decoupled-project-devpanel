<?php

namespace Drupal\dc_revalidate\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for handling Next.js revalidation requests.
 */
class RevalidationService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a RevalidationService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('dc_revalidate');
  }

  /**
   * Triggers revalidation for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to revalidate.
   *
   * @return bool
   *   TRUE if revalidation was triggered successfully, FALSE otherwise.
   */
  public function revalidateNode(NodeInterface $node) {
    $this->logger->info('Revalidation triggered for node @nid (@title)', [
      '@nid' => $node->id(),
      '@title' => $node->getTitle(),
    ]);

    $config = $this->configFactory->get('dc_revalidate.settings');
    $enabled = $config->get('enabled');

    $this->logger->info('Revalidation enabled status: @enabled', ['@enabled' => $enabled ? 'true' : 'false']);

    if (!$enabled) {
      $this->logger->info('Revalidation is disabled, skipping request for node @nid', ['@nid' => $node->id()]);
      return FALSE;
    }

    $frontend_url = $config->get('frontend_url');
    $secret = $config->get('revalidate_secret');

    $this->logger->info('Revalidation config: URL=@url, Secret length=@secret_len', [
      '@url' => $frontend_url,
      '@secret_len' => strlen($secret),
    ]);

    if (empty($frontend_url) || empty($secret)) {
      $this->logger->error('Revalidation failed: Missing frontend URL or secret. URL=@url, Secret=@secret', [
        '@url' => $frontend_url ?: 'empty',
        '@secret' => $secret ? 'present' : 'empty',
      ]);
      return FALSE;
    }

    $slug = $this->getNodeSlug($node);
    if (empty($slug)) {
      $this->logger->warning('Revalidation skipped: No slug found for node @nid.', ['@nid' => $node->id()]);
      return FALSE;
    }

    $this->logger->info('Generated slug for node @nid: @slug', [
      '@nid' => $node->id(),
      '@slug' => $slug,
    ]);

    return $this->sendRevalidationRequest($frontend_url, $secret, $slug);
  }

  /**
   * Gets the slug for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string|null
   *   The node slug or NULL if not found.
   */
  protected function getNodeSlug(NodeInterface $node) {
    // Check for path alias first
    $alias = \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $node->id());
    if ($alias !== '/node/' . $node->id()) {
      return ltrim($alias, '/');
    }

    // Fallback to node ID
    return 'node/' . $node->id();
  }

  /**
   * Sends a revalidation request to the Next.js frontend.
   *
   * @param string $frontend_url
   *   The frontend URL.
   * @param string $secret
   *   The revalidation secret.
   * @param string $slug
   *   The slug to revalidate.
   *
   * @return bool
   *   TRUE if the request was successful, FALSE otherwise.
   */
  protected function sendRevalidationRequest($frontend_url, $secret, $slug) {
    $url = rtrim($frontend_url, '/') . '/api/revalidate';

    // Log the request details
    $this->logger->info('Attempting revalidation request to @url with slug: @slug', [
      '@url' => $url,
      '@slug' => $slug,
    ]);

    try {
      $request_data = [
        'form_params' => [
          'secret' => $secret,
          'slug' => $slug,
        ],
        'timeout' => 10,
      ];

      // Log the request data (without the secret for security)
      $this->logger->info('Revalidation request data: @data', [
        '@data' => json_encode([
          'url' => $url,
          'slug' => $slug,
          'timeout' => 10,
          'secret_length' => strlen($secret),
        ]),
      ]);

      $response = $this->httpClient->post($url, $request_data);

      $status_code = $response->getStatusCode();
      $response_body = $response->getBody()->getContents();

      $this->logger->info('Revalidation response: Status @status, Body: @body', [
        '@status' => $status_code,
        '@body' => $response_body,
      ]);

      if ($status_code === 200) {
        $this->logger->info('Revalidation successful for slug: @slug', ['@slug' => $slug]);
        return TRUE;
      }
      else {
        $this->logger->error('Revalidation failed for slug: @slug. Status: @status', [
          '@slug' => $slug,
          '@status' => $status_code,
        ]);
        return FALSE;
      }
    }
    catch (RequestException $e) {
      $this->logger->error('Revalidation request failed for slug: @slug. URL: @url. Error: @error', [
        '@slug' => $slug,
        '@url' => $url,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}