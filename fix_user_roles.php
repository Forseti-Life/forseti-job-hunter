#!/usr/bin/env php
<?php

use Drupal\user\Entity\User;

// Bootstrap Drupal
$autoloader = require __DIR__ . '/web/autoload.php';
$kernel = \Drupal\Core\DrupalKernel::createFromRequest(
  \Symfony\Component\HttpFoundation\Request::createFromGlobals(),
  $autoloader,
  'prod'
);
$kernel->boot();
$kernel->prepareLegacyRequest(\Symfony\Component\HttpFoundation\Request::createFromGlobals());

$validation_users = [
  2 => ['username' => 'firefighter_active', 'role' => 'firefighter'],
  3 => ['username' => 'firefighter_retired', 'role' => 'firefighter'],
  4 => ['username' => 'nfr_admin', 'role' => 'nfr_administrator'],
  5 => ['username' => 'nfr_researcher', 'role' => 'nfr_researcher'],
  6 => ['username' => 'dept_admin', 'role' => 'fire_dept_admin'],
];

$fixed = 0;
foreach ($validation_users as $uid => $user_data) {
  $user = User::load($uid);
  if ($user) {
    echo "User $uid (" . $user->getAccountName() . "): ";
    echo "Roles: " . implode(', ', $user->getRoles()) . PHP_EOL;
    if (!$user->hasRole($user_data['role'])) {
      $user->addRole($user_data['role']);
      $user->save();
      $fixed++;
      echo "  -> Added role: " . $user_data['role'] . PHP_EOL;
    } else {
      echo "  -> Already has role" . PHP_EOL;
    }
  } else {
    echo "User $uid does not exist" . PHP_EOL;
  }
}
echo "Fixed $fixed users" . PHP_EOL;
