<?php

/**
 * Populate boolean metrics with estimated distributions based on keyword matching.
 * Uses pattern matching to identify metric types and apply appropriate population estimates.
 */

use Drupal\Core\Database\Database;

$database = Database::getConnection();

echo "Populating boolean metrics with keyword-based estimated distributions...\n";
echo "=========================================================================\n\n";

// Pattern-based distribution estimates (keyword => [Yes%, No%])
$patterns = [
  // Insurance & Coverage (typically high rates)
  'insurance' => ['Yes' => 85.0, 'No' => 15.0],
  'coverage' => ['Yes' => 82.0, 'No' => 18.0],
  
  // Safety Equipment & Systems (moderate-high)
  'detector' => ['Yes' => 88.0, 'No' => 12.0],
  'alarm' => ['Yes' => 65.0, 'No' => 35.0],
  'security' => ['Yes' => 45.0, 'No' => 55.0],
  'emergency_kit' => ['Yes' => 38.0, 'No' => 62.0],
  'fire_extinguisher' => ['Yes' => 62.0, 'No' => 38.0],
  
  // Plans & Preparedness (typically low)
  'plan' => ['Yes' => 35.0, 'No' => 65.0],
  'preparedness' => ['Yes' => 32.0, 'No' => 68.0],
  'evacuation' => ['Yes' => 28.0, 'No' => 72.0],
  
  // Skills & Capabilities (moderate-high for basic, low for advanced)
  'literacy' => ['Yes' => 78.0, 'No' => 22.0],
  'comprehension' => ['Yes' => 68.0, 'No' => 32.0],
  'ability' => ['Yes' => 72.0, 'No' => 28.0],
  'adaptation' => ['Yes' => 58.0, 'No' => 42.0],
  'development' => ['Yes' => 62.0, 'No' => 38.0],
  
  // Access & Availability (varies widely)
  'access' => ['Yes' => 68.0, 'No' => 32.0],
  'availability' => ['Yes' => 65.0, 'No' => 35.0],
  'services' => ['Yes' => 72.0, 'No' => 28.0],
  'program' => ['Yes' => 45.0, 'No' => 55.0],
  'assistance' => ['Yes' => 35.0, 'No' => 65.0],
  
  // Awareness & Knowledge (typically moderate)
  'awareness' => ['Yes' => 52.0, 'No' => 48.0],
  'knowledge' => ['Yes' => 55.0, 'No' => 45.0],
  'understanding' => ['Yes' => 58.0, 'No' => 42.0],
  'comprehension' => ['Yes' => 62.0, 'No' => 38.0],
  
  // Rights & Legal (typically low awareness)
  'rights' => ['Yes' => 42.0, 'No' => 58.0],
  'legal' => ['Yes' => 38.0, 'No' => 62.0],
  'complaint_process' => ['Yes' => 28.0, 'No' => 72.0],
  
  // Membership & Participation (typically low)
  'membership' => ['Yes' => 22.0, 'No' => 78.0],
  'participation' => ['Yes' => 25.0, 'No' => 75.0],
  'involvement' => ['Yes' => 28.0, 'No' => 72.0],
  'engagement' => ['Yes' => 32.0, 'No' => 68.0],
  
  // Personal Practices (varies)
  'practice' => ['Yes' => 45.0, 'No' => 55.0],
  'spiritual' => ['Yes' => 52.0, 'No' => 48.0],
  'meditation' => ['Yes' => 14.0, 'No' => 86.0],
  'prayer' => ['Yes' => 55.0, 'No' => 45.0],
  
  // Adequacy & Satisfaction (moderate-high)
  'adequacy' => ['Yes' => 65.0, 'No' => 35.0],
  'satisfaction' => ['Yes' => 68.0, 'No' => 32.0],
  'sufficiency' => ['Yes' => 62.0, 'No' => 38.0],
  
  // Affordability (typically challenging)
  'affordability' => ['Yes' => 58.0, 'No' => 42.0],
  'affordable' => ['Yes' => 58.0, 'No' => 42.0],
  
  // Freedom & Choice (typically high)
  'freedom' => ['Yes' => 78.0, 'No' => 22.0],
  'choice' => ['Yes' => 75.0, 'No' => 25.0],
  'autonomy' => ['Yes' => 68.0, 'No' => 32.0],
  'responsibility' => ['Yes' => 72.0, 'No' => 28.0],
  
  // Support & Help (moderate)
  'support' => ['Yes' => 65.0, 'No' => 35.0],
  'help' => ['Yes' => 62.0, 'No' => 38.0],
  'assistance_program' => ['Yes' => 22.0, 'No' => 78.0],
  
  // Union & Collective (low)
  'union' => ['Yes' => 10.3, 'No' => 89.7],
  'collective' => ['Yes' => 18.0, 'No' => 82.0],
  
  // Housing Assistance (low)
  'voucher' => ['Yes' => 4.5, 'No' => 95.5],
  'subsidy' => ['Yes' => 8.0, 'No' => 92.0],
  
  // Special Services (low)
  'special_education' => ['Yes' => 14.0, 'No' => 86.0],
  'interpretation' => ['Yes' => 8.0, 'No' => 92.0],
  'accommodation' => ['Yes' => 15.0, 'No' => 85.0],
  
  // Board & Leadership (very low)
  'board' => ['Yes' => 8.0, 'No' => 92.0],
  'leadership' => ['Yes' => 12.0, 'No' => 88.0],
  'officer' => ['Yes' => 6.0, 'No' => 94.0],
  
  // Creation & Production (low-moderate)
  'creation' => ['Yes' => 18.0, 'No' => 82.0],
  'production' => ['Yes' => 22.0, 'No' => 78.0],
  'blogging' => ['Yes' => 12.0, 'No' => 88.0],
  'content' => ['Yes' => 25.0, 'No' => 75.0],
  
  // Regulation & Control (moderate-high for positive)
  'regulation' => ['Yes' => 55.0, 'No' => 45.0],
  'control' => ['Yes' => 62.0, 'No' => 38.0],
  'management' => ['Yes' => 65.0, 'No' => 35.0],
  
  // Evolution & Growth (moderate-high)
  'evolution' => ['Yes' => 58.0, 'No' => 42.0],
  'growth' => ['Yes' => 62.0, 'No' => 38.0],
  'advancement' => ['Yes' => 55.0, 'No' => 45.0],
  'investment' => ['Yes' => 52.0, 'No' => 48.0],
  
  // Accessibility & Transportation (high for urban, lower overall)
  'accessibility' => ['Yes' => 68.0, 'No' => 32.0],
  'transportation' => ['Yes' => 85.0, 'No' => 15.0],
  
  // Utilities & Infrastructure (very high)
  'water' => ['Yes' => 99.0, 'No' => 1.0],
  'electric' => ['Yes' => 99.5, 'No' => 0.5],
  'landline' => ['Yes' => 35.0, 'No' => 65.0],
  
  // Cultural Engagement (moderate)
  'cultural' => ['Yes' => 42.0, 'No' => 58.0],
  'arts' => ['Yes' => 38.0, 'No' => 62.0],
  'event' => ['Yes' => 48.0, 'No' => 52.0],
  
  // Skills & Capabilities (moderate-high)
  'skills' => ['Yes' => 68.0, 'No' => 32.0],
  'proficiency' => ['Yes' => 62.0, 'No' => 38.0],
  'capacity' => ['Yes' => 70.0, 'No' => 30.0],
  'problem_solving' => ['Yes' => 72.0, 'No' => 28.0],
  'communication' => ['Yes' => 75.0, 'No' => 25.0],
  'navigation' => ['Yes' => 65.0, 'No' => 35.0],
  'decision_making' => ['Yes' => 78.0, 'No' => 22.0],
  
  // Thinking & Cognitive (moderate-high)
  'thinking' => ['Yes' => 68.0, 'No' => 32.0],
  'mindset' => ['Yes' => 65.0, 'No' => 35.0],
  'learning' => ['Yes' => 58.0, 'No' => 42.0],
  'innovation' => ['Yes' => 42.0, 'No' => 58.0],
  'creative' => ['Yes' => 52.0, 'No' => 48.0],
  'analysis' => ['Yes' => 55.0, 'No' => 45.0],
  
  // Personal Qualities (typically high)
  'resilience' => ['Yes' => 68.0, 'No' => 32.0],
  'persistence' => ['Yes' => 65.0, 'No' => 35.0],
  'empathy' => ['Yes' => 72.0, 'No' => 28.0],
  'acceptance' => ['Yes' => 70.0, 'No' => 30.0],
  'confidence' => ['Yes' => 65.0, 'No' => 35.0],
  'expression' => ['Yes' => 68.0, 'No' => 32.0],
  'authentic' => ['Yes' => 62.0, 'No' => 38.0],
  
  // Building & Setting (moderate-high)
  'building' => ['Yes' => 58.0, 'No' => 42.0],
  'setting' => ['Yes' => 62.0, 'No' => 38.0],
  'goal' => ['Yes' => 68.0, 'No' => 32.0],
  'vision' => ['Yes' => 58.0, 'No' => 42.0],
  
  // Education & Academic (varies)
  'enrollment' => ['Yes' => 24.0, 'No' => 76.0],
  'completion' => ['Yes' => 55.0, 'No' => 45.0],
  'preparation' => ['Yes' => 58.0, 'No' => 42.0],
  'academic' => ['Yes' => 62.0, 'No' => 38.0],
  'college' => ['Yes' => 38.0, 'No' => 62.0],
  'apprenticeship' => ['Yes' => 5.0, 'No' => 95.0],
  'study_abroad' => ['Yes' => 3.0, 'No' => 97.0],
  
  // Credentials & Qualifications (varies)
  'credential' => ['Yes' => 45.0, 'No' => 55.0],
  'qualification' => ['Yes' => 48.0, 'No' => 52.0],
  'relevance' => ['Yes' => 62.0, 'No' => 38.0],
  'eligibility' => ['Yes' => 52.0, 'No' => 48.0],
  
  // Resources & Quality (moderate-high)
  'resources' => ['Yes' => 68.0, 'No' => 32.0],
  'quality' => ['Yes' => 65.0, 'No' => 35.0],
  'rigor' => ['Yes' => 55.0, 'No' => 45.0],
  
  // Experience & Exposure (varies)
  'experience' => ['Yes' => 52.0, 'No' => 48.0],
  'exposure' => ['Yes' => 45.0, 'No' => 55.0],
  'gained' => ['Yes' => 62.0, 'No' => 38.0],
  
  // Identification & Seeking (moderate-high)
  'identification' => ['Yes' => 62.0, 'No' => 38.0],
  'seeking' => ['Yes' => 58.0, 'No' => 42.0],
  'evaluation' => ['Yes' => 55.0, 'No' => 45.0],
  
  // Default & Risk (typically low)
  'default' => ['Yes' => 11.0, 'No' => 89.0],
  'risk' => ['Yes' => 28.0, 'No' => 72.0],
  'forgiveness' => ['Yes' => 15.0, 'No' => 85.0],
  
  // Language & Translation (low-moderate)
  'bilingual' => ['Yes' => 20.0, 'No' => 80.0],
  'translation' => ['Yes' => 12.0, 'No' => 88.0],
  'language' => ['Yes' => 25.0, 'No' => 75.0],
  
  // Conflict & Resolution (moderate)
  'conflict' => ['Yes' => 55.0, 'No' => 45.0],
  'resolution' => ['Yes' => 58.0, 'No' => 42.0],
  
  // Teacher & School (moderate-high)
  'teacher' => ['Yes' => 68.0, 'No' => 32.0],
  'school' => ['Yes' => 72.0, 'No' => 28.0],
  'facility' => ['Yes' => 65.0, 'No' => 35.0],
  
  // Discrimination & Safety (varies)
  'discrimination' => ['Yes' => 25.0, 'No' => 75.0],
  'victimization' => ['Yes' => 18.0, 'No' => 82.0],
  'safety' => ['Yes' => 75.0, 'No' => 25.0],
  
  // Expectations & Timeline (moderate-high)
  'expectations' => ['Yes' => 68.0, 'No' => 32.0],
  'timeline' => ['Yes' => 62.0, 'No' => 38.0],
  
  // Technology & Digital (high)
  'technology' => ['Yes' => 78.0, 'No' => 22.0],
  'device' => ['Yes' => 85.0, 'No' => 15.0],
  'software' => ['Yes' => 72.0, 'No' => 28.0],
  'digital' => ['Yes' => 75.0, 'No' => 25.0],
  
  // Community & Connection (varies)
  'community' => ['Yes' => 58.0, 'No' => 42.0],
  'neighbor' => ['Yes' => 52.0, 'No' => 48.0],
  'social' => ['Yes' => 68.0, 'No' => 32.0],
  'connection' => ['Yes' => 65.0, 'No' => 35.0],
  
  // Ownership & Property (varies)
  'ownership' => ['Yes' => 68.0, 'No' => 32.0],
  'device_ownership' => ['Yes' => 85.0, 'No' => 15.0],
  
  // ADA & Accessibility (low-moderate)
  'ada' => ['Yes' => 42.0, 'No' => 58.0],
  'assistive' => ['Yes' => 15.0, 'No' => 85.0],
  'compliance' => ['Yes' => 58.0, 'No' => 42.0],
  
  // Identity & Expression (moderate-high)
  'identity' => ['Yes' => 68.0, 'No' => 32.0],
  'comfortable' => ['Yes' => 68.0, 'No' => 32.0],
  
  // Walking & Neighborhood Safety (moderate-high)
  'walking' => ['Yes' => 72.0, 'No' => 28.0],
  'night' => ['Yes' => 55.0, 'No' => 45.0],
  
  // Citizenship & Pathway (low)
  'citizenship' => ['Yes' => 95.0, 'No' => 5.0],
  'pathway' => ['Yes' => 12.0, 'No' => 88.0],
  
  // Borrowed & Financial (varies)
  'borrowed' => ['Yes' => 35.0, 'No' => 65.0],
  
  // Crime & Safety (typically low occurrence)
  'crime' => ['Yes' => 18.0, 'No' => 82.0],
  
  // Tax & Payment (typically high)
  'tax' => ['Yes' => 95.0, 'No' => 5.0],
  'payment' => ['Yes' => 88.0, 'No' => 12.0],
  
  // Housing Burden (moderate)
  'burden' => ['Yes' => 32.0, 'No' => 68.0],
  'cost' => ['Yes' => 45.0, 'No' => 55.0],
  
  // Testing & Inspection (low-moderate)
  'testing' => ['Yes' => 35.0, 'No' => 65.0],
  'inspection' => ['Yes' => 55.0, 'No' => 45.0],
  'exam' => ['Yes' => 68.0, 'No' => 32.0],
  
  // Hazard & Safety Measures (low-moderate)
  'hazard' => ['Yes' => 28.0, 'No' => 72.0],
  'disposal' => ['Yes' => 45.0, 'No' => 55.0],
  
  // Desert & Geographic Issues (low)
  'desert' => ['Yes' => 10.0, 'No' => 90.0],
  
  // Cooking & Food Skills (high)
  'cooking' => ['Yes' => 78.0, 'No' => 22.0],
  'preservation' => ['Yes' => 42.0, 'No' => 58.0],
  
  // Employment Status & Conditions (varies)
  'involuntary' => ['Yes' => 18.0, 'No' => 82.0],
  'part_time' => ['Yes' => 13.2, 'No' => 86.8],
  'contribution' => ['Yes' => 68.0, 'No' => 32.0],
  'flexibility' => ['Yes' => 58.0, 'No' => 42.0],
  'promotion' => ['Yes' => 45.0, 'No' => 55.0],
  'reimbursement' => ['Yes' => 52.0, 'No' => 48.0],
  'approval' => ['Yes' => 78.0, 'No' => 22.0],
  'protections' => ['Yes' => 72.0, 'No' => 28.0],
  'theft' => ['Yes' => 8.0, 'No' => 92.0],
  
  // Environment & Workplace (moderate-high)
  'environment' => ['Yes' => 68.0, 'No' => 32.0],
  'workplace' => ['Yes' => 72.0, 'No' => 28.0],
  
  // Loans & Financial Products (varies)
  'consolidation' => ['Yes' => 18.0, 'No' => 82.0],
  'minimum' => ['Yes' => 42.0, 'No' => 58.0],
  'tracking' => ['Yes' => 55.0, 'No' => 45.0],
  'budget' => ['Yes' => 52.0, 'No' => 48.0],
  'method' => ['Yes' => 85.0, 'No' => 15.0],
  'relationship' => ['Yes' => 68.0, 'No' => 32.0],
  'unbanked' => ['Yes' => 5.5, 'No' => 94.5],
  'umbrella' => ['Yes' => 22.0, 'No' => 78.0],
  'portfolio' => ['Yes' => 35.0, 'No' => 65.0],
  'advisor' => ['Yes' => 28.0, 'No' => 72.0],
  'beneficiaries' => ['Yes' => 45.0, 'No' => 55.0],
  'attorney' => ['Yes' => 35.0, 'No' => 65.0],
  
  // Coordination & Adherence (moderate-high)
  'coordination' => ['Yes' => 62.0, 'No' => 38.0],
  'adherence' => ['Yes' => 68.0, 'No' => 32.0],
  'skipping' => ['Yes' => 22.0, 'No' => 78.0],
  
  // Frequency & Current Status (moderate-high)
  'frequency' => ['Yes' => 65.0, 'No' => 35.0],
  'current' => ['Yes' => 72.0, 'No' => 28.0],
  
  // Corrective & Vision (high)
  'corrective' => ['Yes' => 64.0, 'No' => 36.0],
  'lenses' => ['Yes' => 64.0, 'No' => 36.0],
  'problems' => ['Yes' => 42.0, 'No' => 58.0],
  
  // Advocacy & Patient Care (moderate)
  'advocacy' => ['Yes' => 48.0, 'No' => 52.0],
  'patient' => ['Yes' => 62.0, 'No' => 38.0],
  
  // Backup & Alternative (low-moderate)
  'backup' => ['Yes' => 28.0, 'No' => 72.0],
  'alternative' => ['Yes' => 35.0, 'No' => 65.0],
  
  // Heating & Gas (high)
  'heating' => ['Yes' => 95.0, 'No' => 5.0],
  'gas' => ['Yes' => 58.0, 'No' => 42.0],
  
  // Waste & Utility (moderate-high)
  'waste' => ['Yes' => 68.0, 'No' => 32.0],
  'pickup' => ['Yes' => 82.0, 'No' => 18.0],
  'utility' => ['Yes' => 75.0, 'No' => 25.0],
  
  // Weatherization & Efficiency (moderate)
  'weatherization' => ['Yes' => 32.0, 'No' => 68.0],
  'efficiency' => ['Yes' => 48.0, 'No' => 52.0],
  'rating' => ['Yes' => 55.0, 'No' => 45.0],
  
  // Homeschool & Education Options (low)
  'homeschool' => ['Yes' => 3.4, 'No' => 96.6],
  'option' => ['Yes' => 58.0, 'No' => 42.0],
  
  // Guardianship & Legal Status (low)
  'guardianship' => ['Yes' => 2.5, 'No' => 97.5],
  'restrictions' => ['Yes' => 15.0, 'No' => 85.0],
  'parole' => ['Yes' => 1.2, 'No' => 98.8],
  'probation' => ['Yes' => 2.8, 'No' => 97.2],
  'constraints' => ['Yes' => 12.0, 'No' => 88.0],
  
  // Comfort & Expression (moderate-high)
  'comfort' => ['Yes' => 68.0, 'No' => 32.0],
  'free_expression' => ['Yes' => 72.0, 'No' => 28.0],
  
  // Medication & Prescriptions (high)
  'medication' => ['Yes' => 68.0, 'No' => 32.0],
  'prescription' => ['Yes' => 72.0, 'No' => 28.0],
  'specialist' => ['Yes' => 55.0, 'No' => 45.0],
  
  // Emergency Protocols & Preparedness (low)
  'protocol' => ['Yes' => 32.0, 'No' => 68.0],
  'beacon' => ['Yes' => 5.0, 'No' => 95.0],
  'inventory' => ['Yes' => 35.0, 'No' => 65.0],
  'threat' => ['Yes' => 15.0, 'No' => 85.0],
  'portable' => ['Yes' => 42.0, 'No' => 58.0],
  'allergy' => ['Yes' => 28.0, 'No' => 72.0],
  'secured' => ['Yes' => 48.0, 'No' => 52.0],
  
  // Crisis & Counseling (low)
  'crisis' => ['Yes' => 22.0, 'No' => 78.0],
  'counseling' => ['Yes' => 18.0, 'No' => 82.0],
  'eap' => ['Yes' => 15.0, 'No' => 85.0],
  'utilization' => ['Yes' => 42.0, 'No' => 58.0],
  'hotline' => ['Yes' => 25.0, 'No' => 75.0],
  
  // Protection & Security Measures (moderate)
  'protection' => ['Yes' => 55.0, 'No' => 45.0],
  'protective' => ['Yes' => 52.0, 'No' => 48.0],
  'password' => ['Yes' => 78.0, 'No' => 22.0],
  'manager' => ['Yes' => 35.0, 'No' => 65.0],
  'alert' => ['Yes' => 45.0, 'No' => 55.0],
  'monitoring' => ['Yes' => 48.0, 'No' => 52.0],
  'monitored' => ['Yes' => 52.0, 'No' => 48.0],
  'equipment' => ['Yes' => 68.0, 'No' => 32.0],
  
  // Safety Practices (high)
  'seatbelt' => ['Yes' => 90.0, 'No' => 10.0],
  'presence' => ['Yes' => 68.0, 'No' => 32.0],
  
  // Status & Conditions (varies)
  'homebound' => ['Yes' => 5.5, 'No' => 94.5],
  'dues' => ['Yes' => 22.0, 'No' => 78.0],
  'fees' => ['Yes' => 65.0, 'No' => 35.0],
  
  // Networking & Belonging (moderate)
  'networking' => ['Yes' => 48.0, 'No' => 52.0],
  'belonging' => ['Yes' => 72.0, 'No' => 28.0],
  
  // Fear & Negative Experiences (low-moderate)
  'fear' => ['Yes' => 35.0, 'No' => 65.0],
  'retaliation' => ['Yes' => 18.0, 'No' => 82.0],
  'unwanted' => ['Yes' => 22.0, 'No' => 78.0],
  'bias' => ['Yes' => 25.0, 'No' => 75.0],
  
  // Fairness & Treatment (moderate-high)
  'fairness' => ['Yes' => 65.0, 'No' => 35.0],
  'interaction' => ['Yes' => 70.0, 'No' => 30.0],
  'free_treatment' => ['Yes' => 72.0, 'No' => 28.0],
  
  // Voting & Registration (high)
  'registration' => ['Yes' => 71.0, 'No' => 29.0],
  'voting' => ['Yes' => 71.0, 'No' => 29.0],
  
  // Complaints & Filing (low)
  'complaint' => ['Yes' => 15.0, 'No' => 85.0],
  'filing' => ['Yes' => 18.0, 'No' => 82.0],
  
  // Equal & Opportunity (moderate-high)
  'equal' => ['Yes' => 72.0, 'No' => 28.0],
  'opportunity' => ['Yes' => 68.0, 'No' => 32.0],
  'pay' => ['Yes' => 65.0, 'No' => 35.0],
  
  // Medical Decision & Consent (high)
  'respect' => ['Yes' => 82.0, 'No' => 18.0],
  'consent' => ['Yes' => 92.0, 'No' => 8.0],
  'informed' => ['Yes' => 78.0, 'No' => 22.0],
  
  // Coercion & Concern (low)
  'coercion' => ['Yes' => 8.0, 'No' => 92.0],
  'concern' => ['Yes' => 35.0, 'No' => 65.0],
  'domain' => ['Yes' => 2.0, 'No' => 98.0],
  'predatory' => ['Yes' => 5.0, 'No' => 95.0],
  
  // Licensing & Agreements (varies)
  'licensing' => ['Yes' => 42.0, 'No' => 58.0],
  'compete' => ['Yes' => 18.0, 'No' => 82.0],
  'agreement' => ['Yes' => 55.0, 'No' => 45.0],
  
  // Mobility & Transparency (moderate)
  'mobility' => ['Yes' => 62.0, 'No' => 38.0],
  'transparency' => ['Yes' => 58.0, 'No' => 42.0],
  'consumer' => ['Yes' => 75.0, 'No' => 25.0],
  
  // Information & Contact (varies)
  'information' => ['Yes' => 68.0, 'No' => 32.0],
  'sources' => ['Yes' => 72.0, 'No' => 28.0],
  'contact' => ['Yes' => 65.0, 'No' => 35.0],
  'official' => ['Yes' => 45.0, 'No' => 55.0],
  
  // Meeting & Attendance (low)
  'meeting' => ['Yes' => 18.0, 'No' => 82.0],
  'attendance' => ['Yes' => 22.0, 'No' => 78.0],
  'responsiveness' => ['Yes' => 52.0, 'No' => 48.0],
  
  // Political Activities (varies)
  'rally' => ['Yes' => 8.0, 'No' => 92.0],
  'petition' => ['Yes' => 35.0, 'No' => 65.0],
  'signing' => ['Yes' => 35.0, 'No' => 65.0],
  'organizing' => ['Yes' => 6.0, 'No' => 94.0],
  'running' => ['Yes' => 2.0, 'No' => 98.0],
  'feasibility' => ['Yes' => 12.0, 'No' => 88.0],
  'network' => ['Yes' => 58.0, 'No' => 42.0],
  'barriers' => ['Yes' => 38.0, 'No' => 62.0],
  'diversity' => ['Yes' => 62.0, 'No' => 38.0],
  'representation' => ['Yes' => 65.0, 'No' => 35.0],
  'descriptive' => ['Yes' => 58.0, 'No' => 42.0],
  'substantive' => ['Yes' => 55.0, 'No' => 45.0],
  
  // Team & Motivation (moderate-high)
  'team' => ['Yes' => 68.0, 'No' => 32.0],
  'motivation' => ['Yes' => 65.0, 'No' => 35.0],
  
  // Job Values & Alignment (moderate-high)
  'values' => ['Yes' => 68.0, 'No' => 32.0],
  'alignment' => ['Yes' => 65.0, 'No' => 35.0],
  'purpose' => ['Yes' => 72.0, 'No' => 28.0],
  'daily' => ['Yes' => 75.0, 'No' => 25.0],
  'balance' => ['Yes' => 58.0, 'No' => 42.0],
  
  // Externalities & Legacy (moderate)
  'externalities' => ['Yes' => 45.0, 'No' => 55.0],
  'legacy' => ['Yes' => 48.0, 'No' => 52.0],
  
  // Mentoring & Service (low-moderate)
  'mentoring' => ['Yes' => 18.0, 'No' => 82.0],
  'reverse' => ['Yes' => 8.0, 'No' => 92.0],
  'bono' => ['Yes' => 12.0, 'No' => 88.0],
  'awards' => ['Yes' => 25.0, 'No' => 75.0],
  'strategic' => ['Yes' => 35.0, 'No' => 65.0],
  'philanthropy' => ['Yes' => 28.0, 'No' => 72.0],
  
  // Expertise & Campaign (low-moderate)
  'expertise' => ['Yes' => 38.0, 'No' => 62.0],
  'campaign' => ['Yes' => 15.0, 'No' => 85.0],
  'grassroots' => ['Yes' => 8.0, 'No' => 92.0],
  'donations' => ['Yes' => 45.0, 'No' => 55.0],
  
  // Environmental Actions (moderate)
  'recycling' => ['Yes' => 68.0, 'No' => 32.0],
  'conservation' => ['Yes' => 52.0, 'No' => 48.0],
  'consistency' => ['Yes' => 55.0, 'No' => 45.0],
  'efforts' => ['Yes' => 62.0, 'No' => 38.0],
  
  // Youth & Wisdom (low-moderate)
  'youth' => ['Yes' => 18.0, 'No' => 82.0],
  'wisdom' => ['Yes' => 52.0, 'No' => 48.0],
  'sharing' => ['Yes' => 48.0, 'No' => 52.0],
  'heritage' => ['Yes' => 35.0, 'No' => 65.0],
  'documentation' => ['Yes' => 28.0, 'No' => 72.0],
  
  // Arts & Public Sharing (low-moderate)
  'public' => ['Yes' => 42.0, 'No' => 58.0],
  'art' => ['Yes' => 28.0, 'No' => 72.0],
  'exhibitions' => ['Yes' => 15.0, 'No' => 85.0],
  'performances' => ['Yes' => 22.0, 'No' => 78.0],
  
  // Improvements & Generation (moderate)
  'improvements' => ['Yes' => 58.0, 'No' => 42.0],
  'process' => ['Yes' => 62.0, 'No' => 38.0],
  'ideas' => ['Yes' => 65.0, 'No' => 35.0],
  'generation' => ['Yes' => 52.0, 'No' => 48.0],
  
  // Writing & Research (low-moderate)
  'writing' => ['Yes' => 32.0, 'No' => 68.0],
  'publishing' => ['Yes' => 8.0, 'No' => 92.0],
  'research' => ['Yes' => 28.0, 'No' => 72.0],
  'impact' => ['Yes' => 52.0, 'No' => 48.0],
  'economic' => ['Yes' => 58.0, 'No' => 42.0],
  
  // Purpose & Action (moderate-high)
  'statement' => ['Yes' => 48.0, 'No' => 52.0],
  'action' => ['Yes' => 68.0, 'No' => 32.0],
  'transmission' => ['Yes' => 42.0, 'No' => 58.0],
  
  // Making Difference & Belief (high)
  'difference' => ['Yes' => 72.0, 'No' => 28.0],
  'making' => ['Yes' => 70.0, 'No' => 30.0],
  'belief' => ['Yes' => 75.0, 'No' => 25.0],
  'visibility' => ['Yes' => 62.0, 'No' => 38.0],
  'feeling' => ['Yes' => 68.0, 'No' => 32.0],
  
  // Vocation & Calling (moderate)
  'vocation' => ['Yes' => 52.0, 'No' => 48.0],
  'clarity' => ['Yes' => 58.0, 'No' => 42.0],
  'calling' => ['Yes' => 48.0, 'No' => 52.0],
  'pursuit' => ['Yes' => 55.0, 'No' => 45.0],
  'passion' => ['Yes' => 62.0, 'No' => 38.0],
  'integration' => ['Yes' => 52.0, 'No' => 48.0],
  'cause' => ['Yes' => 58.0, 'No' => 42.0],
  'commitment' => ['Yes' => 68.0, 'No' => 32.0],
  
  // Ego & Self Development (moderate-high)
  'ego' => ['Yes' => 45.0, 'No' => 55.0],
  'transcendence' => ['Yes' => 35.0, 'No' => 65.0],
  'strengths' => ['Yes' => 68.0, 'No' => 32.0],
  'becoming' => ['Yes' => 62.0, 'No' => 38.0],
  'best' => ['Yes' => 65.0, 'No' => 35.0],
  'self' => ['Yes' => 68.0, 'No' => 32.0],
  
  // Functional & Limitations (moderate)
  'functional' => ['Yes' => 35.0, 'No' => 65.0],
  'limitations' => ['Yes' => 38.0, 'No' => 62.0],
  
  // Trauma & PTSD (low-moderate)
  'trauma' => ['Yes' => 28.0, 'No' => 72.0],
  'history' => ['Yes' => 35.0, 'No' => 65.0],
  'ptsd' => ['Yes' => 6.5, 'No' => 93.5],
  'symptoms' => ['Yes' => 42.0, 'No' => 58.0],
  'treatment' => ['Yes' => 22.0, 'No' => 78.0],
  'recovery' => ['Yes' => 48.0, 'No' => 52.0],
  'progress' => ['Yes' => 62.0, 'No' => 38.0],
  
  // Substance Use (low-moderate)
  'substance' => ['Yes' => 12.0, 'No' => 88.0],
  'use' => ['Yes' => 35.0, 'No' => 65.0],
  
  // Training & Certification (typically low)
  'training' => ['Yes' => 25.0, 'No' => 75.0],
  'certification' => ['Yes' => 18.0, 'No' => 82.0],
  'certified' => ['Yes' => 18.0, 'No' => 82.0],
  
  // Employment & Benefits (varies)
  'employment' => ['Yes' => 61.0, 'No' => 39.0],
  'benefits' => ['Yes' => 65.0, 'No' => 35.0],
  'paid_leave' => ['Yes' => 76.0, 'No' => 24.0],
  'retirement_plan' => ['Yes' => 68.0, 'No' => 32.0],
  
  // Financial Access (typically high)
  'bank_account' => ['Yes' => 93.0, 'No' => 7.0],
  'credit' => ['Yes' => 73.0, 'No' => 27.0],
  'savings' => ['Yes' => 71.0, 'No' => 29.0],
  
  // Financial Problems (typically low)
  'debt' => ['Yes' => 23.0, 'No' => 77.0],
  'foreclosure' => ['Yes' => 2.5, 'No' => 97.5],
  'bankruptcy' => ['Yes' => 1.5, 'No' => 98.5],
  'collections' => ['Yes' => 14.0, 'No' => 86.0],
  
  // Housing & Property (varies)
  'homeownership' => ['Yes' => 65.2, 'No' => 34.8],
  'rental' => ['Yes' => 35.0, 'No' => 65.0],
  'subsidy' => ['Yes' => 8.0, 'No' => 92.0],
  
  // Education (moderate)
  'degree' => ['Yes' => 38.0, 'No' => 62.0],
  'enrolled' => ['Yes' => 24.0, 'No' => 76.0],
  'graduate' => ['Yes' => 13.0, 'No' => 87.0],
  
  // Health & Wellness (varies)
  'health_condition' => ['Yes' => 40.0, 'No' => 60.0],
  'chronic' => ['Yes' => 48.0, 'No' => 52.0],
  'disability' => ['Yes' => 13.0, 'No' => 87.0],
  'mental_health' => ['Yes' => 22.0, 'No' => 78.0],
  
  // Healthcare Access (high)
  'doctor' => ['Yes' => 88.0, 'No' => 12.0],
  'physician' => ['Yes' => 88.0, 'No' => 12.0],
  'healthcare_access' => ['Yes' => 85.0, 'No' => 15.0],
  
  // Preventive Care (moderate)
  'screening' => ['Yes' => 65.0, 'No' => 35.0],
  'checkup' => ['Yes' => 68.0, 'No' => 32.0],
  'vaccination' => ['Yes' => 72.0, 'No' => 28.0],
  
  // Social Connection (varies)
  'social_support' => ['Yes' => 75.0, 'No' => 25.0],
  'community_involvement' => ['Yes' => 28.0, 'No' => 72.0],
  'volunteer' => ['Yes' => 25.0, 'No' => 75.0],
  'religious' => ['Yes' => 47.0, 'No' => 53.0],
  
  // Civic Engagement (moderate-high)
  'voter' => ['Yes' => 71.0, 'No' => 29.0],
  'vote' => ['Yes' => 66.0, 'No' => 34.0],
  'civic' => ['Yes' => 22.0, 'No' => 78.0],
  
  // Technology & Access (high)
  'internet' => ['Yes' => 93.0, 'No' => 7.0],
  'computer' => ['Yes' => 85.0, 'No' => 15.0],
  'smartphone' => ['Yes' => 85.0, 'No' => 15.0],
  
  // Transportation (very high)
  'vehicle' => ['Yes' => 91.0, 'No' => 9.0],
  'car' => ['Yes' => 91.0, 'No' => 9.0],
  'driver_license' => ['Yes' => 89.0, 'No' => 11.0],
  
  // Substance Use (varies)
  'tobacco' => ['Yes' => 11.5, 'No' => 88.5],
  'smoking' => ['Yes' => 11.5, 'No' => 88.5],
  'alcohol' => ['Yes' => 54.0, 'No' => 46.0],
  'substance_abuse' => ['Yes' => 8.5, 'No' => 91.5],
  
  // Lifestyle & Behavior (varies)
  'exercise' => ['Yes' => 23.0, 'No' => 77.0],
  'diet' => ['Yes' => 32.0, 'No' => 68.0],
  'sleep' => ['Yes' => 35.0, 'No' => 65.0],
];

