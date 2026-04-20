<?php

namespace Drupal\agent_evaluation\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for parsing AI responses and extracting field values.
 */
class FieldUpdateParser {

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new FieldUpdateParser.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('agent_evaluation');
  }

  /**
   * Extracts field values from AI response containing JSON.
   *
   * @param string $response
   *   The AI response text.
   *
   * @return array|null
   *   Array of field names => values, or NULL if no valid JSON found.
   */
  public function extractFieldValues($response) {
    // Look for JSON block in response (between ```json and ``` or just {...})
    $patterns = [
      '/```json\s*(\{[^`]+\})\s*```/s',  // Markdown code block
      '/(\{[\s\S]*?"field_sub_[^}]+\})/s', // Plain JSON object with field_sub_
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $response, $matches)) {
        $json_string = $matches[1];
        
        try {
          $data = json_decode($json_string, TRUE, 512, JSON_THROW_ON_ERROR);
          
          if (is_array($data) && $this->validateFieldData($data)) {
            $this->logger->info('Successfully extracted field values from AI response: @count fields', [
              '@count' => count($data),
            ]);
            return $data;
          }
        }
        catch (\JsonException $e) {
          $this->logger->warning('Failed to parse JSON from AI response: @message', [
            '@message' => $e->getMessage(),
          ]);
          continue;
        }
      }
    }

    $this->logger->debug('No valid JSON field data found in AI response');
    return NULL;
  }

  /**
   * Validates that field data contains expected field names.
   *
   * @param array $data
   *   The decoded JSON data.
   *
   * @return bool
   *   TRUE if data appears valid.
   */
  protected function validateFieldData(array $data) {
    // Check if at least some sub-dimension fields are present
    $sub_dimension_count = 0;
    foreach ($data as $key => $value) {
      if (strpos($key, 'field_sub_') === 0) {
        $sub_dimension_count++;
      }
    }

    // Require at least 10 sub-dimension fields to consider it valid
    return $sub_dimension_count >= 10;
  }

  /**
   * Gets all expected sub-dimension field names.
   *
   * @return array
   *   Array of field names.
   */
  public function getSubDimensionFields() {
    return [
      'field_sub_scope',
      'field_sub_restriction',
      'field_sub_classification',
      'field_sub_temporal',
      'field_sub_sources',
      'field_sub_granularity',
      'field_sub_computational',
      'field_sub_financial',
      'field_sub_data_storage',
      'field_sub_network_bandwidth',
      'field_sub_api_access',
      'field_sub_human',
      'field_sub_legal',
      'field_sub_institutional',
      'field_sub_budget_auth',
      'field_sub_policy',
      'field_sub_override',
      'field_sub_audit',
      'field_sub_connectivity',
      'field_sub_centrality',
      'field_sub_trust_reputation',
      'field_sub_info_flow',
      'field_sub_coalition',
      'field_sub_network_effects',
      'field_sub_reasoning',
      'field_sub_creativity',
      'field_sub_planning',
      'field_sub_learning',
      'field_sub_memory',
      'field_sub_execution',
    ];
  }

}
