<?php

use Drupal\block\Entity\Block;

// Create the local tasks block for Forseti theme
$values = [
  'id' => 'forseti_local_tasks',
  'theme' => 'forseti',
  'region' => 'tabs',
  'weight' => 0,
  'plugin' => 'local_tasks_block',
  'settings' => [
    'id' => 'local_tasks_block',
    'label' => 'Tabs',
    'label_display' => '0',
    'provider' => 'core',
    'primary' => TRUE,
    'secondary' => TRUE,
  ],
];

try {
  // Check if block already exists
  $block = Block::load('forseti_local_tasks');
  if ($block) {
    echo "Block already exists. Updating...\n";
    foreach ($values as $key => $value) {
      if ($key !== 'id') {
        $block->set($key, $value);
      }
    }
    $block->save();
    echo "Block updated successfully!\n";
  } else {
    // Create new block
    $block = Block::create($values);
    $block->save();
    echo "Block created successfully!\n";
  }
  
  echo "Block ID: " . $block->id() . "\n";
  echo "Region: " . $block->getRegion() . "\n";
  echo "Status: " . ($block->status() ? 'Enabled' : 'Disabled') . "\n";
  
} catch (\Exception $e) {
  echo "Error: " . $e->getMessage() . "\n";
}