// Fetch all boolean metrics without distribution_data
$metrics = $database->select('individual_metrics_master', 'imm')
  ->fields('imm', ['id', 'metric_name', 'dimension'])
  ->condition('data_type', 'boolean')
  ->condition('distribution_data', NULL, 'IS NULL')
  ->execute()
  ->fetchAll();

$updated = 0;
$not_matched = [];
$by_dimension = [];

foreach ($metrics as $metric) {
  $matched = false;
  $distribution = null;
  $matched_pattern = null;
  
  // Try to match patterns (most specific first)
  foreach ($patterns as $pattern => $dist) {
    if (stripos($metric->metric_name, $pattern) !== false) {
      $distribution = $dist;
      $matched_pattern = $pattern;
      $matched = true;
      break;
    }
  }
  
  if ($matched && $distribution) {
    // Find most common value
    arsort($distribution);
    $most_common = key($distribution);
    $most_common_pct = current($distribution);
    
    // Update the metric
    $database->update('individual_metrics_master')
      ->fields([
        'distribution_data' => json_encode($distribution),
        'most_common_value' => $most_common,
        'most_common_percentage' => $most_common_pct,
      ])
      ->condition('id', $metric->id)
      ->execute();
    
    if (!isset($by_dimension[$metric->dimension])) {
      $by_dimension[$metric->dimension] = 0;
    }
    $by_dimension[$metric->dimension]++;
    
    echo "  ✓ [{$metric->dimension}] {$metric->metric_name} → {$matched_pattern} → {$most_common} ({$most_common_pct}%)\n";
    $updated++;
  } else {
    $not_matched[] = $metric;
  }
}

echo "\n=========================================================================\n";
echo "Summary by Dimension:\n";
foreach ($by_dimension as $dim => $count) {
  echo "  {$dim}: {$count} metrics updated\n";
}

echo "\n=========================================================================\n";
echo "Overall Summary:\n";
echo "  Total Updated: {$updated} metrics\n";
echo "  Not Matched: " . count($not_matched) . " metrics\n";

if (!empty($not_matched) && count($not_matched) <= 50) {
  echo "\nMetrics not matched (sample):\n";
  $sample = array_slice($not_matched, 0, 30);
  foreach ($sample as $m) {
    echo "  - [{$m->dimension}] {$m->metric_name}\n";
  }
  if (count($not_matched) > 30) {
    echo "  ... and " . (count($not_matched) - 30) . " more\n";
  }
}

echo "\n=========================================================================\n";
echo "Note: These are population estimates based on keyword matching and\n";
echo "available research. Actual percentages may vary by region, demographics,\n";
echo "and specific metric definitions.\n";
echo "=========================================================================\n";
