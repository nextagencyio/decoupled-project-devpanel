<?php

namespace Drupal\dc_usage\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\NodeType;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for checking and enforcing usage limits.
 */
class UsageLimitsService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Plan limits configuration.
   *
   * @var array
   */
  protected $planLimits = [
    'starter' => [
      'users' => 10,
      'content_types' => 25,
      'entities' => 10000,
    ],
    // Future plans can be added here
    'growth' => [
      'users' => 100,
      'content_types' => 100,
      'entities' => 100000,
    ],
    'business' => [
      'users' => -1,  // -1 means unlimited
      'content_types' => -1,
      'entities' => -1,
    ],
  ];

  /**
   * Constructs a UsageLimitsService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Gets the current plan from environment or defaults to starter.
   *
   * @return string
   *   The plan name (starter, growth, business).
   */
  protected function getCurrentPlan() {
    // Get plan from environment variable or default to starter
    $plan = getenv('DECOUPLED_PLAN');
    if (!$plan || !isset($this->planLimits[$plan])) {
      return 'starter';
    }
    return $plan;
  }

  /**
   * Gets the limits for the current plan.
   *
   * @return array
   *   Array with keys: users, content_types, entities.
   */
  public function getCurrentPlanLimits() {
    $plan = $this->getCurrentPlan();
    return $this->planLimits[$plan];
  }

  /**
   * Checks if a user can be created.
   *
   * @return bool
   *   TRUE if user creation is allowed, FALSE otherwise.
   */
  public function canCreateUser() {
    $limits = $this->getCurrentPlanLimits();

    // -1 means unlimited
    if ($limits['users'] === -1) {
      return TRUE;
    }

    $current_count = $this->getUserCount();
    return $current_count < $limits['users'];
  }

  /**
   * Checks if a content type can be created.
   *
   * @return bool
   *   TRUE if content type creation is allowed, FALSE otherwise.
   */
  public function canCreateContentType() {
    $limits = $this->getCurrentPlanLimits();

    // -1 means unlimited
    if ($limits['content_types'] === -1) {
      return TRUE;
    }

    $current_count = $this->getContentTypeCount();
    return $current_count < $limits['content_types'];
  }

  /**
   * Checks if an entity can be created.
   *
   * @return bool
   *   TRUE if entity creation is allowed, FALSE otherwise.
   */
  public function canCreateEntity() {
    $limits = $this->getCurrentPlanLimits();

    // -1 means unlimited
    if ($limits['entities'] === -1) {
      return TRUE;
    }

    $current_count = $this->getTotalEntityCount();
    return $current_count < $limits['entities'];
  }

  /**
   * Gets the current user count (excluding anonymous).
   *
   * @return int
   *   The number of users.
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
      $this->loggerFactory->get('dc_usage')->error('Error counting users: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Gets the current content type count.
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
      $this->loggerFactory->get('dc_usage')->error('Error counting content types: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Gets the total entity count across all entity types.
   *
   * @return int
   *   The total number of entities.
   */
  protected function getTotalEntityCount() {
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');

      $query = $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->count();

      return $query->execute();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('dc_usage')->error('Error getting total entity count: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * Gets usage information with limits.
   *
   * @return array
   *   Array with current usage and limits.
   */
  public function getUsageWithLimits() {
    $limits = $this->getCurrentPlanLimits();
    $plan = $this->getCurrentPlan();

    return [
      'plan' => $plan,
      'users' => [
        'current' => $this->getUserCount(),
        'limit' => $limits['users'],
        'can_create' => $this->canCreateUser(),
      ],
      'content_types' => [
        'current' => $this->getContentTypeCount(),
        'limit' => $limits['content_types'],
        'can_create' => $this->canCreateContentType(),
      ],
      'entities' => [
        'current' => $this->getTotalEntityCount(),
        'limit' => $limits['entities'],
        'can_create' => $this->canCreateEntity(),
      ],
    ];
  }

}
