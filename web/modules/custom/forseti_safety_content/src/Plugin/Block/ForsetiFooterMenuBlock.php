<?php

namespace Drupal\forseti_safety_content\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Footer Menu' Block.
 *
 * @Block(
 *   id = "forseti_footer_menu",
 *   admin_label = @Translation("Forseti Footer Menu"),
 *   category = @Translation("Forseti"),
 * )
 */
class ForsetiFooterMenuBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The menu link tree service.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $menuTree;

  /**
   * Constructs a new ForsetiFooterMenuBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menu_tree
   *   The menu tree service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MenuLinkTreeInterface $menu_tree) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->menuTree = $menu_tree;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('menu.link_tree')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $menu_name = 'main';
    $parameters = new MenuTreeParameters();
    $parameters->setMaxDepth(1);
    $parameters->onlyEnabledLinks();

    $tree = $this->menuTree->load($menu_name, $parameters);
    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $this->menuTree->transform($tree, $manipulators);
    $menu = $this->menuTree->build($tree);

    return [
      '#theme' => 'forseti_footer_block',
      '#menu_items' => $menu['#items'] ?? [],
      '#current_year' => date('Y'),
      '#cache' => [
        'contexts' => ['url.path'],
        'tags' => ['config:system.menu.' . $menu_name],
      ],
    ];
  }

}
