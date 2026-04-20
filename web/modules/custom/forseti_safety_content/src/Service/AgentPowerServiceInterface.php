<?php

namespace Drupal\forseti_safety_content\Service;

/**
 * Interface for Agent Power Service.
 */
interface AgentPowerServiceInterface {

  /**
   * Get power levels data.
   *
   * @return array
   *   Array of power level definitions.
   */
  public function getPowerLevels();

  /**
   * Get dimension info.
   *
   * @return array
   *   Array of dimension information.
   */
  public function getDimensionInfo();

  /**
   * Get power categories.
   *
   * @return array
   *   Array of power category definitions.
   */
  public function getPowerCategories();

  /**
   * Get scope dimension levels.
   *
   * @return array
   *   Array of scope levels.
   */
  public function getScopeLevels();

  /**
   * Get restriction dimension levels.
   *
   * @return array
   *   Array of restriction levels.
   */
  public function getRestrictionLevels();

  /**
   * Get classification dimension levels.
   *
   * @return array
   *   Array of classification levels.
   */
  public function getClassificationLevels();

  /**
   * Get temporal dimension levels.
   *
   * @return array
   *   Array of temporal levels.
   */
  public function getTemporalLevels();

  /**
   * Get sources dimension levels.
   *
   * @return array
   *   Array of sources levels.
   */
  public function getSourcesLevels();

  /**
   * Get granularity dimension levels.
   *
   * @return array
   *   Array of granularity levels.
   */
  public function getGranularityLevels();

  /**
   * Get authority dimension levels.
   *
   * @return array
   *   Array of authority levels.
   */
  public function getAuthorityLevels();

  /**
   * Get synthesis dimension levels.
   *
   * @return array
   *   Array of synthesis levels.
   */
  public function getSynthesisLevels();

  /**
   * Get creativity dimension levels.
   *
   * @return array
   *   Array of creativity levels.
   */
  public function getCreativityLevels();

  /**
   * Get strategic planning dimension levels.
   *
   * @return array
   *   Array of strategic planning levels.
   */
  public function getStrategicPlanningLevels();

  /**
   * Get decision quality dimension levels.
   *
   * @return array
   *   Array of decision quality levels.
   */
  public function getDecisionQualityLevels();

  /**
   * Get adaptive learning dimension levels.
   *
   * @return array
   *   Array of adaptive learning levels.
   */
  public function getAdaptiveLearningLevels();

  /**
   * Get memory architecture dimension levels.
   *
   * @return array
   *   Array of memory architecture levels.
   */
  public function getMemoryArchitectureLevels();

  /**
   * Get verification dimension levels.
   *
   * @return array
   *   Array of verification levels.
   */
  public function getVerificationLevels();

  /**
   * Get computational resources dimension levels.
   *
   * @return array
   *   Array of computational resources levels.
   */
  public function getComputationalResourcesLevels();

  /**
   * Get financial capital dimension levels.
   *
   * @return array
   *   Array of financial capital levels.
   */
  public function getFinancialCapitalLevels();

  /**
   * Get infrastructure access dimension levels.
   *
   * @return array
   *   Array of infrastructure access levels.
   */
  public function getInfrastructureAccessLevels();

  /**
   * Get human capital dimension levels.
   *
   * @return array
   *   Array of human capital levels.
   */
  public function getHumanCapitalLevels();

  /**
   * Get energy resources dimension levels.
   *
   * @return array
   *   Array of energy resources levels.
   */
  public function getEnergyResourcesLevels();

  /**
   * Get time allocation dimension levels.
   *
   * @return array
   *   Array of time allocation levels.
   */
  public function getTimeAllocationLevels();

  /**
   * Get trust network depth dimension levels.
   *
   * @return array
   *   Array of trust network depth levels.
   */
  public function getTrustNetworkDepthLevels();

  /**
   * Get dependency relationships dimension levels.
   *
   * @return array
   *   Array of dependency relationships levels.
   */
  public function getDependencyRelationshipsLevels();

  /**
   * Get gatekeeping power dimension levels.
   *
   * @return array
   *   Array of gatekeeping power levels.
   */
  public function getGatekeepingPowerLevels();

  /**
   * Get influence reach dimension levels.
   *
   * @return array
   *   Array of influence reach levels.
   */
  public function getInfluenceReachLevels();

  /**
   * Get reputation capital dimension levels.
   *
   * @return array
   *   Array of reputation capital levels.
   */
  public function getReputationCapitalLevels();

  /**
   * Get mobilization capability dimension levels.
   *
   * @return array
   *   Array of mobilization capability levels.
   */
  public function getMobilizationCapabilityLevels();

  /**
   * Get legal authorization dimension levels.
   *
   * @return array
   *   Array of legal authorization levels.
   */
  public function getLegalAuthorizationLevels();

  /**
   * Get decision making scope dimension levels.
   *
   * @return array
   *   Array of decision making scope levels.
   */
  public function getDecisionMakingScopeLevels();

  /**
   * Get budget authority dimension levels.
   *
   * @return array
   *   Array of budget authority levels.
   */
  public function getBudgetAuthorityLevels();

  /**
   * Get jurisdictional reach dimension levels.
   *
   * @return array
   *   Array of jurisdictional reach levels.
   */
  public function getJurisdictionalReachLevels();

  /**
   * Get enforcement power dimension levels.
   *
   * @return array
   *   Array of enforcement power levels.
   */
  public function getEnforcementPowerLevels();

  /**
   * Get moral authority dimension levels.
   *
   * @return array
   *   Array of moral authority levels.
   */
  public function getMoralAuthorityLevels();

  /**
   * Get entity evaluations.
   *
   * @return array
   *   Array of entity evaluation data.
   */
  public function getEntityEvaluations();

  /**
   * Get all dimensions list.
   *
   * @return array
   *   Array of all dimensions organized by category.
   */
  public function getAllDimensionsList();

}
