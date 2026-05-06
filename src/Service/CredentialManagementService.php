<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for securely managing employer credentials.
 *
 * Handles encryption, decryption, and secure storage of credentials used
 * for automated job application submission. All credentials are encrypted
 * at rest using a combined approach:
 * - AES-256-CBC with random IV (using OpenSSL)
 * - Base64 encoding for safe database storage
 * - Drupal private key for encryption key derivation
 *
 * ⚠️ SECURITY CRITICAL:
 * - Never log credentials or plaintext values
 * - Always require permission/authentication for retrieval
 * - Encrypt before database storage
 * - Decrypt only when actually needed for automation
 * - Clear sensitive data from memory after use
 * - Audit all credential access
 */
class CredentialManagementService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a CredentialManagementService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->database = $database;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
  }

  /**
   * Stores or updates employer credentials securely.
   *
   * ⚠️ Never logs the actual credentials - only audit metadata.
   *
   * @param int $uid
   *   The user ID.
   * @param int $company_id
   *   The company/employer ID (jobhunter_companies).
   * @param string $credential_type
   *   Type of credential: 'basic' (username/password) or 'api_token'.
   * @param array $credential_data
   *   The credentials array:
   *   - For 'basic': ['username' => '...', 'password' => '...']
   *   - For 'api_token': ['token' => '...', 'token_type' => 'Bearer|Basic|Custom']
   * @param string $submission_url
   *   The URL where these credentials are used (e.g., company ATS login page).
   *
   * @return array
   *   Result with structure:
   *   [
   *     'success' => bool,
   *     'credential_id' => int|null,
   *     'message' => string,
   *     'error' => string|null,
   *   ]
   */
  public function storeCredential(int $uid, int $company_id, string $credential_type, array $credential_data, string $submission_url = ''): array {
    $logger = $this->loggerFactory->get('job_hunter');

    try {
      // Validate credential data
      $validation = $this->validateCredentialData($credential_type, $credential_data);
      if (!$validation['success']) {
        return [
          'success' => FALSE,
          'credential_id' => NULL,
          'message' => 'Invalid credential data',
          'error' => $validation['error'],
        ];
      }

      // Encrypt the credential data
      $encrypted_data = $this->encryptCredentialData($credential_data);

      // Check for existing credential for this user/company
      $existing = $this->database->select('jobhunter_employer_credentials', 'c')
        ->fields('c', ['id'])
        ->condition('uid', $uid)
        ->condition('company_id', $company_id)
        ->condition('credential_type', $credential_type)
        ->execute()
        ->fetchField();

      if ($existing) {
        // Update existing credential
        $this->database->update('jobhunter_employer_credentials')
          ->fields([
            'encrypted_data' => $encrypted_data,
            'submission_url' => $submission_url,
            'updated' => date('Y-m-d H:i:s'),
          ])
          ->condition('id', $existing)
          ->execute();

        $credential_id = $existing;
        $message = 'Credential updated successfully';

        // Log credential update (no plaintext logged)
        $logger->info('🔐 Credential updated for user @uid, company @company_id, type @type', [
          '@uid' => $uid,
          '@company_id' => $company_id,
          '@type' => $credential_type,
        ]);
      } else {
        // Insert new credential
        $result = $this->database->insert('jobhunter_employer_credentials')
          ->fields([
            'uid' => $uid,
            'company_id' => $company_id,
            'credential_type' => $credential_type,
            'encrypted_data' => $encrypted_data,
            'submission_url' => $submission_url,
            'created' => date('Y-m-d H:i:s'),
            'updated' => date('Y-m-d H:i:s'),
          ])
          ->execute();

        $credential_id = $result;
        $message = 'Credential stored successfully';

        // Log credential creation (no plaintext logged)
        $logger->info('🔐 Credential stored for user @uid, company @company_id, type @type', [
          '@uid' => $uid,
          '@company_id' => $company_id,
          '@type' => $credential_type,
        ]);
      }

      return [
        'success' => TRUE,
        'credential_id' => $credential_id,
        'message' => $message,
        'error' => NULL,
      ];
    } catch (\Exception $e) {
      $logger->error('Error storing credential: @error', [
        '@error' => $e->getMessage(),
      ]);

      return [
        'success' => FALSE,
        'credential_id' => NULL,
        'message' => 'Error storing credential',
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Retrieves and decrypts employer credentials.
   *
   * ⚠️ SECURITY: This method decrypts sensitive data. Only call when actually
   * needed for browser automation. Clear the returned data after use.
   *
   * @param int $uid
   *   The user ID.
   * @param int $company_id
   *   The company/employer ID.
   * @param string $credential_type
   *   Type of credential to retrieve.
   *
   * @return array|null
   *   Decrypted credential data or NULL if not found:
   *   [
   *     'credential_id' => int,
   *     'type' => string,
   *     'username' => string|null,
   *     'password' => string|null,
   *     'token' => string|null,
   *     'token_type' => string|null,
   *     'submission_url' => string,
   *   ]
   */
  public function retrieveCredential(int $uid, int $company_id, string $credential_type): ?array {
    $logger = $this->loggerFactory->get('job_hunter');

    try {
      // Verify user permission (user can only access their own credentials)
      $current_user = \Drupal::currentUser();
      if ($current_user->id() !== $uid && !$current_user->hasPermission('administer job application automation')) {
        $logger->warning('Unauthorized credential access attempt by user @current_uid for user @target_uid', [
          '@current_uid' => $current_user->id(),
          '@target_uid' => $uid,
        ]);
        return NULL;
      }

      // Retrieve encrypted credential
      $record = $this->database->select('jobhunter_employer_credentials', 'c')
        ->fields('c')
        ->condition('uid', $uid)
        ->condition('company_id', $company_id)
        ->condition('credential_type', $credential_type)
        ->execute()
        ->fetchAssoc();

      if (!$record) {
        return NULL;
      }

      // Decrypt the credential data
      $decrypted_data = $this->decryptCredentialData($record['encrypted_data']);

      // Log credential access (no plaintext logged)
      $logger->info('🔐 Credential retrieved for user @uid, company @company_id', [
        '@uid' => $uid,
        '@company_id' => $company_id,
      ]);

      return [
        'credential_id' => $record['id'],
        'type' => $credential_type,
        'username' => $decrypted_data['username'] ?? NULL,
        'password' => $decrypted_data['password'] ?? NULL,
        'token' => $decrypted_data['token'] ?? NULL,
        'token_type' => $decrypted_data['token_type'] ?? NULL,
        'submission_url' => $record['submission_url'] ?? '',
      ];
    } catch (\Exception $e) {
      $logger->error('Error retrieving credential: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Deletes stored credentials.
   *
   * ⚠️ This operation is logged for audit purposes.
   *
   * @param int $uid
   *   The user ID.
   * @param int $company_id
   *   The company/employer ID.
   * @param string $credential_type
   *   Type of credential to delete.
   *
   * @return bool
   *   TRUE if deleted, FALSE otherwise.
   */
  public function deleteCredential(int $uid, int $company_id, string $credential_type): bool {
    $logger = $this->loggerFactory->get('job_hunter');

    try {
      // Verify user permission
      $current_user = \Drupal::currentUser();
      if ($current_user->id() !== $uid && !$current_user->hasPermission('administer job application automation')) {
        $logger->warning('Unauthorized credential deletion attempt by user @current_uid for user @target_uid', [
          '@current_uid' => $current_user->id(),
          '@target_uid' => $uid,
        ]);
        return FALSE;
      }

      $result = $this->database->delete('jobhunter_employer_credentials')
        ->condition('uid', $uid)
        ->condition('company_id', $company_id)
        ->condition('credential_type', $credential_type)
        ->execute();

      if ($result > 0) {
        $logger->info('🔐 Credential deleted for user @uid, company @company_id, type @type', [
          '@uid' => $uid,
          '@company_id' => $company_id,
          '@type' => $credential_type,
        ]);
        return TRUE;
      }

      return FALSE;
    } catch (\Exception $e) {
      $logger->error('Error deleting credential: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Lists all credentials for a user.
   *
   * Returns metadata only (not decrypted values).
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   Array of credential metadata:
   *   [
   *     [
   *       'id' => int,
   *       'company_id' => int,
   *       'credential_type' => string,
   *       'submission_url' => string,
   *       'created' => string,
   *       'updated' => string,
   *     ],
   *   ]
   */
  public function listUserCredentials(int $uid): array {
    try {
      // Verify user permission
      $current_user = \Drupal::currentUser();
      if ($current_user->id() !== $uid && !$current_user->hasPermission('administer job application automation')) {
        return [];
      }

      return $this->database->select('jobhunter_employer_credentials', 'c')
        ->fields('c', ['id', 'company_id', 'credential_type', 'submission_url', 'created', 'updated'])
        ->condition('uid', $uid)
        ->orderBy('created', 'DESC')
        ->execute()
        ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
      $this->loggerFactory->get('job_hunter')->error('Error listing credentials: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Tests stored credentials against a URL.
   *
   * Attempts to authenticate with stored credentials to verify they work.
   * This is done asynchronously via browser automation in Phase 2.
   *
   * @param int $uid
   *   The user ID.
   * @param int $company_id
   *   The company/employer ID.
   * @param string $credential_type
   *   Type of credential.
   * @param string $test_url
   *   The URL to test authentication against.
   *
   * @return array
   *   Result with structure:
   *   [
   *     'success' => bool,
   *     'message' => string,
   *     'queued' => bool, // True if testing queued for background processing
   *   ]
   */
  public function testCredential(int $uid, int $company_id, string $credential_type, string $test_url): array {
    $logger = $this->loggerFactory->get('job_hunter');

    try {
      // Verify user permission
      $current_user = \Drupal::currentUser();
      if ($current_user->id() !== $uid && !$current_user->hasPermission('administer job application automation')) {
        return [
          'success' => FALSE,
          'message' => 'Permission denied',
          'queued' => FALSE,
        ];
      }

      // Verify credential exists
      $credential = $this->retrieveCredential($uid, $company_id, $credential_type);
      if (!$credential) {
        return [
          'success' => FALSE,
          'message' => 'Credential not found',
          'queued' => FALSE,
        ];
      }

      // Queue credentialtest in background
      $queue = \Drupal::queue('job_hunter_credential_test');
      $queue->createItem([
        'uid' => $uid,
        'company_id' => $company_id,
        'credential_type' => $credential_type,
        'test_url' => $test_url,
        'credential_id' => $credential['credential_id'],
        'timestamp' => time(),
      ]);

      $logger->info('🔐 Credential test queued for user @uid, company @company_id', [
        '@uid' => $uid,
        '@company_id' => $company_id,
      ]);

      return [
        'success' => TRUE,
        'message' => 'Credential test queued. Results will be available shortly.',
        'queued' => TRUE,
      ];
    } catch (\Exception $e) {
      $logger->error('Error testing credential: @error', [
        '@error' => $e->getMessage(),
      ]);

      return [
        'success' => FALSE,
        'message' => 'Error testing credential',
        'queued' => FALSE,
      ];
    }
  }

  /**
   * Validates credential data structure.
   *
   * @param string $credential_type
   *   The credential type (basic, api_token).
   * @param array $credential_data
   *   The credential data to validate.
   *
   * @return array
   *   Validation result:
   *   [
   *     'success' => bool,
   *     'error' => string|null,
   *   ]
   */
  protected function validateCredentialData(string $credential_type, array $credential_data): array {
    if ($credential_type === 'basic') {
      if (empty($credential_data['username']) || empty($credential_data['password'])) {
        return [
          'success' => FALSE,
          'error' => 'Username and password required for basic authentication',
        ];
      }
    } elseif ($credential_type === 'api_token') {
      if (empty($credential_data['token'])) {
        return [
          'success' => FALSE,
          'error' => 'Token required for API authentication',
        ];
      }
    } else {
      return [
        'success' => FALSE,
        'error' => 'Unknown credential type: ' . $credential_type,
      ];
    }

    return ['success' => TRUE];
  }

  /**
   * Encrypts credential data.
   *
   * Uses AES-256-CBC with OpenSSL for encryption. The encryption key is derived
   * from Drupal's private key to ensure portability across environments.
   *
   * @param array $credential_data
   *   The unencrypted credential data.
   *
   * @return string
   *   The encrypted data (base64 encoded with IV prefix).
   */
  protected function encryptCredentialData(array $credential_data): string {
    try {
      // Serialize the credential data to JSON
      $json_data = json_encode($credential_data);

      // Get encryption key from Drupal's private key
      $key = hash_hkdf('sha256', \Drupal::service('private_key')->get(), 32, '', '');

      // Generate a random IV
      $iv = openssl_random_pseudo_bytes(16);

      // Encrypt using AES-256-CBC
      $encrypted = openssl_encrypt($json_data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

      // Combine IV + encrypted data and base64 encode for safe storage
      $combined = $iv . $encrypted;
      return base64_encode($combined);
    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Credential encryption failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Decrypts credential data.
   *
   * @param string $encrypted_data
   *   The encrypted, base64-encoded data (IV + encrypted).
   *
   * @return array
   *   The decrypted credential data.
   *
   * @throws \Exception
   *   If decryption fails.
   */
  protected function decryptCredentialData(string $encrypted_data): array {
    try {
      // Base64 decode to get IV + encrypted data
      $combined = base64_decode($encrypted_data);

      // IV is first 16 bytes
      $iv = substr($combined, 0, 16);
      $encrypted = substr($combined, 16);

      // Get encryption key (same derivation as encrypt)
      $key = hash_hkdf('sha256', \Drupal::service('private_key')->get(), 32, '', '');

      // Decrypt using AES-256-CBC
      $json_data = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

      if ($json_data === FALSE) {
        throw new \Exception('Decryption failed - invalid key or corrupted data');
      }

      // Parse JSON
      $decrypted = json_decode($json_data, TRUE);
      if ($decrypted === NULL) {
        throw new \Exception('JSON decode failed after decryption');
      }

      return $decrypted;
    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Credential decryption failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

}
