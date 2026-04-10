<?php

namespace Drupal\dc_mail\Plugin\Mail;

use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the Resend HTTP API mail backend.
 *
 * This plugin sends emails via Resend's HTTP API instead of SMTP.
 * The API key is read from the RESEND_API_KEY environment variable
 * and is never stored in Drupal configuration or the database.
 *
 * @Mail(
 *   id = "resend_mail",
 *   label = @Translation("Resend HTTP API"),
 *   description = @Translation("Sends emails via Resend HTTP API using environment variable for API key.")
 * )
 */
class ResendMail implements MailInterface, ContainerFactoryPluginInterface {

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a ResendMail object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger) {
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('dc_mail')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    // Join the body array into a single string.
    $message['body'] = implode("\n\n", $message['body']);
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    // Get API key from environment variable (never from config).
    $api_key = getenv('RESEND_API_KEY');

    if (empty($api_key)) {
      $this->logger->error('RESEND_API_KEY environment variable not set. Email not sent to @to', [
        '@to' => $message['to'],
      ]);
      return FALSE;
    }

    // Prepare email data for Resend API.
    $email_data = [
      'from' => $message['from'],
      'to' => [$message['to']],
      'subject' => $message['subject'],
    ];

    // Determine if body is HTML or plain text.
    // Check for HTML tags to determine format.
    if ($message['body'] !== strip_tags($message['body'])) {
      $email_data['html'] = $message['body'];
    }
    else {
      $email_data['text'] = $message['body'];
    }

    // Add reply-to if present.
    if (!empty($message['headers']['Reply-To'])) {
      $email_data['reply_to'] = $message['headers']['Reply-To'];
    }

    // Send via Resend HTTP API.
    $ch = curl_init('https://api.resend.com/emails');

    curl_setopt_array($ch, [
      CURLOPT_POST => TRUE,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
      ],
      CURLOPT_POSTFIELDS => json_encode($email_data),
      CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Handle errors.
    if ($curl_error) {
      $this->logger->error('Resend API cURL error: @error. Email not sent to @to', [
        '@error' => $curl_error,
        '@to' => $message['to'],
      ]);
      return FALSE;
    }

    if ($http_code !== 200) {
      $error_data = json_decode($response, TRUE);
      $error_message = $error_data['message'] ?? 'Unknown error';

      $this->logger->error('Resend API HTTP @code error: @message. Email not sent to @to', [
        '@code' => $http_code,
        '@message' => $error_message,
        '@to' => $message['to'],
      ]);
      return FALSE;
    }

    // Success!
    $result = json_decode($response, TRUE);
    $email_id = $result['id'] ?? 'unknown';

    $this->logger->info('Email sent successfully via Resend API to @to (ID: @id)', [
      '@to' => $message['to'],
      '@id' => $email_id,
    ]);

    return TRUE;
  }

}
