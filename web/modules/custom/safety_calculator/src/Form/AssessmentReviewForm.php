<?php

namespace Drupal\safety_calculator\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\safety_calculator\Entity\SafetyAssessment;

/**
 * Review form for completed safety assessment questionnaire.
 */
class AssessmentReviewForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'safety_calculator_assessment_review';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Load responses from session
    $tempstore = \Drupal::service('tempstore.private')->get('safety_calculator');
    $responses = $tempstore->get('questionnaire_responses') ?? [];

    if (empty($responses)) {
      $this->messenger()->addError($this->t('No assessment data found. Please complete the questionnaire first.'));
      return $form;
    }

    $form['#prefix'] = '<div class="container my-5"><div class="card card-forseti p-4">';
    $form['#suffix'] = '</div></div>';

    // Header
    $form['header'] = [
      '#markup' => '<h2 class="text-center mb-4">' . $this->t('Review Your Assessment') . '</h2>
        <p class="lead text-center mb-4">' . $this->t('Please review your responses before submitting.') . '</p>',
    ];

    // Group responses by dimension
    $dimensions = $this->getDimensions();
    
    foreach ($dimensions as $dimension_key => $dimension_info) {
      $dimension_responses = $this->getDimensionResponses($responses, $dimension_key);
      
      if (!empty($dimension_responses)) {
        $form[$dimension_key . '_section'] = [
          '#type' => 'details',
          '#title' => $dimension_info['name'],
          '#open' => FALSE,
          '#attributes' => ['class' => ['mb-3']],
        ];

        $items = [];
        foreach ($dimension_responses as $key => $value) {
          if (strpos($key, '_rating') !== FALSE) {
            $question_key = str_replace('_rating', '', $key);
            $question_label = $this->getQuestionLabel($dimension_key, $question_key);
            $rating = $value;
            $comment = $responses[$question_key . '_comment'] ?? '';
            
            $item_text = '<strong>' . $question_label . '</strong><br>';
            $item_text .= 'Rating: ' . $rating . '/10';
            if (!empty($comment)) {
              $item_text .= '<br><em>' . htmlspecialchars($comment) . '</em>';
            }
            
            $items[] = $item_text;
          }
        }

        if (!empty($items)) {
          $form[$dimension_key . '_section']['responses'] = [
            '#theme' => 'item_list',
            '#items' => $items,
            '#attributes' => ['class' => ['list-unstyled']],
          ];
        }
      }
    }

    // Calculate preview scores
    $scores = $this->calculateScores($responses);
    
    $form['scores'] = [
      '#type' => 'details',
      '#title' => $this->t('Calculated Scores'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['mb-4', 'bg-light', 'p-3', 'rounded']],
    ];

    $score_items = [];
    foreach ($dimensions as $dimension_key => $dimension_info) {
      if (isset($scores[$dimension_key])) {
        $score_items[] = '<strong>' . $dimension_info['name'] . ':</strong> ' . 
          number_format($scores[$dimension_key], 1) . '/10';
      }
    }
    
    if (!empty($scores['overall'])) {
      $score_items[] = '<hr><strong class="text-primary">' . $this->t('Overall Safety Score') . ':</strong> ' . 
        number_format($scores['overall'], 1) . '/10';
    }

    $form['scores']['score_list'] = [
      '#theme' => 'item_list',
      '#items' => $score_items,
      '#attributes' => ['class' => ['list-unstyled']],
    ];

    // Actions
    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['d-flex', 'justify-content-between', 'mt-4']],
    ];

    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('← Back to Edit'),
      '#url' => Url::fromRoute('safety_calculator.questionnaire_step', ['step' => 'whole']),
      '#attributes' => ['class' => ['btn', 'btn-secondary']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit Assessment'),
      '#attributes' => ['class' => ['btn', 'btn-success', 'btn-lg']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Load responses from session
    $tempstore = \Drupal::service('tempstore.private')->get('safety_calculator');
    $responses = $tempstore->get('questionnaire_responses') ?? [];

    if (empty($responses)) {
      $this->messenger()->addError($this->t('No assessment data found.'));
      return;
    }

    // Calculate scores
    $scores = $this->calculateScores($responses);

    // Create SafetyAssessment entity
    $assessment = SafetyAssessment::create([
      'user_id' => \Drupal::currentUser()->id(),
      'completed' => TRUE,
      'overall_score' => $scores['overall'] ?? 0,
    ]);

    // Store dimension responses and scores
    $dimensions = ['safe', 'energized', 'connected', 'free', 'capable', 'useful', 'whole'];
    foreach ($dimensions as $dimension) {
      $dimension_responses = $this->getDimensionResponses($responses, $dimension);
      if (!empty($dimension_responses)) {
        $assessment->set($dimension . '_responses', json_encode($dimension_responses));
        $assessment->set($dimension . '_score', $scores[$dimension] ?? 0);
      }
    }

    try {
      $assessment->save();
      
      // Clear the session data
      $tempstore->delete('questionnaire_responses');
      
      $this->messenger()->addStatus($this->t('Your safety assessment has been submitted successfully!'));
      
      // Redirect to results page
      $form_state->setRedirect('safety_calculator.landing');
      
    } catch (\Exception $e) {
      \Drupal::logger('safety_calculator')->error('Failed to save assessment: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('There was an error saving your assessment. Please try again.'));
    }
  }

  /**
   * Get dimension information.
   */
  protected function getDimensions() {
    return [
      'safe' => ['name' => $this->t('Safe - Security & Protection')],
      'energized' => ['name' => $this->t('Energized - Vitality & Basic Needs')],
      'connected' => ['name' => $this->t('Connected - Community & Belonging')],
      'free' => ['name' => $this->t('Free - Autonomy & Rights')],
      'capable' => ['name' => $this->t('Capable - Mastery & Development')],
      'useful' => ['name' => $this->t('Useful - Purpose & Contribution')],
      'whole' => ['name' => $this->t('Whole - Holistic Health & Identity')],
    ];
  }

  /**
   * Get responses for a specific dimension.
   */
  protected function getDimensionResponses($responses, $dimension) {
    $dimension_responses = [];
    $questions = $this->getQuestionsForDimension($dimension);
    
    foreach ($questions as $question_key => $question_info) {
      $rating_key = $question_key . '_rating';
      $comment_key = $question_key . '_comment';
      
      if (isset($responses[$rating_key])) {
        $dimension_responses[$rating_key] = $responses[$rating_key];
      }
      if (isset($responses[$comment_key]) && !empty($responses[$comment_key])) {
        $dimension_responses[$comment_key] = $responses[$comment_key];
      }
    }
    
    return $dimension_responses;
  }

  /**
   * Calculate scores for all dimensions.
   */
  protected function calculateScores($responses) {
    $scores = [];
    $dimensions = ['safe', 'energized', 'connected', 'free', 'capable', 'useful', 'whole'];
    
    $total_score = 0;
    $dimension_count = 0;
    
    foreach ($dimensions as $dimension) {
      $dimension_responses = $this->getDimensionResponses($responses, $dimension);
      $ratings = [];
      
      foreach ($dimension_responses as $key => $value) {
        if (strpos($key, '_rating') !== FALSE && is_numeric($value)) {
          $ratings[] = (float) $value;
        }
      }
      
      if (!empty($ratings)) {
        $scores[$dimension] = array_sum($ratings) / count($ratings);
        $total_score += $scores[$dimension];
        $dimension_count++;
      }
    }
    
    if ($dimension_count > 0) {
      $scores['overall'] = $total_score / $dimension_count;
    }
    
    return $scores;
  }

  /**
   * Get question label by key.
   */
  protected function getQuestionLabel($dimension, $question_key) {
    $questions = $this->getQuestionsForDimension($dimension);
    return $questions[$question_key]['question'] ?? $question_key;
  }

  /**
   * Get questions for a dimension (stub - should match QuestionnaireStepForm).
   */
  protected function getQuestionsForDimension($dimension) {
    // This should ideally be moved to a service for reusability
    // For now, return the questions from the step form
    $step_form = new \Drupal\safety_calculator\Form\QuestionnaireStepForm();
    $reflection = new \ReflectionClass($step_form);
    $method = $reflection->getMethod('getQuestionsForDimension');
    $method->setAccessible(TRUE);
    return $method->invoke($step_form, $dimension);
  }

}
