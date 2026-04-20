<?php

namespace Drupal\amisafe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Controller for AmISafe mobile app downloads.
 */
class MobileDownloadController extends ControllerBase {

  /**
   * Display the mobile app download page.
   */
  public function downloadPage() {
    $module_path = \Drupal::service('extension.list.module')->getPath('amisafe');
    $files_path = 'public://forseti/mobile';
    
    // Check if production APK exists
    $apk_uri = $files_path . '/Forseti-release.apk';
    $apk_url = file_exists(\Drupal::service('file_system')->realpath($apk_uri)) 
      ? \Drupal::service('file_url_generator')->generateAbsoluteString($apk_uri)
      : null;
    
    // Check if development APK exists
    $apk_dev_uri = $files_path . '/Forseti-debug.apk';
    $apk_dev_url = file_exists(\Drupal::service('file_system')->realpath($apk_dev_uri)) 
      ? \Drupal::service('file_url_generator')->generateAbsoluteString($apk_dev_uri)
      : null;
    
    // Check if IPA exists
    $ipa_uri = $files_path . '/AmISafe.ipa';
    $ipa_url = file_exists(\Drupal::service('file_system')->realpath($ipa_uri))
      ? \Drupal::service('file_url_generator')->generateAbsoluteString($ipa_uri)
      : null;
    
    // Get file sizes
    $apk_size = $apk_url ? filesize(\Drupal::service('file_system')->realpath($apk_uri)) : 0;
    $apk_dev_size = $apk_dev_url ? filesize(\Drupal::service('file_system')->realpath($apk_dev_uri)) : 0;
    $ipa_size = $ipa_url ? filesize(\Drupal::service('file_system')->realpath($ipa_uri)) : 0;
    
    return [
      '#theme' => 'amisafe_mobile_download',
      '#apk_url' => $apk_url,
      '#apk_dev_url' => $apk_dev_url,
      '#ipa_url' => $ipa_url,
      '#apk_size' => $this->formatBytes($apk_size),
      '#apk_dev_size' => $this->formatBytes($apk_dev_size),
      '#ipa_size' => $this->formatBytes($ipa_size),
      '#attached' => [
        'library' => [
          'amisafe/mobile-download',
        ],
      ],
    ];
  }

  /**
   * Format bytes to human-readable size.
   */
  private function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
  }

}
