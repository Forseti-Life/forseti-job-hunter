<?php

namespace Drupal\job_hunter\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Job Application Automation Drush commands.
 */
class JobApplicationAutomationCommands extends DrushCommands {

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
   * Constructs a JobApplicationAutomationCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Fix NumberWidget configuration to resolve PHP 8.3+ warnings.
   *
   * @command job-app:fix-numberwidget
   * @option dry-run Preview changes without applying them
   * @aliases jafix
   * @usage job-app:fix-numberwidget
   *   Fix NumberWidget prefix/suffix configuration issues.
   * @usage job-app:fix-numberwidget --dry-run
   *   Preview what changes would be made without applying them.
   */
  public function fixNumberWidget($options = ['dry-run' => FALSE]) {
    $logger = $this->loggerFactory->get('job_hunter');
    $dryRun = $options['dry-run'];
    
    if ($dryRun) {
      $this->output()->writeln('<info>DRY-RUN MODE: Changes will NOT be saved</info>');
    }
    
    $this->output()->writeln('Starting NumberWidget configuration fix...');
    $logger->info('Starting NumberWidget configuration fix' . ($dryRun ? ' (dry-run mode)' : ''));
    
    try {
      // Load the job_seeker profile form display
      $form_display = $this->entityTypeManager
        ->getStorage('entity_form_display')
        ->load('profile.job_seeker.default');
      
      if (!$form_display) {
        $this->output()->writeln('<error>Form display profile.job_seeker.default not found</error>');
        $logger->error('Form display profile.job_seeker.default not found');
        return;
      }
      
      $updated_fields = 0;
      $missing_fields = [];
      $number_fields = [
        'field_experience_years' => ['suffix' => ' years'],
        'field_salary_expectation_min' => ['prefix' => '$'],
        'field_salary_expectation_max' => ['prefix' => '$'],
        'field_profile_completeness' => [],
      ];
      
      foreach ($number_fields as $field_name => $field_settings) {
        $component = $form_display->getComponent($field_name);
        
        if ($component) {
          // Ensure settings array exists
          if (!isset($component['settings'])) {
            $component['settings'] = [];
          }
          
          // Set prefix and suffix with proper values
          $component['settings']['prefix'] = $field_settings['prefix'] ?? null;
          $component['settings']['suffix'] = $field_settings['suffix'] ?? null;
          
          // Ensure widget type is correct
          if ($component['type'] !== 'number') {
            $component['type'] = 'number';
          }
          
          // For hidden fields, ensure proper structure
          if ($field_name === 'field_profile_completeness') {
            $component['weight'] = 100;
            $component['region'] = 'content';
            $component['settings']['placeholder'] = '';
            $component['third_party_settings'] = [];
          }
          
          $form_display->setComponent($field_name, $component);
          $updated_fields++;
          
          $action = $dryRun ? 'Would update' : '✓ Updated';
          $this->output()->writeln("{$action} {$field_name}");
          $logger->info('Fixed NumberWidget configuration for field: @field', ['@field' => $field_name]);
        } else {
          $missing_fields[] = $field_name;
          $this->output()->writeln("<comment>Field {$field_name} not found in form display</comment>");
          $logger->warning('Field not found in form display: @field', ['@field' => $field_name]);
        }
      }
      
      if (!empty($missing_fields)) {
        $this->output()->writeln('<comment>Missing fields: ' . implode(', ', $missing_fields) . '</comment>');
      }
      
      if ($updated_fields > 0) {
        if (!$dryRun) {
          $form_display->save();
          
          // Clear relevant caches
          \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
          \Drupal::cache('render')->deleteAll();
          \Drupal::cache('config')->deleteAll();
          drupal_flush_all_caches();
          
          $this->output()->writeln("<info>✓ Successfully updated {$updated_fields} fields and cleared caches</info>");
          $logger->info('NumberWidget configuration fix completed. Updated @count fields.', ['@count' => $updated_fields]);
        } else {
          $this->output()->writeln("<info>DRY-RUN: Would update {$updated_fields} fields and clear caches</info>");
          $logger->info('NumberWidget configuration fix dry-run completed. Would update @count fields.', ['@count' => $updated_fields]);
        }
      } else {
        // No fields were updated - provide clear messaging
        if (!empty($missing_fields)) {
          $this->output()->writeln('<comment>No fields were updated. All specified fields are missing from form display.</comment>');
          $logger->warning('No fields were found to update. All specified fields are missing.');
        } else {
          $this->output()->writeln('<comment>No fields required updates (all fields are already correctly configured)</comment>');
          $logger->info('No fields required updates');
        }
      }
      
    } catch (\Exception $e) {
      $this->output()->writeln('<error>Error: ' . $e->getMessage() . '</error>');
      $logger->error('Error fixing NumberWidget configuration: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * Clear all caches and check for configuration changes.
   *
   * @command job-app:refresh-config
   * @aliases jarefresh
   * @usage job-app:refresh-config
   *   Clear caches and check for configuration changes.
   */
  public function refreshConfig() {
    $logger = $this->loggerFactory->get('job_hunter');
    
    $this->output()->writeln('Clearing all caches...');
    $logger->info('Starting cache clear and configuration check');
    drupal_flush_all_caches();
    $this->output()->writeln('<info>✓ All caches cleared</info>');
    
    $this->output()->writeln('');
    $this->output()->writeln('Checking for configuration changes...');
    try {
      // Import configuration
      $storage_sync = \Drupal::service('config.storage.sync');
      $storage_active = \Drupal::service('config.storage');
      
      // Get config differences
      $config_comparer = new \Drupal\Core\Config\StorageComparer($storage_sync, $storage_active);
      $config_comparer->createChangelist();
      
      if ($config_comparer->hasChanges()) {
        $this->output()->writeln('<info>Configuration changes detected:</info>');
        
        $changelist = $config_comparer->getChangelist();
        foreach (['create', 'update', 'delete', 'rename'] as $change_type) {
          if (!empty($changelist[$change_type])) {
            $count = count($changelist[$change_type]);
            $this->output()->writeln("  {$change_type}: {$count} item(s)");
          }
        }
        
        $this->output()->writeln('');
        $this->output()->writeln('To import these changes, run:');
        $this->output()->writeln('  <info>drush config:import -y</info>');
        $logger->info('Configuration changes detected and reported to user');
      } else {
        $this->output()->writeln('<info>✓ No configuration changes to import</info>');
        $logger->info('No configuration changes detected');
      }
      
    } catch (\Exception $e) {
      $this->output()->writeln('<error>Configuration check error: ' . $e->getMessage() . '</error>');
      $logger->error('Configuration check error: @error', ['@error' => $e->getMessage()]);
      return;
    }
    
    $this->output()->writeln('');
    $this->output()->writeln('✓ Configuration check completed');
    $logger->info('Configuration check completed successfully');
  }

}