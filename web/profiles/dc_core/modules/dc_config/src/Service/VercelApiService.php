<?php

namespace Drupal\dc_config\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Service for interacting with Vercel API.
 *
 * Uses centralized OAuth flow through dashboard.decoupled.io:
 * 1. Drupal redirects to dashboard's /api/vercel/authorize
 * 2. Dashboard handles OAuth with Vercel
 * 3. Dashboard redirects back to Drupal with exchange_code
 * 4. Drupal exchanges code for token via /api/vercel/exchange
 */
class VercelApiService {

  /**
   * Vercel API base URL.
   */
  const API_BASE = 'https://api.vercel.com';

  /**
   * Production OAuth server URL (Decoupled.io Dashboard).
   */
  const OAUTH_SERVER_URL_PRODUCTION = 'https://dashboard.decoupled.io';

  /**
   * Local development OAuth server URL.
   */
  const OAUTH_SERVER_URL_LOCAL = 'http://localhost:3333';

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
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a VercelApiService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Get the Vercel config.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The Vercel config.
   */
  protected function getConfig() {
    return $this->configFactory->get('dc_config.vercel');
  }

  /**
   * Get editable Vercel config.
   *
   * @return \Drupal\Core\Config\Config
   *   The editable Vercel config.
   */
  protected function getEditableConfig() {
    return $this->configFactory->getEditable('dc_config.vercel');
  }

  /**
   * Get the OAuth server URL for browser redirects.
   *
   * This URL is used for browser redirects where the user's browser
   * makes the request (e.g., OAuth authorize flow).
   *
   * @return string
   *   The OAuth server URL for browser access.
   */
  protected function getOAuthServerUrl(): string {
    // Allow environment variable override.
    $envUrl = getenv('DC_OAUTH_SERVER_URL');
    if (!empty($envUrl)) {
      return rtrim($envUrl, '/');
    }

    // Check if running locally.
    $host = \Drupal::request()->getHost();
    $isLocal = (
      str_contains($host, 'localhost') ||
      str_contains($host, '.ddev.site') ||
      str_contains($host, '127.0.0.1') ||
      str_contains($host, '.local')
    );

    return $isLocal ? self::OAUTH_SERVER_URL_LOCAL : self::OAUTH_SERVER_URL_PRODUCTION;
  }

  /**
   * Get the OAuth server URL for server-to-server requests.
   *
   * When running in Docker, use DECOUPLED_PLATFORM_URL (host.docker.internal)
   * for PHP HTTP requests, since localhost from inside Docker doesn't reach host.
   *
   * @return string
   *   The OAuth server URL for server-to-server requests.
   */
  protected function getOAuthServerUrlInternal(): string {
    // Check for Docker environment (DECOUPLED_PLATFORM_URL is set in docker-compose).
    $platformUrl = getenv('DECOUPLED_PLATFORM_URL');
    if (!empty($platformUrl)) {
      return rtrim($platformUrl, '/');
    }

    // Fall back to the browser URL.
    return $this->getOAuthServerUrl();
  }

