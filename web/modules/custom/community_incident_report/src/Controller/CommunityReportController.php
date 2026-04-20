<?php

namespace Drupal\community_incident_report\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for community incident report listing and admin pages.
 */
class CommunityReportController extends ControllerBase {

  /**
   * Public listing page — AC-3.
   */
  public function listing(): array {
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'community_incident')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->pager(20)
      ->accessCheck(FALSE)
      ->execute();

    $nodes = $nids ? \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids) : [];

    $rows = [];
    foreach ($nodes as $node) {
      $type_label = '';
      $type_field = $node->get('field_ci_incident_type');
      if (!$type_field->isEmpty()) {
        $term = $type_field->entity;
        if ($term) {
          $type_label = htmlspecialchars($term->label());
        }
      }
      $location = $node->get('field_ci_location')->value ?? '';
      $occurred = '';
      $occurred_field = $node->get('field_ci_occurred_at');
      if (!$occurred_field->isEmpty()) {
        $occurred = htmlspecialchars(substr($occurred_field->value, 0, 10));
      }
      $rows[] = [
        'title' => htmlspecialchars($node->getTitle()),
        'type' => $type_label,
        'location' => htmlspecialchars($location),
        'occurred' => $occurred,
        'created' => date('Y-m-d', $node->getCreatedTime()),
      ];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['community-reports-listing']],
    ];

    if (empty($rows)) {
      $build['empty'] = ['#markup' => '<p>No community safety reports have been published yet.</p>'];
    }
    else {
      $thead = '<thead><tr><th>Title</th><th>Type</th><th>Location</th><th>Occurred</th><th>Reported</th></tr></thead>';
      $tbody = '<tbody>';
      foreach ($rows as $row) {
        $tbody .= '<tr>'
          . '<td>' . $row['title'] . '</td>'
          . '<td>' . $row['type'] . '</td>'
          . '<td>' . $row['location'] . '</td>'
          . '<td>' . $row['occurred'] . '</td>'
          . '<td>' . $row['created'] . '</td>'
          . '</tr>';
      }
      $tbody .= '</tbody>';
      $build['table'] = [
        '#markup' => '<table class="community-reports-table">' . $thead . $tbody . '</table>',
      ];
      $build['pager'] = ['#type' => 'pager'];
    }

    return $build;
  }

  /**
   * Admin moderation listing — AC-6.
   */
  public function adminListing(): array {
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'community_incident')
      ->sort('created', 'DESC')
      ->pager(50)
      ->accessCheck(FALSE)
      ->execute();

    $nodes = $nids ? \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids) : [];

    $build = [
      '#type' => 'container',
      '#attached' => [
        'html_head' => [[
          [
            '#type' => 'html_tag',
            '#tag' => 'style',
            '#value' => '
              .ci-admin-table { width: 100%; border-collapse: collapse; }
              .ci-admin-table th, .ci-admin-table td { padding: 6px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
              .ci-admin-table tr:nth-child(even) { background: #f7fafc; }
              .ci-status-published { color: green; font-weight: bold; }
              .ci-status-pending { color: #b7791f; font-weight: bold; }
            ',
          ],
          'community_incident_admin_styles',
        ]],
      ],
    ];

    if (empty($nodes)) {
      $build['empty'] = ['#markup' => '<p>No community incident reports found.</p>'];
      return $build;
    }

    $csrf_token = \Drupal::csrfToken();
    $rows_html = '';
    foreach ($nodes as $node) {
      $nid = (int) $node->id();
      $status = $node->isPublished();
      $status_label = $status ? '<span class="ci-status-published">Published</span>' : '<span class="ci-status-pending">Pending</span>';
      $action_label = $status ? 'Unpublish' : 'Publish';
      $toggle_url = '/admin/content/community-reports/' . $nid . '/toggle';
      $token = $csrf_token->get('admin/content/community-reports/' . $nid . '/toggle');

      $rows_html .= '<tr>'
        . '<td>' . $nid . '</td>'
        . '<td>' . htmlspecialchars($node->getTitle()) . '</td>'
        . '<td>' . date('Y-m-d', $node->getCreatedTime()) . '</td>'
        . '<td>' . $status_label . '</td>'
        . '<td>'
          . '<button type="button" class="button ci-toggle-btn" data-url="' . htmlspecialchars($toggle_url) . '" data-token="' . htmlspecialchars($token) . '">'
          . htmlspecialchars($action_label)
          . '</button>'
        . '</td>'
        . '</tr>';
    }

    $build['table'] = [
      '#markup' => '<table class="ci-admin-table"><thead><tr><th>ID</th><th>Title</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead><tbody>' . $rows_html . '</tbody></table>',
    ];
    $build['pager'] = ['#type' => 'pager'];

    $build['script'] = [
      '#markup' => '<script>
(function() {
  document.querySelectorAll(".ci-toggle-btn").forEach(function(btn) {
    btn.addEventListener("click", function() {
      var url = btn.getAttribute("data-url");
      var token = btn.getAttribute("data-token");
      fetch(url + "?token=" + encodeURIComponent(token), {
        method: "POST",
        headers: {"Content-Type": "application/json"}
      }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.status) {
          location.reload();
        } else {
          alert("Toggle failed: " + (d.error || "unknown error"));
        }
      }).catch(function() { alert("Request failed."); });
    });
  });
})();
</script>',
    ];

    return $build;
  }

  /**
   * Toggle publish/unpublish — AC-6 one-click toggle.
   */
  public function togglePublish(int $nid): JsonResponse {
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if (!$node || $node->bundle() !== 'community_incident') {
      return new JsonResponse(['error' => 'Not found.'], 404);
    }
    $new_status = $node->isPublished() ? 0 : 1;
    $node->setPublished((bool) $new_status);
    $node->save();
    \Drupal::logger('community_incident_report')->info('Toggle publish: nid=@nid status=@status', [
      '@nid' => $nid,
      '@status' => $new_status,
    ]);
    return new JsonResponse(['status' => 'ok', 'published' => (bool) $new_status]);
  }

  /**
   * GeoJSON endpoint for AmISafe map layer — AC-4.
   */
  public function geojson(): JsonResponse {
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'community_incident')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, 500)
      ->accessCheck(FALSE)
      ->execute();

    $nodes = $nids ? \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids) : [];

    $features = [];
    foreach ($nodes as $node) {
      $location = $node->get('field_ci_location')->value ?? '';
      $type_label = '';
      $type_field = $node->get('field_ci_incident_type');
      if (!$type_field->isEmpty() && $type_field->entity) {
        $type_label = $type_field->entity->label();
      }
      // SEC-4: expose only safe display fields; no full description.
      $features[] = [
        'type' => 'Feature',
        'geometry' => NULL,
        'properties' => [
          'nid' => (int) $node->id(),
          'title' => $node->getTitle(),
          'type' => $type_label,
          'location' => $location,
          'created' => date('Y-m-d', $node->getCreatedTime()),
        ],
      ];
    }

    $response = new JsonResponse([
      'type' => 'FeatureCollection',
      'features' => $features,
    ]);
    $response->headers->set('Cache-Control', 'public, max-age=300');
    return $response;
  }

}
