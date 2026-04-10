<?php

namespace Drupal\dc_config\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\dc_config\Service\VercelApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\Random;

/**
 * Controller for Decoupled Drupal configuration page.
 */
class DcConfigController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Vercel API service.
   *
   * @var \Drupal\dc_config\Service\VercelApiService
   */
  protected $vercelApi;

  /**
   * Constructs a DcConfigController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\dc_config\Service\VercelApiService $vercel_api
   *   The Vercel API service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    VercelApiService $vercel_api
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->vercelApi = $vercel_api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('dc_config.vercel_api')
    );
  }

  /**
   * Homepage that shows configuration information.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array or redirect response.
   */
  public function homePage() {
    // Check if user has permission to administer site configuration.
    if (!$this->currentUser()->hasPermission('administer site configuration')) {
      return new RedirectResponse('/user');
    }

    // Show the configuration page for authorized users.
    return $this->configPage();
  }

  /**
   * Helper function to create a code block with copy and download buttons.
   *
   * @param string $code
   *   The code content.
   * @param string $language
   *   The language type (optional).
   * @param string $title
   *   The title for the code block (optional).
   * @param bool $show_download
   *   Whether to show the download button (optional).
   *
   * @return string
   *   HTML markup for code block with copy and download buttons.
   */
  private function createCodeBlock($code, $language = '', $title = '', $show_download = FALSE) {
    $id = 'code-' . uniqid();
    $title_html = $title ? '<div class="dc-config-code-title">' . $title . '</div>' : '';
    $has_title_class = $title ? ' has-title' : '';

    $download_button = '';
    if ($show_download) {
      $download_button = '<button class="dc-config-download-button" data-target="' . $id . '" data-filename=".env.local" title="Download as .env file">
          💾 Download
        </button>';
    }

    return '<div class="dc-config-code-block' . $has_title_class . '">
      ' . $title_html . '
      <div class="dc-config-code-content">
        <pre id="' . $id . '">' . htmlspecialchars($code) . '</pre>
        <div class="dc-config-buttons">
          <button class="dc-config-copy-button" data-target="' . $id . '" title="Copy to clipboard">
            📋 Copy
          </button>
          ' . $download_button . '
        </div>
      </div>
    </div>';
  }

  /**
   * Get the default starter definition.
   *
   * @return array
   *   The starter data.
   */
  private function getDefaultStarter() {
    return [
      'id' => 'components',
      'name' => 'Decoupled Components',
      'description' => '10+ professional components, visual page builder, and type-safe GraphQL client',
      'icon' => 'layout',
      'contentUrl' => 'https://raw.githubusercontent.com/nextagencyio/decoupled-components/main/data/components-content.json',
      'vercelUrl' => 'https://vercel.com/new/clone?repository-url=https://github.com/nextagencyio/decoupled-components',
    ];
  }

  /**
   * Get SVG icon markup for a starter.
   *
   * @param string $icon
   *   The icon name.
   *
   * @return string
   *   SVG markup.
   */
  private function getIconSvg($icon) {
    $icons = [
      'newspaper' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/></svg>',
      'shopping-cart' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>',
      'message-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>',
      'search' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
      'graduation-cap' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>',
      'globe' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>',
      'credit-card' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>',
      'layout' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><line x1="3" x2="21" y1="9" y2="9"/><line x1="9" x2="9" y1="21" y2="9"/></svg>',
      'plus' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>',
    ];

    return $icons[$icon] ?? $icons['plus'];
  }

  /**
   * Build the starter selection grid HTML.
   *
   * @return string
   *   HTML markup for the starter grid.
   */
  private function buildStarterGrid() {
    // Check if a starter has already been imported.
    $importedStarter = \Drupal::state()->get('dc_config.imported_starter');

    // If already imported, show success message.
    if ($importedStarter) {
      $starterName = $importedStarter['name'] ?? $importedStarter['id'] ?? 'Unknown';
      return '
      <div class="dc-config-section dc-config-starter-section">
        <h2>Starter Template Installed</h2>
        <div class="dc-config-starter-installed">
          <div class="dc-config-starter-installed-icon">&#10003;</div>
          <div class="dc-config-starter-installed-text">
            <strong>' . htmlspecialchars($starterName) . '</strong> has been imported.
            <p>Content types, sample data, and components are ready to use.</p>
          </div>
        </div>
      </div>

      <div class="dc-config-divider">
        <span>next, deploy your frontend</span>
      </div>
      ';
    }

    $starter = $this->getDefaultStarter();

    return '
    <div class="dc-config-section dc-config-starter-section">
      <div class="dc-config-starter-single"
           data-starter-id="' . htmlspecialchars($starter['id']) . '"
           data-content-url="' . htmlspecialchars($starter['contentUrl']) . '"
           data-vercel-url="' . htmlspecialchars($starter['vercelUrl']) . '">
        <div class="dc-config-starter-single-icon dc-config-starter-icon--starter">
          ' . $this->getIconSvg($starter['icon']) . '
        </div>
        <div class="dc-config-starter-single-info">
          <div class="dc-config-starter-single-name">' . htmlspecialchars($starter['name']) . '</div>
          <div class="dc-config-starter-single-desc">' . htmlspecialchars($starter['description']) . '</div>
        </div>
        <button type="button" class="dc-config-import-btn" id="import-starter-btn">
          Import Content
        </button>
      </div>
      <div id="import-status" class="dc-config-import-status"></div>
    </div>

    <div class="dc-config-divider">
      <span>next, deploy your frontend</span>
    </div>
    ';
  }

  /**
   * Displays the Next.js configuration page.
   *
   * @return array
   *   A render array.
   */
  public function configPage() {
    $build = [];

    // Attach the custom library for styling and JavaScript.
    $build['#attached']['library'][] = 'dc_config/dc_config';

    // Get Vercel connection status.
    $vercel_status = $this->vercelApi->getConnectionStatus();
    $vercel_connected = $vercel_status['connected'];
    $vercel_project_name = $vercel_status['project_name'];
    $vercel_last_synced = $vercel_status['last_synced'];

    // Pass status to JavaScript.
    $build['#attached']['drupalSettings']['dcConfig'] = [
      'spaceToken' => \Drupal::state()->get('dc_import.space_auth_token', ''),
      'vercelConnected' => $vercel_connected,
      'vercelProjectName' => $vercel_project_name,
      'vercelProjectId' => $vercel_status['project_id'],
      'vercelLastSynced' => $vercel_last_synced,
      'csrfToken' => \Drupal::csrfToken()->get('dc_config_vercel_sync'),
      'disconnectToken' => \Drupal::csrfToken()->get('dc_config_vercel_disconnect'),
    ];

    // Get or create Next.js consumer information.
    $client_id = '';
    $client_secret = '';

    try {
      $consumer_storage = $this->entityTypeManager->getStorage('consumer');
      // Clear cache to ensure we get fresh data
      $consumer_storage->resetCache();
      $consumers = $consumer_storage->loadByProperties(['label' => 'Next.js Frontend']);

      if (empty($consumers)) {
        // Create the OAuth consumer automatically.
        $consumer = $this->createOAuthConsumer($consumer_storage);
        if (!$consumer) {
          // If consumer creation fails, generate temporary values.
          $client_id = 'TEMP_' . bin2hex(random_bytes(16));
          $client_secret = bin2hex(random_bytes(16));

          $build['warning'] = [
            '#type' => 'markup',
            '#markup' => '<div class="messages messages--warning">
              <h3>⚠️ Temporary Configuration</h3>
              <p>OAuth consumer could not be created automatically. Using temporary values below.</p>
              <p>For production use, please create the OAuth consumer manually or run: <code>ddev drush php:script scripts/consumers-next.php</code></p>
            </div>',
          ];
        }
        else {
          $client_id = $consumer->getClientId();
          $client_secret = $consumer->get('secret')->value;
        }
      }
      else {
        $consumer = reset($consumers);
        $client_id = $consumer->getClientId();

        // Get secret from consumer (only works if not hashed)
        $stored_secret = $consumer->get('secret')->value;

        if (empty($stored_secret) || preg_match('/^\$2[ayb]\$/', $stored_secret)) {
          // Secret is hashed or missing - user needs to generate a new one
          // (Vercel sync will auto-generate fresh secrets)
          $client_secret = '[Click "Generate New Client Secret" below]';
        }
        else {
          $client_secret = $stored_secret;
        }
      }
    }
    catch (\Exception $e) {
      // If all else fails, provide temporary values.
      $client_id = 'TEMP_' . bin2hex(random_bytes(16));
      $client_secret = bin2hex(random_bytes(16));

      $build['error'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">
          <h3>⚠️ Temporary Configuration</h3>
          <p>Could not access OAuth consumer storage. Using temporary values below.</p>
          <p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>
          <p>For production use, please ensure all required modules are installed and configured properly.</p>
        </div>',
      ];
    }

    // Get Next.js settings.
    $next_config = $this->configFactory->get('next.settings');
    $next_base_url = $next_config->get('base_url') ?: 'http://host.docker.internal:3333';

    // Get revalidate secret from dc_revalidate.settings
    $revalidate_config = $this->configFactory->get('dc_revalidate.settings');
    $revalidate_secret = $revalidate_config->get('revalidate_secret');

    // Generate revalidation secret if it doesn't exist
    if (empty($revalidate_secret) || $revalidate_secret === 'not-set') {
      $random = new Random();
      $revalidate_secret = bin2hex(random_bytes(16));

      // Save the new revalidation secret to dc_revalidate.settings
      $revalidate_config_editable = $this->configFactory->getEditable('dc_revalidate.settings');
      $revalidate_config_editable->set('revalidate_secret', $revalidate_secret);
      $revalidate_config_editable->save();
    }

    // Get current site URL.
    global $base_url;
    $site_url = $base_url ?: \Drupal::request()->getSchemeAndHttpHost();

    // Determine if this is a local development environment.
    $host = parse_url($site_url, PHP_URL_HOST);
    $is_local = (strpos($host, 'localhost') !== FALSE || strpos($host, '127.0.0.1') !== FALSE || strpos($host, '.local') !== FALSE);

    // Use HTTPS for production URLs.
    if (!$is_local && parse_url($site_url, PHP_URL_SCHEME) === 'http') {
      $site_url = str_replace('http://', 'https://', $site_url);
    }

    // Prepare code blocks.
    $env_content = "# Required - Drupal backend URL
NEXT_PUBLIC_DRUPAL_BASE_URL=" . $site_url . "
NEXT_IMAGE_DOMAIN=" . parse_url($site_url, PHP_URL_HOST) . "

# Authentication - OAuth credentials
DRUPAL_CLIENT_ID=" . $client_id . "
DRUPAL_CLIENT_SECRET=" . $client_secret . "

# Required for On-demand Revalidation
DRUPAL_REVALIDATE_SECRET=" . $revalidate_secret;

    // Only include NODE_TLS_REJECT_UNAUTHORIZED for local development.
    if ($is_local) {
      $env_content .= "

# Allow self-signed certificates for development
NODE_TLS_REJECT_UNAUTHORIZED=0";
    }

    $npm_run_dev = "npm run dev";

    // Build starter grid.
    $starter_grid = $this->buildStarterGrid();

    // Check if a frontend was auto-provisioned via turnkey flow.
    // First check local config, then fall back to checking the dashboard API.
    $frontend_config = $this->configFactory->get('dc_config.frontend');
    if ($frontend_config->isNew()) {
      $this->configFactory->getEditable('dc_config.frontend')->set('data', NULL)->save();
      $frontend_config = $this->configFactory->get('dc_config.frontend');
    }
    $frontend_status = $frontend_config->get('data');

    // If no local frontend config, check the dashboard API to see if one was provisioned.
    // This handles the case where Netlify was created but dc-config hasn't been told yet.
    if (empty($frontend_status)) {
      $spaceToken = \Drupal::state()->get('dc_import.space_auth_token', '');
      if ($spaceToken) {
        try {
          $client = \Drupal::httpClient();
          $response = $client->get('https://dashboard.decoupled.io/api/spaces/frontend-status-by-token', [
            'headers' => ['X-Space-Token' => $spaceToken],
            'timeout' => 5,
          ]);
          $data = json_decode($response->getBody()->getContents(), TRUE);
          if (!empty($data['hasFrontend']) && !empty($data['url'])) {
            // A frontend exists on the dashboard — set local config to deploying
            $frontend_status = [
              'provider' => 'netlify',
              'url' => $data['url'],
              'claim_url' => $data['claimUrl'] ?? '',
              'template' => $data['template'] ?? 'decoupled-components',
              'status' => $data['status'] === 'active' ? 'active' : 'deploying',
              'claimed' => $data['claimed'] ?? FALSE,
              'preview_configured' => FALSE,
              'puck_configured' => FALSE,
              'content_imported' => FALSE,
              'updated_at' => date('c'),
            ];
            $this->configFactory->getEditable('dc_config.frontend')
              ->set('data', $frontend_status)->save();
          }
        }
        catch (\Exception $e) {
          // Dashboard not reachable — that's fine, show backend-only state
        }
      }
    }
    $netlify_section = '';

    if ($frontend_status && !empty($frontend_status['url'])) {
      $fe_url = htmlspecialchars($frontend_status['url']);
      $claim_url = htmlspecialchars($frontend_status['claim_url'] ?? '');
      $is_claimed = !empty($frontend_status['claimed']);
      $is_deploying = ($frontend_status['status'] ?? '') === 'deploying';

      $check_svg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
      $spinner_svg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="dc-config-spinner"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>';

      // Local verification — check Drupal's own state, not dashboard flags
      $preview_config_check = $this->configFactory->get('decoupled_preview_iframe.settings');
      $has_preview = !empty($preview_config_check->get('preview_url'));

      $puck_config_check = $this->configFactory->get('dc_puck.settings');
      $has_puck = !empty($puck_config_check->get('enabled')) && !empty($puck_config_check->get('editor_url'));

      $has_content = FALSE;
      try {
        $node_storage = $this->entityTypeManager->getStorage('node');
        $landing_pages = $node_storage->getQuery()
          ->condition('type', 'landing_page')
          ->accessCheck(FALSE)
          ->count()
          ->execute();
        $has_content = $landing_pages > 0;
      }
      catch (\Exception $e) {
        // Content type may not exist yet
      }

      $claim_html = '';
      if ($is_claimed) {
        $claim_html = '<div class="dc-config-netlify-claimed">
          <span class="dc-config-status-dot dc-config-status-dot--connected"></span>
          <strong>Claimed</strong> — this site is in your Netlify account.
        </div>';
      }
      elseif ($claim_url) {
        $claim_html = '<div class="dc-config-netlify-claim">
          <p>Take full ownership of your frontend. Custom domains, deploy settings, and CI/CD — all under your Netlify account.</p>
          <a href="' . $claim_url . '" target="_blank" rel="noopener noreferrer" class="dc-config-claim-button">Claim Your Site on Netlify</a>
          <p class="dc-config-claim-note">Environment variables transfer automatically. We retain deploy access so content updates keep flowing.</p>
        </div>';
      }

      if ($is_deploying) {
        // Deploying state — auto-refresh every 10 seconds
        $netlify_section = '
          <div class="dc-config-netlify-card dc-config-netlify-deploying">
            <div class="dc-config-netlify-header">
              <div class="dc-config-netlify-title">
                ' . $spinner_svg . '
                <strong>Frontend Deploying</strong>
              </div>
              <span class="dc-config-netlify-link" style="color:#6b7280;">' . preg_replace('#^https?://#', '', $fe_url) . '</span>
            </div>

            <div class="dc-config-netlify-checks">
              <div class="dc-config-check" style="color:#6b7280;">' . $spinner_svg . ' Deploying Next.js to Netlify...</div>
              <div class="dc-config-check" style="color:#6b7280;">' . $spinner_svg . ' Importing content...</div>
              <div class="dc-config-check" style="color:#6b7280;">' . $spinner_svg . ' Configuring preview and editor...</div>
            </div>

            <p style="font-size:13px;color:#9ca3af;margin-top:12px;">This page will update automatically when everything is connected.</p>
          </div>';
      }
      else {
        // Connected state
        $netlify_section = '
          <div class="dc-config-netlify-card">
            <div class="dc-config-netlify-header">
              <div class="dc-config-netlify-title">
                <span class="dc-config-status-dot dc-config-status-dot--connected"></span>
                <strong>Frontend Connected</strong>
              </div>
              <a href="' . $fe_url . '" target="_blank" rel="noopener noreferrer" class="dc-config-netlify-link">' . preg_replace('#^https?://#', '', $fe_url) . ' ↗</a>
            </div>

            <div class="dc-config-netlify-checks">
              ' . ($has_preview ? '<div class="dc-config-check">' . $check_svg . ' Live preview configured</div>' : '') . '
              ' . ($has_puck ? '<div class="dc-config-check">' . $check_svg . ' Visual page builder ready</div>' : '') . '
              ' . ($has_content ? '<div class="dc-config-check">' . $check_svg . ' Sample content imported</div>' : '') . '
            </div>

            ' . $claim_html . '

            <div class="dc-config-netlify-next-steps">
              <strong>Get started:</strong>
              <ul>
                <li><a href="/admin/content">Browse your content</a> — sample pages with 10+ professional components</li>
                <li>Edit any landing page and click the <strong>Preview</strong> tab to see it live on your frontend</li>
                <li>Open the <strong>Design Studio</strong> to build pages visually — drag, drop, publish</li>
              </ul>
            </div>
          </div>';
      }
    }

    // Build the Vercel section (used in both states, but collapsed in State A)
    $vercel_section = $vercel_connected ?
      '<div class="dc-config-vercel-connected-card">
        <div class="dc-config-vercel-connected-header">
          <div class="dc-config-vercel-connected-status">
            <span class="dc-config-status-dot dc-config-status-dot--connected"></span>
            <span>Connected to Vercel</span>
          </div>
        </div>
        <div class="dc-config-vercel-connected-body">
          <div class="dc-config-vercel-project-selector">
            <label for="vercel-project">Select a project to sync environment variables:</label>
            <select id="vercel-project" class="dc-config-select">
              <option value="">Loading projects...</option>
            </select>
          </div>
          <div class="dc-config-vercel-actions">
            <div class="dc-config-vercel-buttons">
              <button type="button" id="vercel-sync-btn" class="dc-config-sync-button" disabled>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                Sync Environment Variables
              </button>
              <button type="button" id="vercel-rebuild-btn" class="dc-config-rebuild-button">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                Trigger Rebuild
              </button>
            </div>
            <div class="dc-config-vercel-status-row">
              <div id="vercel-sync-status" class="dc-config-sync-status"></div>
              <div id="vercel-deploy-status" class="dc-config-deploy-status"></div>
            </div>
          </div>
          ' . ($vercel_last_synced ? '<p class="dc-config-vercel-last-sync">Last synced: ' . date('M j, Y g:i A', $vercel_last_synced) . '</p>' : '') . '
        </div>
        <div class="dc-config-vercel-connected-footer">
          <form method="post" action="/dc-config/vercel/disconnect" style="display: inline;">
            <input type="hidden" name="form_token" value="' . \Drupal::csrfToken()->get('dc_config_vercel_disconnect') . '">
            <button type="submit" class="dc-config-disconnect-link">Disconnect from Vercel</button>
          </form>
        </div>
      </div>'
      :
      '<div class="dc-config-vercel-steps">
        <div class="dc-config-vercel-step">
          <div class="dc-config-step-marker">
            <span class="dc-config-step-number-badge">1</span>
            <div class="dc-config-step-line"></div>
          </div>
          <div class="dc-config-vercel-step-card dc-config-vercel-step-card--deploy">
            <div class="dc-config-vercel-step-content">
              <h3>Deploy to Vercel</h3>
              <p>One-click deploy from our Next.js starter with type-safe Drupal client.</p>
            </div>
            <a href="https://vercel.com/new/clone?repository-url=https://github.com/nextagencyio/decoupled-components&project-name=my-app"
               target="_blank"
               class="dc-config-vercel-deploy-btn">
              <svg width="18" height="18" viewBox="0 0 76 65" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="m37.5274 0 36.9815 64H.5459Z" fill="currentColor"/>
              </svg>
              Deploy with Vercel
              <span class="dc-config-arrow">→</span>
            </a>
          </div>
        </div>
        <div class="dc-config-vercel-step">
          <div class="dc-config-step-marker">
            <span class="dc-config-step-number-badge">2</span>
          </div>
          <div class="dc-config-vercel-step-card dc-config-vercel-step-card--connect">
            <div class="dc-config-vercel-step-content">
              <h3>Connect to sync credentials</h3>
              <p>Auto-configure your Vercel project with OAuth and revalidation secrets.</p>
            </div>
            <a href="/dc-config/vercel/connect" class="dc-config-vercel-connect-btn">
              <svg width="18" height="18" viewBox="0 0 76 65" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="m37.5274 0 36.9815 64H.5459Z" fill="currentColor"/>
              </svg>
              Connect to Vercel
            </a>
          </div>
        </div>
      </div>';

    // Build the manual config section
    $manual_section = '<div style="padding-top:12px;">
      <p>For local development or manual setup, create a <code>.env.local</code> file in your Next.js project root:</p>
      ' . $this->createCodeBlock($env_content, 'env', '.env.local', TRUE) . '
      <div class="dc-config-generate-secret">
        <form method="post" action="/dc-config/generate-secret" style="display: inline;">
          <input type="hidden" name="form_token" value="' . \Drupal::csrfToken()->get('dc_config_generate_secret') . '">
          <button type="submit" class="dc-config-generate-button">
            🔑&nbsp;&nbsp;Generate New Client Secret
          </button>
        </form>
        <p class="dc-config-generate-help">Generate a new OAuth client secret for enhanced security.</p>
      </div>
    </div>';

    // Build the settings grid (shared between both states)
    $settings_grid = '<div class="dc-config-section" style="margin-top:32px;">
      <h2 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:16px;">Settings</h2>
      <div class="dc-settings-grid">
        <a href="/admin/config/decoupled_preview_iframe/settings" class="dc-settings-card">
          <div class="dc-settings-card-icon" style="background:#eff6ff;color:#3b82f6;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
          </div>
          <div>
            <div class="dc-settings-card-title">Preview Iframe</div>
            <div class="dc-settings-card-desc">Frontend URL for live content preview</div>
          </div>
        </a>
        <a href="/admin/config/dc-puck" class="dc-settings-card">
          <div class="dc-settings-card-icon" style="background:#f5f3ff;color:#8b5cf6;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.855z"/></svg>
          </div>
          <div>
            <div class="dc-settings-card-title">Design Studio</div>
            <div class="dc-settings-card-desc">Puck visual editor URL and content types</div>
          </div>
        </a>
        <a href="/admin/config/decoupled/revalidation" class="dc-settings-card">
          <div class="dc-settings-card-icon" style="background:#ecfdf5;color:#10b981;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
          </div>
          <div>
            <div class="dc-settings-card-title">Revalidation</div>
            <div class="dc-settings-card-desc">On-demand cache revalidation for Next.js</div>
          </div>
        </a>
        <a href="/admin/content/import" class="dc-settings-card">
          <div class="dc-settings-card-icon" style="background:#fff7ed;color:#f97316;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
          </div>
          <div>
            <div class="dc-settings-card-title">Import Content</div>
            <div class="dc-settings-card-desc">Import content types and data from JSON</div>
          </div>
        </a>
      </div>
    </div>';

    if ($frontend_status && !empty($frontend_status['url'])) {
      // ===== STATE A: Turnkey (Netlify frontend exists) =====
      $build['instructions'] = [
        '#type' => 'markup',
        '#markup' => '<div class="dc-config-main-layout">
          <div class="dc-config-content-area">
            <div class="dc-config-header">
              <h1>' . ($is_deploying ? 'Setting up your site...' : 'Your site is live!') . '</h1>
              <p>' . ($is_deploying ? 'Your backend is ready. Frontend is deploying and will connect automatically.' : 'Frontend and backend are connected. Start creating content or customize your site.') . '</p>
            </div>

            <div class="dc-config-main-content">
              ' . $netlify_section . '

              ' . $settings_grid . '

              <details style="margin-top:32px;" class="dc-config-section dc-config-advanced-section">
                <summary style="cursor:pointer;font-size:16px;font-weight:600;color:#64748b;padding:12px 0;list-style:none;display:flex;align-items:center;gap:8px;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transition:transform 0.2s;"><polyline points="6 9 12 15 18 9"/></svg>
                  Other Options
                </summary>
                <div style="padding-top:16px;">
                  <h3 style="font-size:16px;font-weight:600;color:#1e293b;margin-bottom:12px;">Deploy to Vercel</h3>
                  <p style="font-size:14px;color:#64748b;margin-bottom:16px;">Prefer Vercel? Deploy our Next.js starter with type-safe Drupal client.</p>
                  <div class="dc-config-vercel-hero">
                    ' . $vercel_section . '
                  </div>

                  <h3 style="font-size:16px;font-weight:600;color:#1e293b;margin-top:24px;margin-bottom:12px;">Manual Configuration</h3>
                  ' . $manual_section . '
                </div>
              </details>
            </div>
          </div>

          <div class="dc-config-sidebar">
            <div class="dc-config-sidebar-card">
              <div class="dc-config-sidebar-content">
                <h3>
                  <span class="dc-config-sidebar-icon">📋</span>
                  Quick Start
                </h3>
                <div class="dc-config-checklist">
                  <div class="dc-config-checklist-item">
                    <div class="dc-config-step-number ' . ($is_deploying ? '' : 'dc-config-step-done') . '">' . ($is_deploying ? '1' : '✓') . '</div>
                    <div class="dc-config-step-content">
                      <div class="dc-config-step-title">Content Imported</div>
                      <div class="dc-config-step-description">' . ($is_deploying ? 'Importing components...' : '10+ professional components ready') . '</div>
                    </div>
                  </div>
                  <div class="dc-config-checklist-item">
                    <div class="dc-config-step-number ' . ($is_deploying ? '' : 'dc-config-step-done') . '">' . ($is_deploying ? '2' : '✓') . '</div>
                    <div class="dc-config-step-content">
                      <div class="dc-config-step-title">Frontend Deployed</div>
                      <div class="dc-config-step-description">' . ($is_deploying ? 'Deploying to Netlify...' : 'Next.js on Netlify, zero DevOps') . '</div>
                    </div>
                  </div>
                  <div class="dc-config-checklist-item">
                    <div class="dc-config-step-number ' . ($is_deploying ? '' : 'dc-config-step-done') . '">' . ($is_deploying ? '3' : '✓') . '</div>
                    <div class="dc-config-step-content">
                      <div class="dc-config-step-title">Preview + Design Studio</div>
                      <div class="dc-config-step-description">' . ($is_deploying ? 'Configuring...' : 'Live preview and visual builder') . '</div>
                    </div>
                  </div>
                  <div class="dc-config-checklist-item">
                    <div class="dc-config-step-number">4</div>
                    <div class="dc-config-step-content">
                      <div class="dc-config-step-title">' . ($is_deploying ? 'Start Creating' : '<a href="/admin/content" style="color:inherit;text-decoration:none;">Start Creating</a>') . '</div>
                      <div class="dc-config-step-description">Build pages with drag and drop</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>',
      ];
    }
    else {
      // ===== STATE B: Backend Only (no Netlify frontend) =====
      $build['instructions'] = [
        '#type' => 'markup',
        '#markup' => '<div class="dc-config-main-layout">
          <div class="dc-config-content-area">
            <div class="dc-config-header">
              <h1>Your CMS is ready!</h1>
              <p>Import content, deploy your frontend, and start building.</p>
            </div>

            <div class="dc-config-main-content">
              ' . $starter_grid . '

              <div class="dc-config-vercel-hero">
                ' . $vercel_section . '
              </div>

              ' . $settings_grid . '

              <details style="margin-top:32px;" class="dc-config-section dc-config-advanced-section">
                <summary style="cursor:pointer;font-size:16px;font-weight:600;color:#64748b;padding:12px 0;list-style:none;display:flex;align-items:center;gap:8px;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transition:transform 0.2s;"><polyline points="6 9 12 15 18 9"/></svg>
                  Manual Configuration
                </summary>
                ' . $manual_section . '
              </details>
            </div>
          </div>

          <div class="dc-config-sidebar">
            <div class="dc-config-sidebar-card">
              <div class="dc-config-sidebar-content">
                <h3>
                  <span class="dc-config-sidebar-icon">📋</span>
                  Quick Start
                </h3>
                <div class="dc-config-checklist">
                  <div class="dc-config-checklist-item">
                    <div class="dc-config-step-number">1</div>
                    <div class="dc-config-step-content">
                      <div class="dc-config-step-title">Import Content</div>
                      <div class="dc-config-step-description">10+ components and sample pages</div>
                    </div>
                  </div>
                  <div class="dc-config-checklist-item">
                    <div class="dc-config-step-number">2</div>
                    <div class="dc-config-step-content">
                      <div class="dc-config-step-title">Deploy Frontend</div>
                      <div class="dc-config-step-description">One-click deploy to Vercel</div>
                    </div>
                  </div>
                  <div class="dc-config-checklist-item">
                    <div class="dc-config-step-number">3</div>
                    <div class="dc-config-step-content">
                      <div class="dc-config-step-title">Connect</div>
                      <div class="dc-config-step-description">Sync OAuth credentials automatically</div>
                    </div>
                  </div>
                  <div class="dc-config-checklist-item">
                    <div class="dc-config-step-number">4</div>
                    <div class="dc-config-step-content">
                      <div class="dc-config-step-title">Start Creating</div>
                      <div class="dc-config-step-description">Build pages with the visual editor</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>',
      ];
    }

    // Cache invalidates automatically when dc_config.frontend config changes.
    $build['#cache'] = [
      'tags' => $frontend_config->getCacheTags(),
    ];

    // Allow HTML attributes to preserve styling.
    $build['instructions']['#allowed_tags'] = [
      'div',
      'h1',
      'h2',
      'h3',
      'h4',
      'p',
      'pre',
      'code',
      'button',
      'form',
      'input',
      'select',
      'option',
      'label',
      'ul',
      'li',
      'a',
      'span',
      'strong',
      'br',
      'script',
      'details',
      'summary',
      // SVG elements for icons.
      'svg',
      'path',
      'circle',
      'line',
      'rect',
      'polyline',
      'polygon',
      'g',
    ];

    // Disable render caching so Vercel connection status is always fresh.
    $build['#cache'] = ['max-age' => 0];

    return $build;
  }

  /**
   * Creates an OAuth consumer for Next.js frontend.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $consumer_storage
   *   The consumer storage service.
   *
   * @return \Drupal\consumer\Entity\Consumer|null
   *   The created consumer entity or null if creation failed.
   */
  private function createOAuthConsumer($consumer_storage) {
    try {
      $random = new Random();

      $client_id = Crypt::randomBytesBase64();
      $client_secret = $random->word(8);

      // Create previewer consumer data.
      $consumer_data = [
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'label' => 'Next.js Frontend',
        'user_id' => 2,
        'third_party' => TRUE,
        'is_default' => FALSE,
      ];

      // Check if consumer__roles table exists before adding roles.
      $database = \Drupal::database();
      if ($database->schema()->tableExists('consumer__roles')) {
        $consumer_data['roles'] = ['previewer'];
      }

      $consumer = $consumer_storage->create($consumer_data);
      $consumer->save();

      return $consumer;
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_config')->error('Failed to create OAuth consumer: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Generates a new revalidation secret and OAuth client secret.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response back to the configuration page.
   */
  public function generateSecret(Request $request) {
    // Check if user has permission to administer site configuration.
    if (!$this->currentUser()->hasPermission('administer site configuration')) {
      return new RedirectResponse('/user');
    }

    // Verify CSRF token
    $token = $request->request->get('form_token');
    if (!\Drupal::csrfToken()->validate($token, 'dc_config_generate_secret')) {
      \Drupal::messenger()->addError('Invalid form token. Please try again.');
      return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
    }


    // Also regenerate OAuth client secret if needed
    try {
      $consumer_storage = $this->entityTypeManager->getStorage('consumer');
      $consumers = $consumer_storage->loadByProperties(['label' => 'Next.js Frontend']);

      if (!empty($consumers)) {
        $consumer = reset($consumers);

        // Always generate a new plain text secret when the button is clicked
        $random = new Random();
        $client_secret = $random->word(8);

        // Set the secret field directly
        $consumer->set('secret', $client_secret);
        $consumer->save();

        // Clear entity cache to ensure fresh data on page reload
        $consumer_storage->resetCache([$consumer->id()]);
        \Drupal::entityTypeManager()->clearCachedDefinitions();

        \Drupal::logger('dc_config')->info('Generated new OAuth client secret');
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_config')->error('Failed to update OAuth consumer secret: @message', ['@message' => $e->getMessage()]);
    }

    // Add success message
    \Drupal::messenger()->addStatus('New client secret generated successfully!');

    // Redirect back to the configuration page
    return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
  }

  /**
   * AJAX endpoint to generate new OAuth client secret.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the new client secret.
   */
  public function generateSecretAjax(Request $request) {
    // Check if user has permission to administer site configuration.
    if (!$this->currentUser()->hasPermission('administer site configuration')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    // Verify CSRF token
    $token = $request->request->get('form_token');
    if (!\Drupal::csrfToken()->validate($token, 'dc_config_generate_secret')) {
      return new JsonResponse(['error' => 'Invalid form token'], 403);
    }

    $response_data = [
      'success' => false,
      'client_secret' => '',
      'client_id' => '',
      'revalidate_secret' => '',
      'message' => ''
    ];

    try {
      // Get existing revalidation secret to preserve it
      $revalidate_config = $this->configFactory->get('dc_revalidate.settings');
      $existing_revalidate_secret = $revalidate_config->get('revalidate_secret');

      // If no existing revalidate secret, generate one
      if (empty($existing_revalidate_secret) || $existing_revalidate_secret === 'not-set') {
        $random = new Random();
        $existing_revalidate_secret = bin2hex(random_bytes(16));

        // Save the new revalidate secret to config
        $revalidate_config_editable = $this->configFactory->getEditable('dc_revalidate.settings');
        $revalidate_config_editable->set('revalidate_secret', $existing_revalidate_secret);
        $revalidate_config_editable->save();
      }

      $response_data['revalidate_secret'] = $existing_revalidate_secret;

      // Regenerate OAuth client secret
      $consumer_storage = $this->entityTypeManager->getStorage('consumer');
      $consumer_storage->resetCache();
      $consumers = $consumer_storage->loadByProperties(['label' => 'Next.js Frontend']);

      if (!empty($consumers)) {
        $consumer = reset($consumers);
        $response_data['client_id'] = $consumer->getClientId();

        // Generate new client secret
        $random = new Random();
        $client_secret = $random->word(8);

        $consumer->set('secret', $client_secret);
        $consumer->save();

        // Clear cache
        $consumer_storage->resetCache([$consumer->id()]);

        $response_data['client_secret'] = $client_secret;
        $response_data['success'] = true;
        $response_data['message'] = 'New client secret generated successfully!';
      } else {
        $response_data['error'] = 'OAuth consumer not found';
      }

    } catch (\Exception $e) {
      \Drupal::logger('dc_config')->error('AJAX secret generation failed: @message', ['@message' => $e->getMessage()]);
      $response_data['error'] = 'Failed to generate client secret: ' . $e->getMessage();
    }

    return new JsonResponse($response_data);
  }

  // ============================================================
  // Vercel OAuth Integration Methods
  // ============================================================

  /**
   * Initiates the Vercel OAuth flow.
   *
   * Redirects to the central MCP server which handles OAuth with Vercel.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the OAuth authorization URL.
   */
  public function vercelConnect() {
    // Check if Vercel OAuth is available on the central server.
    if (!$this->vercelApi->isConfigured()) {
      $this->messenger()->addError($this->t('Vercel integration is not available. Please try again later.'));
      return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
    }

    // Build the callback URL for this Drupal site.
    $callbackUrl = Url::fromRoute('dc_config.vercel_callback', [], ['absolute' => TRUE])->toString();

    // Get the authorization URL from the central server.
    $authUrl = $this->vercelApi->getAuthorizationUrl($callbackUrl);

    \Drupal::logger('dc_config')->info('Initiating Vercel OAuth flow, redirecting to: @url', ['@url' => $authUrl]);

    // Use TrustedRedirectResponse for external URL redirect.
    return new TrustedRedirectResponse($authUrl);
  }

  /**
   * Handles the OAuth callback from the central MCP server.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the configuration page.
   */
  public function vercelCallback(Request $request) {
    $exchangeCode = $request->query->get('exchange_code');
    $teamId = $request->query->get('team_id');
    $error = $request->query->get('error');
    $errorDescription = $request->query->get('error_description');

    // Handle errors.
    if ($error) {
      $message = $errorDescription ?: $error;
      $this->messenger()->addError($this->t('Failed to connect to Vercel: @message', ['@message' => $message]));
      \Drupal::logger('dc_config')->error('Vercel OAuth error: @error - @description', [
        '@error' => $error,
        '@description' => $errorDescription,
      ]);
      return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
    }

    // Validate exchange code.
    if (empty($exchangeCode)) {
      $this->messenger()->addError($this->t('Invalid OAuth callback: missing exchange code.'));
      return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
    }

    // Exchange the code for an access token.
    $success = $this->vercelApi->exchangeCodeForToken($exchangeCode, $teamId);

    if ($success) {
      $this->messenger()->addStatus($this->t('Successfully connected to Vercel! You can now sync environment variables.'));
      \Drupal::logger('dc_config')->info('Vercel OAuth completed successfully');
    }
    else {
      $this->messenger()->addError($this->t('Failed to complete Vercel connection. Please try again.'));
    }

    return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
  }

  /**
   * Disconnects from Vercel.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\JsonResponse
   *   A redirect or JSON response.
   */
  public function vercelDisconnect(Request $request) {
    // Verify CSRF token.
    $token = $request->request->get('form_token');
    if (!\Drupal::csrfToken()->validate($token, 'dc_config_vercel_disconnect')) {
      if ($request->isXmlHttpRequest()) {
        return new JsonResponse(['error' => 'Invalid form token'], 403);
      }
      $this->messenger()->addError($this->t('Invalid form token. Please try again.'));
      return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
    }

    $this->vercelApi->disconnect();
    $this->messenger()->addStatus($this->t('Disconnected from Vercel.'));
    \Drupal::logger('dc_config')->info('Disconnected from Vercel');

    if ($request->isXmlHttpRequest()) {
      return new JsonResponse(['success' => TRUE, 'message' => 'Disconnected from Vercel']);
    }

    return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
  }

  /**
   * Returns a list of Vercel projects as JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the list of projects.
   */
  public function vercelProjects() {
    if (!$this->vercelApi->isConnected()) {
      return new JsonResponse(['error' => 'Not connected to Vercel'], 401);
    }

    $projects = $this->vercelApi->getProjects();

    return new JsonResponse([
      'success' => TRUE,
      'projects' => array_map(function ($project) {
        return [
          'id' => $project['id'],
          'name' => $project['name'],
          'framework' => $project['framework'] ?? NULL,
          'link' => $project['link']['productionBranch'] ?? NULL,
        ];
      }, $projects),
    ]);
  }

  /**
   * Syncs environment variables to a Vercel project.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response indicating success or failure.
   */
  public function vercelSync(Request $request) {
    // Verify CSRF token.
    $token = $request->request->get('form_token');
    if (!\Drupal::csrfToken()->validate($token, 'dc_config_vercel_sync')) {
      return new JsonResponse(['error' => 'Invalid form token'], 403);
    }

    if (!$this->vercelApi->isConnected()) {
      return new JsonResponse(['error' => 'Not connected to Vercel'], 401);
    }

    $projectId = $request->request->get('project_id');
    $projectName = $request->request->get('project_name');

    if (empty($projectId)) {
      return new JsonResponse(['error' => 'Missing project_id'], 400);
    }

    // Generate fresh secrets for this sync (more secure than storing plain text).
    $freshSecrets = $this->generateFreshSecrets();
    if (!$freshSecrets['success']) {
      return new JsonResponse(['error' => $freshSecrets['error']], 500);
    }

    // Get the environment variables with fresh secrets.
    $envVars = $this->getEnvironmentVariablesWithSecrets(
      $freshSecrets['client_id'],
      $freshSecrets['client_secret'],
      $freshSecrets['revalidate_secret']
    );

    // Sync to Vercel.
    $success = $this->vercelApi->setEnvironmentVariables($projectId, $envVars);

    if ($success) {
      // Save the connected project info.
      if ($projectName) {
        $this->vercelApi->connectProject($projectId, $projectName);
      }

      \Drupal::logger('dc_config')->info('Synced environment variables to Vercel project: @project (with fresh secrets)', [
        '@project' => $projectName ?: $projectId,
      ]);

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Environment variables synced! Click "Trigger Rebuild" to apply changes.',
        'variables_synced' => array_keys($envVars),
      ]);
    }

    return new JsonResponse([
      'error' => 'Failed to sync environment variables to Vercel',
    ], 500);
  }

  /**
   * Generate fresh OAuth and revalidation secrets.
   *
   * This regenerates secrets atomically so both Drupal and Vercel stay in sync.
   *
   * @return array
   *   Array with success status and secrets or error message.
   */
  protected function generateFreshSecrets(): array {
    $random = new Random();

    try {
      // Generate new client secret and update consumer.
      $consumerStorage = $this->entityTypeManager->getStorage('consumer');
      $consumers = $consumerStorage->loadByProperties(['label' => 'Next.js Frontend']);

      if (empty($consumers)) {
        return ['success' => FALSE, 'error' => 'OAuth consumer not found'];
      }

      $consumer = reset($consumers);
      $clientId = $consumer->getClientId();
      $clientSecret = $random->word(8);

      // Update the consumer with fresh secret.
      $consumer->set('secret', $clientSecret);
      $consumer->save();
      $consumerStorage->resetCache([$consumer->id()]);

      // Generate or get revalidate secret.
      $revalidateConfig = $this->configFactory->get('dc_revalidate.settings');
      $revalidateSecret = $revalidateConfig->get('revalidate_secret');

      if (empty($revalidateSecret) || $revalidateSecret === 'not-set') {
        $revalidateSecret = bin2hex(random_bytes(16));
        $this->configFactory->getEditable('dc_revalidate.settings')
          ->set('revalidate_secret', $revalidateSecret)
          ->save();
      }

      return [
        'success' => TRUE,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'revalidate_secret' => $revalidateSecret,
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_config')->error('Failed to generate fresh secrets: @message', ['@message' => $e->getMessage()]);
      return ['success' => FALSE, 'error' => 'Failed to generate secrets: ' . $e->getMessage()];
    }
  }

  /**
   * Get environment variables with provided secrets.
   *
   * @param string $clientId
   *   The OAuth client ID.
   * @param string $clientSecret
   *   The OAuth client secret (plain text).
   * @param string $revalidateSecret
   *   The revalidation secret.
   *
   * @return array
   *   Array of environment variable key-value pairs.
   */
  protected function getEnvironmentVariablesWithSecrets(string $clientId, string $clientSecret, string $revalidateSecret): array {
    global $base_url;
    $siteUrl = $base_url ?: \Drupal::request()->getSchemeAndHttpHost();

    // Use HTTPS for production URLs.
    $host = parse_url($siteUrl, PHP_URL_HOST);
    $isLocal = (strpos($host, 'localhost') !== FALSE || strpos($host, '127.0.0.1') !== FALSE || strpos($host, '.local') !== FALSE);
    if (!$isLocal && parse_url($siteUrl, PHP_URL_SCHEME) === 'http') {
      $siteUrl = str_replace('http://', 'https://', $siteUrl);
    }

    return [
      'NEXT_PUBLIC_DRUPAL_BASE_URL' => $siteUrl,
      'NEXT_IMAGE_DOMAIN' => parse_url($siteUrl, PHP_URL_HOST),
      'DRUPAL_CLIENT_ID' => $clientId,
      'DRUPAL_CLIENT_SECRET' => $clientSecret,
      'DRUPAL_REVALIDATE_SECRET' => $revalidateSecret,
      'NEXT_PUBLIC_DEMO_MODE' => 'false',
    ];
  }

  /**
   * Get the Vercel connection status.
   *
   * @return array
   *   The connection status.
   */
  public function getVercelStatus(): array {
    return $this->vercelApi->getConnectionStatus();
  }

  /**
   * Triggers a Vercel rebuild for the connected project.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response indicating success or failure.
   */
  public function vercelRebuild(Request $request) {
    // Verify CSRF token.
    $token = $request->request->get('form_token');
    if (!\Drupal::csrfToken()->validate($token, 'dc_config_vercel_sync')) {
      return new JsonResponse(['error' => 'Invalid form token'], 403);
    }

    if (!$this->vercelApi->isConnected()) {
      return new JsonResponse(['error' => 'Not connected to Vercel'], 401);
    }

    $status = $this->vercelApi->getConnectionStatus();
    $projectId = $status['project_id'];
    $projectName = $status['project_name'];

    if (empty($projectId)) {
      return new JsonResponse(['error' => 'No Vercel project connected. Please sync environment variables first.'], 400);
    }

    $result = $this->vercelApi->triggerDeployment($projectId, $projectName ?: $projectId);

    if ($result['success']) {
      \Drupal::logger('dc_config')->info('Triggered manual Vercel rebuild for project: @project', [
        '@project' => $projectName ?: $projectId,
      ]);

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Rebuild triggered! Your site will be updated shortly.',
        'deployment' => $result['deployment'],
        'productionUrl' => $this->vercelApi->getProjectProductionUrl($projectId) ?: 'https://' . ($projectName ?: $projectId) . '.vercel.app',
      ]);
    }

    return new JsonResponse([
      'error' => $result['error'] ?? 'Failed to trigger rebuild',
    ], 500);
  }

  /**
   * Returns the latest deployment status for the connected project.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with deployment status.
   */
  public function vercelDeploymentStatus() {
    if (!$this->vercelApi->isConnected()) {
      return new JsonResponse(['error' => 'Not connected to Vercel'], 401);
    }

    $status = $this->vercelApi->getConnectionStatus();
    $projectId = $status['project_id'];

    if (empty($projectId)) {
      return new JsonResponse(['error' => 'No project connected'], 400);
    }

    $deployment = $this->vercelApi->getLatestDeployment($projectId);
    $projectName = $status['project_name'];

    if ($deployment) {
      return new JsonResponse([
        'success' => TRUE,
        'deployment' => $deployment,
        'productionUrl' => $this->vercelApi->getProjectProductionUrl($projectId) ?: 'https://' . ($projectName ?: $projectId) . '.vercel.app',
      ]);
    }

    return new JsonResponse([
      'success' => TRUE,
      'deployment' => NULL,
      'message' => 'No deployments found',
    ]);
  }

  // ============================================================
  // Starter Content Import Methods
  // ============================================================

  /**
   * Import starter content from a remote JSON URL.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response indicating success or failure.
   */
  public function importStarter(Request $request) {
    // Check if a starter has already been imported.
    $importedStarter = \Drupal::state()->get('dc_config.imported_starter');
    if ($importedStarter) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'A starter has already been imported. Only one starter can be installed per site.',
      ], 400);
    }

    $contentUrl = $request->request->get('content_url');
    $starterId = $request->request->get('starter_id');
    $starterName = $request->request->get('starter_name');

    if (empty($contentUrl)) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'No content URL provided',
      ], 400);
    }

    // Validate URL is from allowed domains (GitHub raw content).
    $allowedDomains = [
      'raw.githubusercontent.com',
      'gist.githubusercontent.com',
    ];
    $urlHost = parse_url($contentUrl, PHP_URL_HOST);
    if (!in_array($urlHost, $allowedDomains)) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Content URL must be from GitHub raw content',
      ], 400);
    }

    try {
      // Fetch content JSON from the remote URL.
      $client = \Drupal::httpClient();
      $response = $client->get($contentUrl, [
        'timeout' => 30,
        'headers' => [
          'Accept' => 'application/json',
        ],
      ]);

      $contentJson = json_decode($response->getBody()->getContents(), TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Invalid JSON in content file: ' . json_last_error_msg(),
        ], 400);
      }

      // Use dc_import.importer service to import content directly.
      if (\Drupal::hasService('dc_import.importer')) {
        $importer = \Drupal::service('dc_import.importer');
        $result = $importer->import($contentJson);

        // Calculate stats from result summary.
        $stats = [
          'content_types' => 0,
          'nodes' => 0,
          'paragraphs' => 0,
          'media' => 0,
        ];

        if (!empty($result['summary'])) {
          foreach ($result['summary'] as $message) {
            if (strpos($message, 'Created node type:') !== FALSE) {
              $stats['content_types']++;
            }
            elseif (strpos($message, 'Created node:') !== FALSE) {
              $stats['nodes']++;
            }
            elseif (strpos($message, 'Created paragraph:') !== FALSE) {
              $stats['paragraphs']++;
            }
            elseif (strpos($message, 'Created media:') !== FALSE) {
              $stats['media']++;
            }
          }
        }

        // Save state to prevent future imports.
        \Drupal::state()->set('dc_config.imported_starter', [
          'id' => $starterId ?? 'unknown',
          'name' => $starterName ?? 'Unknown Starter',
          'imported_at' => date('c'),
          'stats' => $stats,
        ]);

        return new JsonResponse([
          'success' => TRUE,
          'message' => 'Content imported successfully',
          'stats' => $stats,
        ]);
      }

      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Import service not available. Please ensure dc_import module is enabled.',
      ], 500);

    }
    catch (\GuzzleHttp\Exception\RequestException $e) {
      \Drupal::logger('dc_config')->error('Failed to fetch content from @url: @message', [
        '@url' => $contentUrl,
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Failed to fetch content: ' . $e->getMessage(),
      ], 500);
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_config')->error('Import failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Import failed: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Set frontend status (called by dashboard during turnkey provisioning).
   *
   * Stores the Netlify frontend info in Drupal state so the dc-config page
   * can display it.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response.
   */
  /**
   * Get frontend status (called by dc-config JavaScript to update UI).
   */
  /**
   * Trigger frontend connect (Phase 2) — called by dc-config JS when user visits.
   *
   * Imports content, configures preview + puck, updates frontend config to active.
   * This runs on the Drupal side so no timing issues with OAuth consumers.
   */
  public function triggerConnect(Request $request) {
    $frontend_config = $this->configFactory->getEditable('dc_config.frontend');
    $frontend = $frontend_config->get('data');

    if (!$frontend || empty($frontend['url'])) {
      return new JsonResponse(['error' => 'No frontend configured'], 400);
    }

    if (($frontend['status'] ?? '') === 'active') {
      return new JsonResponse(['success' => TRUE, 'message' => 'Already connected']);
    }

    $results = [];

    // Step 1: Import starter content (if not already imported)
    if (empty($frontend['content_imported'])) {
      try {
        $importer = \Drupal::service('dc_import.importer');
        $contentUrl = 'https://raw.githubusercontent.com/nextagencyio/decoupled-components/main/data/components-content.json';
        $json = file_get_contents($contentUrl);
        if ($json) {
          $contentData = json_decode($json, TRUE);
          if ($contentData) {
            $result = $importer->import($contentData);
            $results['content_imported'] = TRUE;
            $results['import_summary'] = $result['summary'] ?? [];
          }
        }
      }
      catch (\Exception $e) {
        $results['content_imported'] = FALSE;
        $results['import_error'] = $e->getMessage();
      }
    }
    else {
      $results['content_imported'] = TRUE;
    }

    // Step 2: Configure preview iframe
    $fe_url = $frontend['url'];
    try {
      $preview_config = $this->configFactory->getEditable('decoupled_preview_iframe.settings');
      $preview_config->set('preview_url', $fe_url);
      $preview_config->set('status', TRUE);
      // Set preview types for common content types
      $types = ['landing_page' => 'landing_page', 'article' => 'article', 'page' => 'page'];
      $preview_config->set('preview_types', ['node' => $types]);
      $preview_config->save();
      $results['preview_configured'] = TRUE;
    }
    catch (\Exception $e) {
      $results['preview_configured'] = FALSE;
    }

    // Step 3: Configure Puck editor
    try {
      $puck_config = $this->configFactory->getEditable('dc_puck.settings');
      $puck_config->set('enabled', TRUE);
      $puck_config->set('editor_url', $fe_url);
      $puck_config->set('enabled_content_types', ['landing_page']);
      $puck_config->save();
      $results['puck_configured'] = TRUE;
    }
    catch (\Exception $e) {
      $results['puck_configured'] = FALSE;
    }

    // Step 4: Update frontend config to active
    $frontend['status'] = 'active';
    $frontend['content_imported'] = $results['content_imported'] ?? FALSE;
    $frontend['preview_configured'] = $results['preview_configured'] ?? FALSE;
    $frontend['puck_configured'] = $results['puck_configured'] ?? FALSE;
    $frontend['updated_at'] = date('c');
    $frontend_config->set('data', $frontend);
    $frontend_config->save();

    // Step 5: Update Netlify env vars via dashboard API
    try {
      $consumer_storage = $this->entityTypeManager->getStorage('consumer');
      $consumers = $consumer_storage->loadByProperties(['label' => 'Next.js Frontend']);
      if (!empty($consumers)) {
        $consumer = reset($consumers);
        $clientId = $consumer->getClientId();
        $clientSecret = $consumer->get('secret')->value;
        $results['credentials'] = ['client_id' => $clientId];

        // Get revalidate secret
        $revalidate_config = $this->configFactory->get('dc_revalidate.settings');
        $revalidateSecret = $revalidate_config->get('revalidate_secret') ?: '';

        // Get space auth token and site URL for the dashboard callback
        $spaceToken = \Drupal::state()->get('dc_import.space_auth_token', '');
        global $base_url;
        $siteUrl = $base_url ?: \Drupal::request()->getSchemeAndHttpHost();

        // Call dashboard to update Netlify env vars
        if ($spaceToken && $clientId && $clientSecret) {
          try {
            $dashboardUrl = 'https://dashboard.decoupled.io';
            $client = \Drupal::httpClient();
            $client->post("$dashboardUrl/api/spaces/frontend-env-update", [
              'json' => [
                'spaceToken' => $spaceToken,
                'drupalBaseUrl' => $siteUrl,
                'clientId' => $clientId,
                'clientSecret' => $clientSecret,
                'revalidateSecret' => $revalidateSecret,
              ],
              'timeout' => 15,
            ]);
            $results['netlify_updated'] = TRUE;
          }
          catch (\Exception $e) {
            $results['netlify_updated'] = FALSE;
            $results['netlify_error'] = $e->getMessage();
          }
        }
      }
    }
    catch (\Exception $e) {
      // Non-fatal
    }

    // Clear caches
    drupal_flush_all_caches();

    return new JsonResponse([
      'success' => TRUE,
      'results' => $results,
    ]);
  }

  public function getFrontendStatus() {
    $frontend = \Drupal::config('dc_config.frontend')->get('data');
    return new JsonResponse($frontend ?: ['status' => 'none']);
  }

  public function setFrontendStatus(Request $request) {
    // Validate token.
    $token = $request->headers->get('X-Decoupled-Token');
    if (!$token) {
      return new JsonResponse(['error' => 'Missing token'], 401);
    }

    // Verify space auth token.
    $state_token = \Drupal::state()->get('dc_import.space_auth_token');
    if ($token !== $state_token) {
      return new JsonResponse(['error' => 'Invalid token'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (empty($data)) {
      return new JsonResponse(['error' => 'Invalid JSON'], 400);
    }

    // Store frontend status in config (not state) so cache tags auto-invalidate.
    $config = \Drupal::configFactory()->getEditable('dc_config.frontend');
    $config->set('data', [
      'provider' => $data['provider'] ?? 'netlify',
      'url' => $data['url'] ?? '',
      'claim_url' => $data['claim_url'] ?? '',
      'template' => $data['template'] ?? 'decoupled-components',
      'status' => $data['status'] ?? 'active',
      'claimed' => $data['claimed'] ?? FALSE,
      'preview_configured' => $data['preview_configured'] ?? FALSE,
      'puck_configured' => $data['puck_configured'] ?? FALSE,
      'content_imported' => $data['content_imported'] ?? FALSE,
      'updated_at' => date('c'),
    ]);
    $config->save();

    return new JsonResponse(['success' => TRUE]);
  }

}
