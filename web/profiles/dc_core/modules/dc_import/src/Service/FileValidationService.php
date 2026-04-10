<?php

namespace Drupal\dc_import\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;

/**
 * Service for validating file uploads with enhanced error handling.
 */
class FileValidationService {

  use StringTranslationTrait;

  /**
   * Default maximum file size (unlimited).
   */
  const DEFAULT_MAX_SIZE = 0; // 0 = unlimited

  /**
   * Default allowed image extensions.
   */
  const DEFAULT_EXTENSIONS = ['png', 'gif', 'jpg', 'jpeg', 'svg', 'webp'];

  /**
   * Validates an uploaded file against size and type constraints.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity to validate.
   * @param array $settings
   *   Field settings containing validation rules.
   *
   * @return array
   *   Array of validation errors, empty if valid.
   */
  public function validateFile(FileInterface $file, array $settings = []): array {
    $errors = [];


    // Validate file extension.
    $allowed_extensions = $this->parseFileExtensions($settings['file_extensions'] ?? 'png gif jpg jpeg svg webp');
    $file_extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    if (!in_array($file_extension, $allowed_extensions)) {
      $errors[] = $this->t('The file @filename has an extension of @extension which is not allowed. Allowed extensions: @extensions.', [
        '@filename' => $file->getFilename(),
        '@extension' => $file_extension,
        '@extensions' => implode(', ', $allowed_extensions),
      ]);
    }

    // Validate image dimensions if specified.
    if (!empty($settings['max_resolution']) && $this->isImageFile($file)) {
      $resolution_errors = $this->validateImageResolution($file, $settings);
      $errors = array_merge($errors, $resolution_errors);
    }

    // Validate MIME type for security.
    $mime_errors = $this->validateMimeType($file);
    $errors = array_merge($errors, $mime_errors);

    return $errors;
  }

  /**
   * Validates image resolution constraints.
   *
   * @param \Drupal\file\FileInterface $file
   *   The image file to validate.
   * @param array $settings
   *   Field settings containing resolution limits.
   *
   * @return array
   *   Array of validation errors.
   */
  protected function validateImageResolution(FileInterface $file, array $settings): array {
    $errors = [];
    
    if (function_exists('getimagesize')) {
      $image_info = getimagesize($file->getFileUri());
      if ($image_info !== FALSE) {
        [$width, $height] = $image_info;
        
        // Check maximum resolution.
        if (!empty($settings['max_resolution'])) {
          [$max_width, $max_height] = explode('x', $settings['max_resolution']);
          if ($width > $max_width || $height > $max_height) {
            $errors[] = $this->t('The image @filename is @widthx@height pixels, which exceeds the maximum allowed dimensions of @max_resolution.', [
              '@filename' => $file->getFilename(),
              '@width' => $width,
              '@height' => $height,
              '@max_resolution' => $settings['max_resolution'],
            ]);
          }
        }
        
        // Check minimum resolution.
        if (!empty($settings['min_resolution'])) {
          [$min_width, $min_height] = explode('x', $settings['min_resolution']);
          if ($width < $min_width || $height < $min_height) {
            $errors[] = $this->t('The image @filename is @widthx@height pixels, which is below the minimum required dimensions of @min_resolution.', [
              '@filename' => $file->getFilename(),
              '@width' => $width,
              '@height' => $height,
              '@min_resolution' => $settings['min_resolution'],
            ]);
          }
        }
      }
    }
    
    return $errors;
  }

  /**
   * Validates MIME type for security.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to validate.
   *
   * @return array
   *   Array of validation errors.
   */
  protected function validateMimeType(FileInterface $file): array {
    $errors = [];
    $mime_type = $file->getMimeType();
    
    // Define allowed MIME types for images.
    $allowed_mime_types = [
      'image/png',
      'image/gif',
      'image/jpeg',
      'image/jpg',
      'image/svg+xml',
      'image/webp',
    ];
    
    if (!in_array($mime_type, $allowed_mime_types)) {
      $errors[] = $this->t('The file @filename has a MIME type of @mime_type which is not allowed for image uploads.', [
        '@filename' => $file->getFilename(),
        '@mime_type' => $mime_type,
      ]);
    }
    
    return $errors;
  }

  /**
   * Parses file size string into bytes.
   *
   * @param string $size
   *   Size string like '5MB', '1GB', etc.
   *
   * @return int
   *   Size in bytes.
   */
  protected function parseFileSize(string $size): int {
    if (empty($size)) {
      return self::DEFAULT_MAX_SIZE;
    }
    
    $size = strtoupper(trim($size));
    $unit = preg_replace('/[^A-Z]/', '', $size);
    $value = (float) preg_replace('/[^0-9.]/', '', $size);
    
    switch ($unit) {
      case 'GB':
        return (int) ($value * 1073741824);
      case 'MB':
        return (int) ($value * 1048576);
      case 'KB':
        return (int) ($value * 1024);
      default:
        return (int) $value;
    }
  }

  /**
   * Parses file extensions string into array.
   *
   * @param string $extensions
   *   Space-separated extension string.
   *
   * @return array
   *   Array of lowercase extensions.
   */
  protected function parseFileExtensions(string $extensions): array {
    if (empty($extensions)) {
      return self::DEFAULT_EXTENSIONS;
    }
    
    return array_map('strtolower', array_filter(preg_split('/[\s,]+/', $extensions)));
  }

  /**
   * Checks if the file is an image based on MIME type.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file to check.
   *
   * @return bool
   *   TRUE if the file is an image.
   */
  protected function isImageFile(FileInterface $file): bool {
    return strpos($file->getMimeType(), 'image/') === 0;
  }

  /**
   * Formats bytes into human-readable format.
   *
   * @param int $bytes
   *   Size in bytes.
   *
   * @return string
   *   Formatted size string.
   */
  protected function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $power = floor(log($bytes, 1024));
    
    return number_format($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
  }

}