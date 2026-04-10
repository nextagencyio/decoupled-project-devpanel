<?php

namespace Drupal\dc_usage\EventSubscriber;

use Drupal\Core\Database\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscribes to kernel events to track API requests.
 */
class ApiRequestSubscriber implements EventSubscriberInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The request start time.
   *
   * @var float
   */
  protected $requestStartTime;

  /**
   * The current request path.
   *
   * @var string
   */
  protected $requestPath;

  /**
   * The request size in bytes.
   *
   * @var int
   */
  protected $requestSize;

  /**
   * Constructs an ApiRequestSubscriber object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 100],
      KernelEvents::RESPONSE => ['onKernelResponse', -100],
    ];
  }

  /**
   * Handles the kernel request event.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event object.
   */
  public function onKernelRequest(RequestEvent $event) {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // Only track API endpoints
    if ($this->isApiEndpoint($path)) {
      $this->requestStartTime = microtime(TRUE);
      $this->requestPath = $path;
      $this->requestSize = $this->calculateRequestSize($request);
    }
  }

  /**
   * Handles the kernel response event.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event object.
   */
  public function onKernelResponse(ResponseEvent $event) {
    if (!$event->isMainRequest() || !$this->requestStartTime) {
      return;
    }

    $request = $event->getRequest();
    $response = $event->getResponse();
    
    $response_time = round((microtime(TRUE) - $this->requestStartTime) * 1000);
    $status_code = $response->getStatusCode();
    $method = $request->getMethod();
    $response_size = $this->calculateResponseSize($response);
    $total_bytes = $this->requestSize + $response_size;

    $api_type = $this->getApiType($this->requestPath);

    try {
      // Create the table if it doesn't exist
      $this->ensureApiRequestsTable();

      // Insert the request log
      $this->database->insert('dc_usage_api_requests')
        ->fields([
          'endpoint_path' => $this->requestPath,
          'method' => $method,
          'api_type' => $api_type,
          'response_time' => $response_time,
          'status_code' => $status_code,
          'request_size' => $this->requestSize,
          'response_size' => $response_size,
          'total_bytes' => $total_bytes,
          'timestamp' => time(),
        ])
        ->execute();

    } catch (\Exception $e) {
      \Drupal::logger('dc_usage')->error('Error logging API request: @message', ['@message' => $e->getMessage()]);
    }

    // Reset for next request
    $this->requestStartTime = NULL;
    $this->requestPath = NULL;
    $this->requestSize = 0;
  }

  /**
   * Checks if the path is an API endpoint we want to track.
   *
   * @param string $path
   *   The request path.
   *
   * @return bool
   *   TRUE if this is an API endpoint to track.
   */
  protected function isApiEndpoint($path) {
    // Exclude usage reporting endpoints from tracking to avoid counting usage checks as API usage
    $excluded_patterns = [
      '/^\/api\/decoupled\/usage/',
    ];

    foreach ($excluded_patterns as $pattern) {
      if (preg_match($pattern, $path)) {
        return FALSE;
      }
    }

    $api_patterns = [
      '/^\/jsonapi\//',
      '/^\/graphql/',
      '/^\/rest\//',
      '/^\/api\//',
    ];

    foreach ($api_patterns as $pattern) {
      if (preg_match($pattern, $path)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Determines the API type from the path.
   *
   * @param string $path
   *   The request path.
   *
   * @return string
   *   The API type (jsonapi, graphql, rest, or custom).
   */
  protected function getApiType($path) {
    if (strpos($path, '/jsonapi/') === 0) {
      return 'jsonapi';
    }
    if (strpos($path, '/graphql') === 0) {
      return 'graphql';
    }
    if (strpos($path, '/rest/') === 0) {
      return 'rest';
    }
    return 'custom';
  }

  /**
   * Calculates the size of the request in bytes.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return int
   *   The request size in bytes.
   */
  protected function calculateRequestSize($request) {
    $size = 0;
    
    // Add headers size.
    foreach ($request->headers->all() as $name => $values) {
      foreach ($values as $value) {
        // +4 for ": " and "\r\n".
        $size += strlen($name) + strlen($value) + 4;
      }
    }

    // Add request line size (method + URI + protocol).
    // +12 for " HTTP/1.1\r\n".
    $size += strlen($request->getMethod()) + strlen($request->getRequestUri()) + 12;

    // Add body size.
    $content = $request->getContent();
    if ($content) {
      $size += strlen($content);
    }
    
    return $size;
  }

  /**
   * Calculates the size of the response in bytes.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response object.
   *
   * @return int
   *   The response size in bytes.
   */
  protected function calculateResponseSize($response) {
    $size = 0;
    
    // Add status line size.
    $statusCode = $response->getStatusCode();
    $statusText = $this->getStatusText($statusCode);
    $size += strlen('HTTP/1.1 ' . $statusCode . ' ' . $statusText . "\r\n");

    // Add headers size.
    foreach ($response->headers->all() as $name => $values) {
      foreach ($values as $value) {
        // +4 for ": " and "\r\n".
        $size += strlen($name) + strlen($value) + 4;
      }
    }

    // Add content size.
    $content = $response->getContent();
    if ($content) {
      $size += strlen($content);
    }
    
    return $size;
  }

  /**
   * Gets the status text for a given HTTP status code.
   *
   * @param int $statusCode
   *   The HTTP status code.
   *
   * @return string
   *   The status text.
   */
  protected function getStatusText($statusCode) {
    $statusTexts = [
      200 => 'OK',
      201 => 'Created',
      204 => 'No Content',
      400 => 'Bad Request',
      401 => 'Unauthorized',
      403 => 'Forbidden',
      404 => 'Not Found',
      405 => 'Method Not Allowed',
      500 => 'Internal Server Error',
      502 => 'Bad Gateway',
      503 => 'Service Unavailable',
    ];
    
    return $statusTexts[$statusCode] ?? 'Unknown';
  }

  /**
   * Ensures the API requests table exists.
   */
  protected function ensureApiRequestsTable() {
    $schema = $this->database->schema();
    
    if (!$schema->tableExists('dc_usage_api_requests')) {
      $table_spec = [
        'description' => 'Stores API request logs for usage tracking.',
        'fields' => [
          'id' => [
            'type' => 'serial',
            'not null' => TRUE,
            'description' => 'Primary Key: Unique request ID.',
          ],
          'endpoint_path' => [
            'type' => 'varchar',
            'length' => 500,
            'not null' => TRUE,
            'description' => 'The API endpoint path.',
          ],
          'method' => [
            'type' => 'varchar',
            'length' => 10,
            'not null' => TRUE,
            'description' => 'HTTP method (GET, POST, etc.).',
          ],
          'api_type' => [
            'type' => 'varchar',
            'length' => 50,
            'not null' => TRUE,
            'description' => 'Type of API (jsonapi, graphql, rest, custom).',
          ],
          'response_time' => [
            'type' => 'int',
            'not null' => TRUE,
            'description' => 'Response time in milliseconds.',
          ],
          'status_code' => [
            'type' => 'int',
            'not null' => TRUE,
            'description' => 'HTTP status code.',
          ],
          'request_size' => [
            'type' => 'int',
            'not null' => TRUE,
            'default' => 0,
            'description' => 'Request size in bytes.',
          ],
          'response_size' => [
            'type' => 'int',
            'not null' => TRUE,
            'default' => 0,
            'description' => 'Response size in bytes.',
          ],
          'total_bytes' => [
            'type' => 'int',
            'not null' => TRUE,
            'default' => 0,
            'description' => 'Total bytes transferred (request + response).',
          ],
          'timestamp' => [
            'type' => 'int',
            'not null' => TRUE,
            'description' => 'Unix timestamp of the request.',
          ],
        ],
        'primary key' => ['id'],
        'indexes' => [
          'api_type' => ['api_type'],
          'timestamp' => ['timestamp'],
          'endpoint_path' => ['endpoint_path'],
          'total_bytes' => ['total_bytes'],
        ],
      ];
      $schema->createTable('dc_usage_api_requests', $table_spec);
    }
  }

}