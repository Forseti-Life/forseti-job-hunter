<?php

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$keys = ['scope', 'restriction', 'classification', 'temporal', 'sources', 'granularity', 'computational', 'financial', 'data_storage', 'network_bandwidth', 'api_access', 'human', 'legal', 'institutional', 'budget_auth', 'policy', 'override', 'audit', 'connectivity', 'centrality', 'trust_reputation', 'info_flow', 'coalition', 'network_effects', 'reasoning', 'creativity', 'planning', 'learning', 'memory', 'execution'];

$count = 0;
foreach ($keys as $key) {
  $field_name = 'field_sub_' . $key . '_desc';
  
  $storage = FieldStorageConfig::loadByName('node', $field_name);
  if (!$storage) {
    $storage = FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => 'text_long',
      'cardinality' => 1,
      'settings' => [],
    ]);
    $storage->save();
    
    $config = FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'evaluated_entity',
      'label' => ucwords(str_replace('_', ' ', $key)) . ' Description',
      'description' => 'AI-provided description for ' . $key . ' sub-dimension',
      'required' => FALSE,
      'settings' => [],
    ]);
    $config->save();
    $count++;
  }
}

print "Created $count description fields\n";
