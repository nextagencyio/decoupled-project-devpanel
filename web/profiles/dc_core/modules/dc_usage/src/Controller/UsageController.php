<?php

namespace Drupal\dc_usage\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\NodeType;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for usage statistics endpoints.
 */
class UsageController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The usage limits service.
   *
   * @var \Drupal\dc_usage\Service\UsageLimitsService
   */
  protected $limitsService;

  /**
   * Constructs a UsageController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\dc_usage\Service\UsageLimitsService $limits_service
   *   The usage limits service.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system, $limits_service = NULL) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->limitsService = $limits_service ?? \Drupal::service('dc_usage.limits');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('dc_usage.limits')
    );
  }

  /**
   * Returns basic usage statistics.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with usage statistics.
   */
  public function getUsageStats(Request $request) {
    try {
      // Get usage with limits
      $usage_with_limits = $this->limitsService->getUsageWithLimits();

      $stats = [
        'content_types' => $this->getContentTypeCount(),
        'entities' => $this->getEntityStats(),
        'api_requests' => $this->getApiRequestStats(),
        'storage' => $this->getStorageStats(),
        'bandwidth' => $this->getBandwidthStats(),
        'users' => $this->getUserCount(),
        'limits' => $usage_with_limits,
        'timestamp' => time(),
      ];

      $response = new JsonResponse($stats);
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      $response->headers->set('Pragma', 'no-cache');
      $response->headers->set('Expires', '0');
      return $response;
    }
    catch (\Exception $e) {
      $response = new JsonResponse([
        'error' => 'Failed to fetch usage statistics',
        'message' => $e->getMessage(),
        'timestamp' => time(),
      ], 500);
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      return $response;
    }
  }

  /**
   * Returns detailed usage statistics.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with detailed usage statistics.
   */
  public function getDetailedUsage(Request $request) {
    try {
      $stats = [
        'content_types' => $this->getContentTypeCount(),
        'content_type_details' => $this->getContentTypeDetails(),
        'entities' => $this->getEntityStats(),
        'entity_details' => $this->getEntityDetails(),
        'api_requests' => $this->getApiRequestStats(),
        'storage' => $this->getStorageStats(),
        'storage_details' => $this->getStorageDetails(),
        'bandwidth' => $this->getBandwidthStats(),
        'bandwidth_details' => $this->getBandwidthDetails(),
        'users' => $this->getUserCount(),
        'user_details' => $this->getUserDetails(),
        'system_info' => $this->getSystemInfo(),
        'timestamp' => time(),
      ];

      $response = new JsonResponse($stats);
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      $response->headers->set('Pragma', 'no-cache');
      $response->headers->set('Expires', '0');
      return $response;
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error fetching detailed usage stats: @message', ['@message' => $e->getMessage()]);
      $response = new JsonResponse(['error' => 'Failed to fetch detailed usage statistics'], 500);
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      return $response;
    }
  }

  /**
   * Gets the count of content types.
   *
   * @return int
   *   The number of content types.
   */
  protected function getContentTypeCount() {
    try {
      $node_types = NodeType::loadMultiple();
      return count($node_types);
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error counting content types: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Gets content type details.
   *
   * @return array
   *   Array of content type information.
   */
  protected function getContentTypeDetails() {
    try {
      $node_types = NodeType::loadMultiple();
      $details = [];

      foreach ($node_types as $type) {
        $details[] = [
          'id' => $type->id(),
          'label' => $type->label(),
          'description' => $type->getDescription(),
        ];
      }

      return $details;
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error getting content type details: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Gets entity statistics.
   *
   * @return array
   *   Array with entity counts.
   */
  protected function getEntityStats() {
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');

      // Get total count
      $total_query = $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->count();
      $total = $total_query->execute();

      // Get count by type
      $by_type = [];
      $node_types = NodeType::loadMultiple();

      foreach ($node_types as $type_id => $type) {
        $type_query = $node_storage->getQuery()
          ->condition('type', $type_id)
          ->accessCheck(FALSE)
          ->count();
        $count = $type_query->execute();

        if ($count > 0) {
          $by_type[$type_id] = $count;
        }
      }

      return [
        'total' => $total,
        'by_type' => $by_type,
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error getting entity stats: @message', ['@message' => $e->getMessage()]);
      return ['total' => 0, 'by_type' => []];
    }
  }

  /**
   * Gets detailed entity information.
   *
   * @return array
   *   Array with detailed entity information.
   */
  protected function getEntityDetails() {
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');
      $node_types = NodeType::loadMultiple();
      $details = [];

      foreach ($node_types as $type_id => $type) {
        $query = $node_storage->getQuery()
          ->condition('type', $type_id)
          ->accessCheck(FALSE)
          ->count();
        $count = $query->execute();

        if ($count > 0) {
          // Get published vs unpublished counts
          $published_query = $node_storage->getQuery()
            ->condition('type', $type_id)
            ->condition('status', 1)
            ->accessCheck(FALSE)
            ->count();
          $published = $published_query->execute();

          $details[$type_id] = [
            'label' => $type->label(),
            'total' => $count,
            'published' => $published,
            'unpublished' => $count - $published,
          ];
        }
      }

      return $details;
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error getting entity details: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Gets API request statistics.
   *
   * @return array
   *   Array with API request stats.
   */
  protected function getApiRequestStats() {
    try {
      // Check if table exists
      if (!$this->database->schema()->tableExists('dc_usage_api_requests')) {
        return [
          'total_monthly' => 0,
          'by_type' => [
            'graphql' => 0,
            'jsonapi' => 0,
            'rest' => 0,
          ],
          'daily_average' => 0,
        ];
      }

      // Get current month's requests
      $month_start = strtotime('first day of this month 00:00:00');
      $total_monthly = $this->database->select('dc_usage_api_requests', 'r')
        ->condition('timestamp', $month_start, '>=')
        ->countQuery()
        ->execute()
        ->fetchField();

      // Get requests by type for current month
      $by_type_query = $this->database->select('dc_usage_api_requests', 'r')
        ->fields('r', ['api_type'])
        ->condition('timestamp', $month_start, '>=');
      $by_type_query->addExpression('COUNT(*)', 'count');
      $by_type_query->groupBy('api_type');

      $by_type_results = $by_type_query->execute()->fetchAllKeyed();

      $by_type = [
        'graphql' => $by_type_results['graphql'] ?? 0,
        'jsonapi' => $by_type_results['jsonapi'] ?? 0,
        'rest' => $by_type_results['rest'] ?? 0,
      ];

      // Calculate daily average for current month
      $days_in_month = date('j'); // Current day of month
      $daily_average = $days_in_month > 0 ? round($total_monthly / $days_in_month, 1) : 0;

      return [
        'total_monthly' => (int) $total_monthly,
        'by_type' => $by_type,
        'daily_average' => $daily_average,
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error getting API request stats: @message', ['@message' => $e->getMessage()]);
      return [
        'total_monthly' => 0,
        'by_type' => [
          'graphql' => 0,
          'jsonapi' => 0,
          'rest' => 0,
        ],
        'daily_average' => 0,
      ];
    }
  }

  /**
   * Gets storage statistics.
   *
   * @return array
   *   Array with storage information.
   */
  protected function getStorageStats() {
    try {
      $database_size = $this->getDatabaseSize();
      $files_size = $this->getFilesSize();

      return [
        'database_mb' => round($database_size / 1024 / 1024, 2),
        'files_mb' => round($files_size / 1024 / 1024, 2),
        'total_mb' => round(($database_size + $files_size) / 1024 / 1024, 2),
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error getting storage stats: @message', ['@message' => $e->getMessage()]);
      return ['database_mb' => 0, 'files_mb' => 0, 'total_mb' => 0];
    }
  }

  /**
   * Gets detailed storage information.
   *
   * @return array
   *   Array with detailed storage information.
   */
  protected function getStorageDetails() {
    try {
      $file_storage = $this->entityTypeManager->getStorage('file');

      // Get file count
      $file_query = $file_storage->getQuery()
        ->accessCheck(FALSE)
        ->count();
      $file_count = $file_query->execute();

      // Get file size by type
      $files = $file_storage->loadMultiple();
      $by_type = [];
      $total_size = 0;

      foreach ($files as $file) {
        $mime_type = $file->getMimeType();
        $size = $file->getSize();
        $total_size += $size;

        if (!isset($by_type[$mime_type])) {
          $by_type[$mime_type] = ['count' => 0, 'size' => 0];
        }
        $by_type[$mime_type]['count']++;
        $by_type[$mime_type]['size'] += $size;
      }

      return [
        'file_count' => $file_count,
        'total_file_size' => $total_size,
        'by_mime_type' => $by_type,
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error getting storage details: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Gets bandwidth statistics.
   *
   * @return array
   *   Array with bandwidth stats.
   */
  protected function getBandwidthStats() {
    try {
      // Check if table exists and has bandwidth columns
      if (!$this->database->schema()->tableExists('dc_usage_api_requests') ||
          !$this->database->schema()->fieldExists('dc_usage_api_requests', 'total_bytes')) {
        return [
          'monthly_gb' => 0,
          'daily_average_mb' => 0,
          'total_requests' => 0,
          'by_type' => [
            'graphql' => ['gb' => 0, 'requests' => 0],
            'jsonapi' => ['gb' => 0, 'requests' => 0],
            'rest' => ['gb' => 0, 'requests' => 0],
          ],
        ];
      }

      // Get current month's bandwidth
      $month_start = strtotime('first day of this month 00:00:00');

      // Total bandwidth for the month
      $total_query = $this->database->select('dc_usage_api_requests', 'r')
        ->condition('timestamp', $month_start, '>=');
      $total_query->addExpression('SUM(total_bytes)', 'total_bytes');
      $total_query->addExpression('COUNT(*)', 'total_requests');
      $total_result = $total_query->execute()->fetchAssoc();

      $total_bytes = $total_result['total_bytes'] ?? 0;
      $total_requests = $total_result['total_requests'] ?? 0;

      // Bandwidth by API type
      $by_type_query = $this->database->select('dc_usage_api_requests', 'r')
        ->fields('r', ['api_type'])
        ->condition('timestamp', $month_start, '>=');
      $by_type_query->addExpression('SUM(total_bytes)', 'total_bytes');
      $by_type_query->addExpression('COUNT(*)', 'requests');
      $by_type_query->groupBy('api_type');

      $by_type_results = $by_type_query->execute()->fetchAllAssoc('api_type');

      $by_type = [
        'graphql' => [
          'gb' => isset($by_type_results['graphql']) ? round($by_type_results['graphql']->total_bytes / 1024 / 1024 / 1024, 3) : 0,
          'mb' => isset($by_type_results['graphql']) ? round($by_type_results['graphql']->total_bytes / 1024 / 1024, 2) : 0,
          'requests' => isset($by_type_results['graphql']) ? (int) $by_type_results['graphql']->requests : 0,
        ],
        'jsonapi' => [
          'gb' => isset($by_type_results['jsonapi']) ? round($by_type_results['jsonapi']->total_bytes / 1024 / 1024 / 1024, 3) : 0,
          'mb' => isset($by_type_results['jsonapi']) ? round($by_type_results['jsonapi']->total_bytes / 1024 / 1024, 2) : 0,
          'requests' => isset($by_type_results['jsonapi']) ? (int) $by_type_results['jsonapi']->requests : 0,
        ],
        'rest' => [
          'gb' => isset($by_type_results['rest']) ? round($by_type_results['rest']->total_bytes / 1024 / 1024 / 1024, 3) : 0,
          'mb' => isset($by_type_results['rest']) ? round($by_type_results['rest']->total_bytes / 1024 / 1024, 2) : 0,
          'requests' => isset($by_type_results['rest']) ? (int) $by_type_results['rest']->requests : 0,
        ],
      ];

      // Calculate daily average
      $days_in_month = date('j'); // Current day of month
      $daily_average_mb = $days_in_month > 0 ? round($total_bytes / 1024 / 1024 / $days_in_month, 2) : 0;

      return [
        'monthly_gb' => round($total_bytes / 1024 / 1024 / 1024, 3),
        'monthly_mb' => round($total_bytes / 1024 / 1024, 2),
        'daily_average_mb' => $daily_average_mb,
        'total_requests' => (int) $total_requests,
        'by_type' => $by_type,
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error getting bandwidth stats: @message', ['@message' => $e->getMessage()]);
      return [
        'monthly_gb' => 0,
        'daily_average_mb' => 0,
        'total_requests' => 0,
        'by_type' => [
          'graphql' => ['gb' => 0, 'requests' => 0],
          'jsonapi' => ['gb' => 0, 'requests' => 0],
          'rest' => ['gb' => 0, 'requests' => 0],
        ],
      ];
    }
  }

  /**
   * Gets detailed bandwidth statistics.
   *
   * @return array
   *   Array with detailed bandwidth information.
   */
  protected function getBandwidthDetails() {
    try {
      // Check if table exists and has bandwidth columns
      if (!$this->database->schema()->tableExists('dc_usage_api_requests') ||
          !$this->database->schema()->fieldExists('dc_usage_api_requests', 'total_bytes')) {
        return [
          'daily_breakdown' => [],
          'endpoint_breakdown' => [],
          'average_request_size_kb' => 0,
          'average_response_size_kb' => 0,
        ];
      }

      // Get current month's daily breakdown
      $month_start = strtotime('first day of this month 00:00:00');
      $daily_query = $this->database->select('dc_usage_api_requests', 'r')
        ->condition('timestamp', $month_start, '>=');
      $daily_query->addExpression('DATE(FROM_UNIXTIME(timestamp))', 'date');
      $daily_query->addExpression('SUM(total_bytes)', 'total_bytes');
      $daily_query->addExpression('COUNT(*)', 'requests');
      $daily_query->groupBy('DATE(FROM_UNIXTIME(timestamp))');
      $daily_query->orderBy('date');

      $daily_results = $daily_query->execute()->fetchAll();
      $daily_breakdown = [];

      foreach ($daily_results as $row) {
        $daily_breakdown[] = [
          'date' => $row->date,
          'mb' => round($row->total_bytes / 1024 / 1024, 2),
          'requests' => (int) $row->requests,
        ];
      }

      // Get top bandwidth consuming endpoints
      $endpoint_query = $this->database->select('dc_usage_api_requests', 'r')
        ->fields('r', ['endpoint_path', 'api_type'])
        ->condition('timestamp', $month_start, '>=');
      $endpoint_query->addExpression('SUM(total_bytes)', 'total_bytes');
      $endpoint_query->addExpression('COUNT(*)', 'requests');
      $endpoint_query->addExpression('AVG(total_bytes)', 'avg_bytes');
      $endpoint_query->groupBy('endpoint_path');
      $endpoint_query->groupBy('api_type');
      $endpoint_query->orderBy('total_bytes', 'DESC');
      $endpoint_query->range(0, 10); // Top 10 endpoints

      $endpoint_results = $endpoint_query->execute()->fetchAll();
      $endpoint_breakdown = [];

      foreach ($endpoint_results as $row) {
        $endpoint_breakdown[] = [
          'endpoint' => $row->endpoint_path,
          'api_type' => $row->api_type,
          'total_mb' => round($row->total_bytes / 1024 / 1024, 2),
          'requests' => (int) $row->requests,
          'avg_kb_per_request' => round($row->avg_bytes / 1024, 2),
        ];
      }

      // Get average request and response sizes
      $avg_query = $this->database->select('dc_usage_api_requests', 'r')
        ->condition('timestamp', $month_start, '>=');
      $avg_query->addExpression('AVG(request_size)', 'avg_request');
      $avg_query->addExpression('AVG(response_size)', 'avg_response');
      $avg_result = $avg_query->execute()->fetchAssoc();

      return [
        'daily_breakdown' => $daily_breakdown,
        'endpoint_breakdown' => $endpoint_breakdown,
        'average_request_size_kb' => round(($avg_result['avg_request'] ?? 0) / 1024, 2),
        'average_response_size_kb' => round(($avg_result['avg_response'] ?? 0) / 1024, 2),
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error getting detailed bandwidth stats: @message', ['@message' => $e->getMessage()]);
      return [
        'daily_breakdown' => [],
        'endpoint_breakdown' => [],
        'average_request_size_kb' => 0,
        'average_response_size_kb' => 0,
      ];
    }
  }

  /**
   * Gets user count.
   *
   * @return int
   *   The number of users (excluding anonymous user).
   */
  protected function getUserCount() {
    try {
      $user_storage = $this->entityTypeManager->getStorage('user');
      $query = $user_storage->getQuery()
        ->condition('uid', 0, '>')  // Exclude anonymous user (uid=0)
        ->accessCheck(FALSE)
        ->count();

      return $query->execute();
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error counting users: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Gets detailed user information.
   *
   * @return array
   *   Array with user details.
   */
  protected function getUserDetails() {
    try {
      $user_storage = $this->entityTypeManager->getStorage('user');

      // Get total users (excluding anonymous)
      $total_query = $user_storage->getQuery()
        ->condition('uid', 0, '>')
        ->accessCheck(FALSE)
        ->count();
      $total = $total_query->execute();

      // Get active users (logged in within last 30 days)
      $thirty_days_ago = time() - (30 * 24 * 60 * 60);
      $active_query = $user_storage->getQuery()
        ->condition('uid', 0, '>')
        ->condition('access', $thirty_days_ago, '>')
        ->accessCheck(FALSE)
        ->count();
      $active = $active_query->execute();

      // Get blocked users
      $blocked_query = $user_storage->getQuery()
        ->condition('uid', 0, '>')
        ->condition('status', 0)
        ->accessCheck(FALSE)
        ->count();
      $blocked = $blocked_query->execute();

      return [
        'total' => $total,
        'active_30_days' => $active,
        'blocked' => $blocked,
        'active' => $total - $blocked,
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error getting user details: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Gets system information.
   *
   * @return array
   *   Array with system information.
   */
  protected function getSystemInfo() {
    try {
      return [
        'drupal_version' => \Drupal::VERSION,
        'php_version' => PHP_VERSION,
        'database_type' => $this->database->databaseType(),
        'site_name' => \Drupal::config('system.site')->get('name'),
        'install_time' => \Drupal::state()->get('install_time'),
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error getting system info: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Gets database size in bytes.
   *
   * @return int
   *   Database size in bytes.
   */
  protected function getDatabaseSize() {
    try {
      $database_type = $this->database->databaseType();

      if ($database_type === 'mysql') {
        // For MySQL/MariaDB, query the information schema.
        $database_info = $this->database->getConnectionOptions();
        $database_name = $database_info['database'] ?? '';

        if ($database_name) {
          // Handle both string and array prefix formats.
          $prefix = '';
          if (isset($database_info['prefix'])) {
            if (is_array($database_info['prefix'])) {
              $prefix = $database_info['prefix']['default'] ?? '';
            }
            else {
              $prefix = $database_info['prefix'];
            }
          }

          $query = $this->database->query(
            "SELECT SUM(data_length + index_length) as size
             FROM information_schema.TABLES
             WHERE table_schema = :database
             AND table_name LIKE :prefix",
            [
              ':database' => $database_name,
              ':prefix' => $prefix . '%',
            ]
          );
          $result = $query->fetchField();
          return $result ? (int) $result : 0;
        }
      }

      return 0;
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error getting database size: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Gets total files size in bytes.
   *
   * @return int
   *   Files size in bytes.
   */
  protected function getFilesSize() {
    try {
      $file_storage = $this->entityTypeManager->getStorage('file');
      $files = $file_storage->loadMultiple();

      $total_size = 0;
      foreach ($files as $file) {
        $total_size += $file->getSize();
      }

      return $total_size;
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error getting files size: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Debug endpoint to check module status.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with debug information.
   */
  public function debugInfo(Request $request) {
    try {
      $debug_info = [
        'module_enabled' => \Drupal::moduleHandler()->moduleExists('dc_usage'),
        'database_connection' => 'OK',
        'table_exists' => $this->database->schema()->tableExists('dc_usage_api_requests'),
        'columns' => [],
        'row_count' => 0,
        'timestamp' => time(),
      ];

      if ($debug_info['table_exists']) {
        $columns = ['id', 'endpoint_path', 'method', 'api_type', 'response_time', 'status_code', 'request_size', 'response_size', 'total_bytes', 'timestamp'];
        foreach ($columns as $column) {
          $debug_info['columns'][$column] = $this->database->schema()->fieldExists('dc_usage_api_requests', $column);
        }

        try {
          $debug_info['row_count'] = $this->database->select('dc_usage_api_requests', 'r')->countQuery()->execute()->fetchField();
        } catch (\Exception $e) {
          $debug_info['row_count_error'] = $e->getMessage();
        }
      }

      $response = new JsonResponse($debug_info);
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      return $response;
    }
    catch (\Exception $e) {
      $response = new JsonResponse([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ], 500);
      $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
      return $response;
    }
  }

}
