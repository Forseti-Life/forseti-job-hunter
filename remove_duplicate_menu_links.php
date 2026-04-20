<?php
/**
 * Remove duplicate menu links from main navigation.
 */

echo "Checking for duplicate menu links...\n\n";

// Get all menu links
$menu_tree_service = \Drupal::service('menu.link_tree');
$parameters = new \Drupal\Core\Menu\MenuTreeParameters();
$parameters->onlyEnabledLinks();
$tree = $menu_tree_service->load('main', $parameters);

// Track seen titles and routes
$seen = [];
$to_delete = [];

function check_tree($tree, &$seen, &$to_delete, $indent = '') {
  foreach ($tree as $item) {
    $link = $item->link;
    $title = $link->getTitle();
    $route = $link->getUrlObject()->toString();
    $plugin_id = $link->getPluginId();
    
    // Create unique key
    $key = $title . '|' . $route;
    
    if (isset($seen[$key])) {
      echo "{$indent}DUPLICATE: $title -> $route (ID: $plugin_id)\n";
      $to_delete[] = $plugin_id;
    } else {
      echo "{$indent}OK: $title -> $route (ID: $plugin_id)\n";
      $seen[$key] = true;
    }
    
    if ($item->hasChildren) {
      check_tree($item->subtree, $seen, $to_delete, $indent . '  ');
    }
  }
}

check_tree($tree, $seen, $to_delete);

echo "\n" . count($to_delete) . " duplicates found.\n";

if (!empty($to_delete)) {
  echo "\nDeleting duplicates...\n";
  $menu_link_manager = \Drupal::service('plugin.manager.menu.link');
  
  foreach ($to_delete as $plugin_id) {
    try {
      // Check if it's a content entity link
      if (strpos($plugin_id, 'menu_link_content:') === 0) {
        $uuid = substr($plugin_id, strlen('menu_link_content:'));
        $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
        $entities = $storage->loadByProperties(['uuid' => $uuid]);
        
        if ($entity = reset($entities)) {
          $entity->delete();
          echo "  ✅ Deleted: " . $entity->getTitle() . "\n";
        }
      }
    } catch (\Exception $e) {
      echo "  ❌ Error deleting $plugin_id: " . $e->getMessage() . "\n";
    }
  }
  
  // Rebuild menu
  $menu_link_manager->rebuild();
  echo "\n✅ Menu links rebuilt!\n";
}

echo "\nDone!\n";
