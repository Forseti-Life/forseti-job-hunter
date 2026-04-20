<?php

namespace Drupal\community_incident_report\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

/**
 * Community incident report submission form.
 */
class CommunityIncidentReportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'community_incident_report_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Brief title'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#placeholder' => $this->t('e.g. Broken streetlight near 5th & Oak'),
    ];

    $form['field_ci_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#required' => TRUE,
      '#rows' => 5,
      '#placeholder' => $this->t('Describe what you observed.'),
    ];

    // Load incident_type terms for select list.
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'incident_type']);
    $options = [];
    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }

    $form['field_ci_incident_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Incident Type'),
      '#required' => TRUE,
      '#options' => $options,
      '#empty_option' => $this->t('-- Select type --'),
    ];

    $form['field_ci_location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Location / Address'),
      '#required' => FALSE,
      '#maxlength' => 255,
      '#placeholder' => $this->t('Street address or cross streets'),
    ];

    $form['field_ci_occurred_at'] = [
      '#type' => 'datetime',
      '#title' => $this->t('When did this occur?'),
      '#required' => FALSE,
      '#date_date_element' => 'date',
      '#date_time_element' => 'time',
    ];

    $form['field_ci_photo'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Photo (optional)'),
      '#description' => $this->t('Max 5 MB. Accepted: jpg, jpeg, png, gif, webp.'),
      '#required' => FALSE,
      '#upload_location' => 'public://community-incidents/',
      '#upload_validators' => [
        'file_validate_extensions' => ['jpg jpeg png gif webp'],
        'file_validate_size' => [5 * 1024 * 1024],
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit Report'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = \Drupal::currentUser()->id();
    $values = $form_state->getValues();

    $node_values = [
      'type' => 'community_incident',
      'title' => strip_tags((string) $values['title']),
      'status' => 0,
      'uid' => $uid,
      'field_ci_description' => ['value' => strip_tags((string) $values['field_ci_description']), 'format' => 'plain_text'],
      'field_ci_incident_type' => ['target_id' => (int) $values['field_ci_incident_type']],
    ];

    if (!empty($values['field_ci_location'])) {
      $node_values['field_ci_location'] = strip_tags((string) $values['field_ci_location']);
    }
    if (!empty($values['field_ci_occurred_at'])) {
      $node_values['field_ci_occurred_at'] = ['value' => $values['field_ci_occurred_at']->format('Y-m-d\TH:i:s')];
    }
    if (!empty($values['field_ci_photo'])) {
      $node_values['field_ci_photo'] = ['target_id' => reset($values['field_ci_photo'])];
      // Mark uploaded file as permanent.
      $fid = reset($values['field_ci_photo']);
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
      if ($file) {
        $file->setPermanent();
        $file->save();
      }
    }

    try {
      $node = Node::create($node_values);
      $node->save();
      // SEC-5: log only uid and nid.
      \Drupal::logger('community_incident_report')->info('Incident submitted: uid=@uid nid=@nid', [
        '@uid' => $uid,
        '@nid' => $node->id(),
      ]);
      $this->messenger()->addStatus($this->t('Thank you — your report has been submitted and will appear after review.'));
    }
    catch (\Exception $e) {
      \Drupal::logger('community_incident_report')->error('Incident submit failed: uid=@uid error=@error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('There was a problem submitting your report. Please try again.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $form_state->setRedirect('<front>');
  }

}
