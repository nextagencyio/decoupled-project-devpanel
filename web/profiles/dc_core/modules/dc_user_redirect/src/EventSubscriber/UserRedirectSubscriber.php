<?php

namespace Drupal\dc_user_redirect\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects logged-in users away from password reset pages.
 */
class UserRedirectSubscriber implements EventSubscriberInterface {

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a UserRedirectSubscriber object.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   */
  public function __construct(AccountProxyInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 100],
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

    // Check if this is a password reset related URL
    if ($this->isPasswordResetPath($path) && $this->currentUser->isAuthenticated()) {
      // Redirect to user profile page
      $user_url = Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser->id()]);
      $response = new RedirectResponse($user_url->toString());
      $event->setResponse($response);
    }
  }

  /**
   * Checks if the current path is a password reset related path.
   *
   * @param string $path
   *   The request path.
   *
   * @return bool
   *   TRUE if this is a password reset path.
   */
  protected function isPasswordResetPath($path) {
    $reset_patterns = [
      '/^\/user\/reset\/\d+\/\d+\/[^\/]+\/login$/',  // One-time login links: /user/reset/{uid}/{timestamp}/{hash}/login
      '/^\/user\/reset\//',  // General reset paths
      '/^\/user\/password/',  // Password reset form
      '/^\/user\/reset$/',  // Base reset page
    ];

    foreach ($reset_patterns as $pattern) {
      if (preg_match($pattern, $path)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}