  /**
   * Check if Vercel OAuth is available on the central server.
   *
   * @return bool
   *   TRUE if the central server has Vercel OAuth configured.
   */
  public function isConfigured(): bool {
    try {
      $response = $this->httpClient->request('GET', $this->getOAuthServerUrlInternal() . '/api/vercel/status', [
        'timeout' => 5,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return !empty($data['configured']);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Could not check Vercel OAuth status: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Check if connected to Vercel.
   *
   * @return bool
   *   TRUE if connected (has access token).
   */
  public function isConnected(): bool {
    return !empty($this->getConfig()->get('access_token'));
  }

  /**
   * Get the central OAuth authorization URL.
   *
   * @param string $callback_url
   *   The callback URL for this Drupal site.
   *
   * @return string
   *   The OAuth server authorization URL.
   */
  public function getAuthorizationUrl(string $callback_url): string {
    return $this->getOAuthServerUrl() . '/api/vercel/authorize?' . http_build_query([
      'callback_url' => $callback_url,
    ]);
  }

  /**
   * Exchange the one-time code for access token via central server.
   *
   * @param string $exchange_code
   *   The one-time exchange code from the callback.
   * @param string|null $team_id
   *   Optional team ID from the callback.
   *
   * @return bool
   *   TRUE if token was successfully retrieved and saved.
   */
  public function exchangeCodeForToken(string $exchange_code, ?string $team_id = NULL): bool {
    try {
      $response = $this->httpClient->request('POST', $this->getOAuthServerUrlInternal() . '/api/vercel/exchange', [
        'json' => [
          'exchange_code' => $exchange_code,
        ],
        'timeout' => 10,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (!empty($data['access_token'])) {
        // Save the access token.
        $config = $this->getEditableConfig();
        $config->set('access_token', $data['access_token']);
        if ($team_id) {
          $config->set('team_id', $team_id);
        }
        $config->save();

        $this->logger->info('Successfully connected to Vercel');
        return TRUE;
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Vercel OAuth token exchange failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return FALSE;
  }

  /**
   * Get list of Vercel projects.
   *
   * @return array
   *   Array of projects.
   */
  public function getProjects(): array {
    $config = $this->getConfig();
    $accessToken = $config->get('access_token');

    if (empty($accessToken)) {
      return [];
    }

    try {
      $url = self::API_BASE . '/v9/projects';
      $teamId = $config->get('team_id');
      if (!empty($teamId)) {
        $url .= '?teamId=' . $teamId;
      }

      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return $data['projects'] ?? [];
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to fetch Vercel projects: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Get the production URL for a Vercel project.
   *
   * Queries the Vercel API for the actual production domain instead of
   * assuming {project-name}.vercel.app (which is often wrong).
   *
   * @param string $project_id
   *   The Vercel project ID.
   *
   * @return string|null
   *   The production URL (with https://), or NULL if not found.
   */
  public function getProjectProductionUrl(string $project_id): ?string {
    $config = $this->getConfig();
    $accessToken = $config->get('access_token');

    if (empty($accessToken)) {
      return NULL;
    }

    try {
      $url = self::API_BASE . '/v9/projects/' . $project_id;
      $teamId = $config->get('team_id');
      if (!empty($teamId)) {
        $url .= '?teamId=' . $teamId;
      }

      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
        ],
        'timeout' => 10,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      // Try production alias first (the actual deployed URL).
      if (!empty($data['targets']['production']['alias'][0])) {
        return 'https://' . $data['targets']['production']['alias'][0];
      }

      // Fall back to project-level alias.
      if (!empty($data['alias'][0]['domain'])) {
        return 'https://' . $data['alias'][0]['domain'];
      }

      // Last resort: construct from project name.
      $name = $data['name'] ?? $project_id;
      return 'https://' . $name . '.vercel.app';
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to fetch Vercel project URL: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Set environment variables on a Vercel project.
   *
   * @param string $project_id
   *   The Vercel project ID.
   * @param array $env_vars
   *   Array of environment variables [key => value].
   * @param array $targets
   *   Target environments (production, preview, development).
   *
   * @return bool
   *   TRUE on success.
   */
  public function setEnvironmentVariables(string $project_id, array $env_vars, array $targets = ['production', 'preview', 'development']): bool {
    $config = $this->getConfig();
    $accessToken = $config->get('access_token');

    if (empty($accessToken)) {
      return FALSE;
    }

    try {
      $url = self::API_BASE . '/v10/projects/' . $project_id . '/env?upsert=true';
      $teamId = $config->get('team_id');
      if (!empty($teamId)) {
        $url .= '&teamId=' . $teamId;
      }

      // Format env vars for Vercel API.
      $body = [];
      foreach ($env_vars as $key => $value) {
        $body[] = [
          'key' => $key,
          'value' => $value,
          'target' => $targets,
          'type' => 'encrypted',
        ];
      }

      $response = $this->httpClient->request('POST', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
          'Content-Type' => 'application/json',
        ],
        'json' => $body,
      ]);

      $statusCode = $response->getStatusCode();
      if ($statusCode >= 200 && $statusCode < 300) {
        // Update last synced timestamp.
        $this->getEditableConfig()
          ->set('last_synced', time())
          ->save();
        return TRUE;
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to set Vercel environment variables: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return FALSE;
  }

  /**
   * Connect to a Vercel project.
   *
   * @param string $project_id
   *   The project ID.
   * @param string $project_name
   *   The project name.
   */
  public function connectProject(string $project_id, string $project_name): void {
    $this->getEditableConfig()
      ->set('connected_project_id', $project_id)
      ->set('connected_project_name', $project_name)
      ->save();
  }

  /**
   * Disconnect from Vercel.
   */
  public function disconnect(): void {
    $this->getEditableConfig()
      ->set('access_token', '')
      ->set('team_id', '')
      ->set('connected_project_id', '')
      ->set('connected_project_name', '')
      ->set('last_synced', 0)
      ->save();
  }

  /**
   * Trigger a new deployment for the connected Vercel project.
   *
   * Uses the Vercel API to create a new deployment, which rebuilds
   * the project with the latest environment variables.
   *
   * @param string $project_id
   *   The Vercel project ID.
   * @param string $project_name
   *   The Vercel project name (used for API call).
   *
   * @return array
   *   Array with 'success' boolean and 'deployment' data or 'error' message.
   */
  public function triggerDeployment(string $project_id, string $project_name): array {
    $config = $this->getConfig();
    $accessToken = $config->get('access_token');

    if (empty($accessToken)) {
      return ['success' => FALSE, 'error' => 'Not connected to Vercel'];
    }

    try {
      // Get the latest READY deployment to redeploy it.
      $latestDeployment = $this->getLatestDeployment($project_id, 'READY');
      if (empty($latestDeployment['id'])) {
        return ['success' => FALSE, 'error' => 'No existing deployment found to redeploy'];
      }

      $url = self::API_BASE . '/v13/deployments';
      $teamId = $config->get('team_id');

      $query = ['forceNew' => '1'];
      if (!empty($teamId)) {
        $query['teamId'] = $teamId;
      }
      $url .= '?' . http_build_query($query);

      $body = [
        'deploymentId' => $latestDeployment['id'],
        'name' => $project_name,
        'target' => 'production',
        'withLatestCommit' => TRUE,
      ];

      $response = $this->httpClient->request('POST', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
          'Content-Type' => 'application/json',
        ],
        'json' => $body,
      ]);

      $statusCode = $response->getStatusCode();
      $data = json_decode($response->getBody()->getContents(), TRUE);

      if ($statusCode >= 200 && $statusCode < 300) {
        $this->logger->info('Triggered Vercel redeployment for project @project', [
          '@project' => $project_name,
        ]);
        return [
          'success' => TRUE,
          'deployment' => [
            'id' => $data['id'] ?? NULL,
            'url' => $data['url'] ?? NULL,
            'readyState' => $data['readyState'] ?? 'QUEUED',
          ],
        ];
      }

      return [
        'success' => FALSE,
        'error' => $data['error']['message'] ?? 'Unknown error',
      ];
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to trigger Vercel deployment: @message', [
        '@message' => $e->getMessage(),
      ]);
      return ['success' => FALSE, 'error' => $e->getMessage()];
    }
  }

  /**
   * Get the latest deployment for a project.
   *
   * @param string $project_id
   *   The Vercel project ID.
   * @param string|null $state
   *   Filter by deployment state (e.g. 'READY'). NULL for any state.
   *
   * @return array|null
   *   Deployment data or NULL if not found.
   */
  public function getLatestDeployment(string $project_id, ?string $state = NULL): ?array {
    $config = $this->getConfig();
    $accessToken = $config->get('access_token');

    if (empty($accessToken)) {
      return NULL;
    }

    try {
      $url = self::API_BASE . '/v6/deployments?projectId=' . $project_id . '&limit=1';
      if ($state) {
        $url .= '&state=' . $state;
      }
      $teamId = $config->get('team_id');
      if (!empty($teamId)) {
        $url .= '&teamId=' . $teamId;
      }

      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      $deployments = $data['deployments'] ?? [];

      if (!empty($deployments)) {
        $deployment = $deployments[0];
        return [
          'id' => $deployment['uid'] ?? NULL,
          'url' => $deployment['url'] ?? NULL,
          'state' => $deployment['readyState'] ?? $deployment['state'] ?? 'UNKNOWN',
          'created' => $deployment['created'] ?? NULL,
        ];
      }

      return NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to get latest Vercel deployment: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Get connection status.
   *
   * @return array
   *   Connection status array.
   */
  public function getConnectionStatus(): array {
    $config = $this->getConfig();
    return [
      'configured' => $this->isConfigured(),
      'connected' => $this->isConnected(),
      'project_id' => $config->get('connected_project_id'),
      'project_name' => $config->get('connected_project_name'),
      'last_synced' => $config->get('last_synced'),
    ];
  }

}
