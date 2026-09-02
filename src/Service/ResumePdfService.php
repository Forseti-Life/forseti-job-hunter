<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use TCPDF;

/**
 * Service for generating PDF resumes from JSON content and style schemas.
 */
class ResumePdfService {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The style schema.
   *
   * @var array
   */
  protected array $styleSchema = [];

  /**
   * The resume content.
   *
   * @var array
   */
  protected array $content = [];

  /**
   * The PDF instance.
   *
   * @var \TCPDF
   */
  protected TCPDF $pdf;

  /**
   * Constructs a ResumePdfService object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('job_hunter');
  }

  /**
   * Generate a PDF from resume JSON content.
   *
   * @param array $content
   *   The resume content from consolidated_profile_json or tailored_resume_json.
   * @param string $style_schema_name
   *   The name of the style schema to use (without .json extension).
   *
   * @return string|null
   *   The PDF content as a string, or NULL on failure.
   */
  public function generatePdf(array $content, string $style_schema_name = 'keith_aumiller'): ?string {
    $this->content = $content;

    // Load the style schema.
    if (!$this->loadStyleSchema($style_schema_name)) {
      return NULL;
    }

    // Initialize PDF.
    $this->initializePdf();

    // Render each section.
    $this->renderContactInfo();
    $this->renderSections();

    // Return PDF content.
    return $this->pdf->Output('', 'S');
  }

  /**
   * Generate and save PDF to a file.
   *
   * @param array $content
   *   The resume content.
   * @param string $filename
   *   The filename to save as.
   * @param string $style_schema_name
   *   The style schema name.
   *
   * @return string|null
   *   The file path, or NULL on failure.
   */
  public function generateAndSavePdf(array $content, string $filename, string $style_schema_name = 'keith_aumiller', ?int $userId = NULL): ?string {
    $pdfContent = $this->generatePdf($content, $style_schema_name);

    if ($pdfContent === NULL) {
      return NULL;
    }

    // Use provided userId or current user
    $uid = $userId ?? \Drupal::currentUser()->id();
    
    // Create user-specific tailored resumes directory.
    $directory = 'private://job_hunter/resumes/' . $uid . '/tailoredresumes';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // Save file.
    $filepath = $directory . '/' . $filename;
    $saved = $this->fileSystem->saveData($pdfContent, $filepath, FileSystemInterface::EXISTS_REPLACE);

    return $saved ? $filepath : NULL;
  }

  /**
   * Load style schema from JSON file.
   *
   * @param string $name
   *   The schema name.
   *
   * @return bool
   *   TRUE if loaded successfully.
   */
  protected function loadStyleSchema(string $name): bool {
    $module_path = \Drupal::service('extension.list.module')->getPath('job_hunter');
    $schema_path = $module_path . '/config/resume_styles/' . $name . '.json';

    if (!file_exists($schema_path)) {
      $this->logger->error('Style schema not found: @path', ['@path' => $schema_path]);
      return FALSE;
    }

    $json = file_get_contents($schema_path);
    $this->styleSchema = json_decode($json, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      $this->logger->error('Invalid style schema JSON: @error', ['@error' => json_last_error_msg()]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Initialize the PDF document.
   */
  protected function initializePdf(): void {
    $page = $this->styleSchema['page'] ?? [];

    // Create PDF with custom page size.
    $this->pdf = new TCPDF('P', 'pt', [
      $page['width_pt'] ?? 612,
      $page['height_pt'] ?? 792,
    ], TRUE, 'UTF-8', FALSE);

    // Set document info.
    $name = $this->getContentValue('contact_info.full_name', 'Resume');
    $this->pdf->SetCreator('Job Hunter Module');
    $this->pdf->SetAuthor($name);
    $this->pdf->SetTitle($name . ' - Resume');

    // Remove default header/footer.
    $this->pdf->setPrintHeader(FALSE);
    $this->pdf->setPrintFooter(FALSE);

    // Set margins.
    $margins = $page['margins_pt'] ?? [];
    $this->pdf->SetMargins(
      $margins['left'] ?? 54,
      $margins['top'] ?? 36,
      $margins['right'] ?? 54
    );
    $this->pdf->SetAutoPageBreak(TRUE, $margins['bottom'] ?? 36);

    // Add first page.
    $this->pdf->AddPage();

    // Set default font.
    $this->applyFontStyle('body_text');
  }

  /**
   * Apply a style to the PDF.
   *
   * @param string $style_name
   *   The style name from the schema.
   */
  protected function applyFontStyle(string $style_name): void {
    $styles = $this->styleSchema['styles'] ?? [];
    $fonts = $this->styleSchema['fonts'] ?? [];
    $style = $styles[$style_name] ?? $styles['body_text'] ?? [];

    // Get font definition.
    $fontKey = $style['font'] ?? 'primary';
    $font = $fonts[$fontKey] ?? ['family' => 'Helvetica', 'weight' => 'normal'];

    // Map font weight/style to TCPDF style string.
    $tcpdfStyle = '';
    if (($font['weight'] ?? 'normal') === 'bold') {
      $tcpdfStyle .= 'B';
    }
    if (($font['style'] ?? 'normal') === 'italic') {
      $tcpdfStyle .= 'I';
    }

    $family = $font['family'] ?? 'Helvetica';
    // TCPDF uses lowercase font names.
    $family = strtolower($family);
    // Map common fonts to TCPDF built-in fonts.
    $fontMap = [
      'tahoma' => 'helvetica',
      'arial' => 'helvetica',
    ];
    $family = $fontMap[$family] ?? $family;

    $size = $style['size_pt'] ?? 11;

    $this->pdf->SetFont($family, $tcpdfStyle, $size);

    // Set text color.
    $color = $style['color'] ?? '#000000';
    $this->setColorFromHex($color);
  }

  /**
   * Set text color from hex string.
   *
   * @param string $hex
   *   Hex color code (e.g., #000000).
   */
  protected function setColorFromHex(string $hex): void {
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $this->pdf->SetTextColor($r, $g, $b);
  }

  /**
   * Get a value from content using dot notation.
   *
   * @param string $path
   *   The dot-notation path (e.g., contact_info.full_name).
   * @param mixed $default
   *   Default value if not found.
   *
   * @return mixed
   *   The value at the path.
   */
  protected function getContentValue(string $path, $default = '') {
    $keys = explode('.', $path);
    $value = $this->content;

    foreach ($keys as $key) {
      // Handle array notation like "websites[]".
      $key = rtrim($key, '[]');
      if (!isset($value[$key])) {
        return $default;
      }
      $value = $value[$key];
    }

    return $value;
  }

  /**
   * Get style property.
   *
   * @param string $style_name
   *   The style name.
   * @param string $property
   *   The property name.
   * @param mixed $default
   *   Default value.
   *
   * @return mixed
   *   The property value.
   */
  protected function getStyleProperty(string $style_name, string $property, $default = NULL) {
    return $this->styleSchema['styles'][$style_name][$property] ?? $default;
  }

  /**
   * Render the contact info section (header).
   */
  protected function renderContactInfo(): void {
    $contact = $this->content['contact_info'] ?? [];

    // Name and credentials - centered.
    $this->applyFontStyle('name');
    $name = $contact['full_name'] ?? '';
    $credentials = $contact['credentials'] ?? [];
    if (!empty($credentials)) {
      $name .= ', ' . implode(', ', $credentials);
    }
    $this->pdf->Cell(0, 0, $name, 0, 1, 'C');
    $this->pdf->Ln($this->getStyleProperty('name', 'margin_bottom_pt', 2));

    // Headline - centered.
    if (!empty($contact['headline'])) {
      $this->applyFontStyle('headline');
      $this->pdf->Cell(0, 0, $contact['headline'], 0, 1, 'C');
      $this->pdf->Ln($this->getStyleProperty('headline', 'margin_bottom_pt', 4));
    }

    // Contact line - centered.
    $this->applyFontStyle('contact_line');
    $contactParts = [];
    if (!empty($contact['location'])) {
      // Handle location as object {city, state} or string.
      if (is_array($contact['location'])) {
        $locParts = [];
        if (!empty($contact['location']['city'])) {
          $locParts[] = $contact['location']['city'];
        }
        if (!empty($contact['location']['state'])) {
          $locParts[] = $contact['location']['state'];
        }
        $contactParts[] = implode(', ', $locParts);
      }
      else {
        $contactParts[] = $contact['location'];
      }
    }
    if (!empty($contact['phone'])) {
      $contactParts[] = $contact['phone'];
    }
    if (!empty($contact['email'])) {
      $contactParts[] = $contact['email'];
    }
    if (!empty($contactParts)) {
      $separator = $this->getStyleProperty('contact_line', 'separator', ' | ');
      $this->pdf->Cell(0, 0, implode($separator, $contactParts), 0, 1, 'C');
      $this->pdf->Ln(2);
    }

    // Websites (skip linkedin since it's handled separately with metadata) - centered.
    $websites = $contact['websites'] ?? [];
    foreach ($websites as $website) {
      $type = $website['type'] ?? '';
      // Skip linkedin - it's rendered below with followers/groups info.
      if (strtolower($type) === 'linkedin') {
        continue;
      }
      $this->applyFontStyle('contact_link');
      $label = $website['label'] ?? $type;
      $url = $website['url'] ?? '';
      if (!empty($url)) {
        $text = !empty($label) ? ucfirst($label) . ': ' . $url : $url;
        $this->pdf->Cell(0, 0, $text, 0, 1, 'C', FALSE, $url);
        $this->pdf->Ln(2);
      }
    }

    // LinkedIn info (followers, groups) - with clickable links, centered.
    $linkedin = $contact['linkedin'] ?? [];
    if (!empty($linkedin)) {
      $this->applyFontStyle('contact_link');
      
      // LinkedIn URL with followers
      $linkedinUrl = $linkedin['url'] ?? '';
      if (!empty($linkedinUrl)) {
        $urlText = 'LinkedIn: ' . $linkedinUrl;
        if (!empty($linkedin['followers'])) {
          $urlText .= ' (' . $linkedin['followers'] . ' followers)';
        }
        $this->pdf->Cell(0, 0, $urlText, 0, 1, 'C', FALSE, $linkedinUrl);
        $this->pdf->Ln(2);
      }
      
      // Groups administered with link
      if (!empty($linkedin['groups_administered'])) {
        $groupUrl = $linkedin['group_url'] ?? '';
        foreach ($linkedin['groups_administered'] as $group) {
          $groupText = 'Facebook Group Admin: ' . $group;
          $this->pdf->Cell(0, 0, $groupText, 0, 1, 'C', FALSE, $groupUrl);
          $this->pdf->Ln(2);
        }
      }
    }

    $this->pdf->Ln(8);
  }

  /**
   * Render all content sections.
   */
  protected function renderSections(): void {
    $sectionOrder = $this->styleSchema['section_order'] ?? [];
    $sectionLabels = $this->styleSchema['section_labels'] ?? [];

    foreach ($sectionOrder as $section) {
      // Skip contact_info (already rendered).
      if ($section === 'contact_info') {
        continue;
      }

      // Check if section has content, and normalize/filter sections whose
      // AI-generated shape is inconsistent or may contain items with no
      // real substance (e.g. an engagement with only a client name and no
      // role/description, or a duplicate demonstration project). Sections
      // that end up with nothing meaningful after normalization are
      // skipped entirely so no blank header is ever printed.
      $rawContent = $this->content[$section] ?? NULL;
      if (empty($rawContent)) {
        continue;
      }
      $sectionContent = $this->prepareSectionContent($section, $rawContent);
      if ($sectionContent === NULL) {
        continue;
      }

      // Render section header.
      $label = $sectionLabels[$section] ?? strtoupper(str_replace('_', ' ', $section));
      $this->renderSectionHeader($label);

      // Render section content.
      switch ($section) {
        case 'executive_profile':
          $this->renderExecutiveProfile($sectionContent);
          break;

        case 'organizational_philosophy':
          $this->renderOrganizationalPhilosophy($sectionContent);
          break;

        case 'strategic_differentiators':
          $this->renderStrategicDifferentiators($sectionContent);
          break;

        case 'professional_experience':
          $this->renderProfessionalExperience($sectionContent);
          break;

        case 'consulting_practice':
          $this->renderConsultingPractice($sectionContent);
          break;

        case 'early_career':
          $this->renderEarlyCareer($sectionContent);
          break;

        case 'education':
          $this->renderEducation($sectionContent);
          break;

        case 'technical_expertise':
          $this->renderTechnicalExpertise($sectionContent);
          break;

        case 'leadership_philosophy':
          $this->renderLeadershipPhilosophy($sectionContent);
          break;

        case 'demonstration_projects':
          $this->renderDemonstrationProjects($sectionContent);
          break;

        case 'publications':
          $this->renderPublications($sectionContent);
          break;

        case 'patents':
          $this->renderPatents($sectionContent);
          break;

        case 'certifications':
          $this->renderCertifications($sectionContent);
          break;

        case 'awards_and_honors':
          $this->renderAwardsAndHonors($sectionContent);
          break;

        case 'languages':
          $this->renderLanguages($sectionContent);
          break;
      }
    }
  }

  /**
   * Normalize and filter section content that may contain low-substance or
   * inconsistently-shaped AI-generated items before a header is printed.
   *
   * The GenAI tailoring prompts leave several optional sections' schemas
   * loosely specified ("include if relevant" with no strict shape), so the
   * model can return inconsistent field names/structures across runs (e.g.
   * strategic_differentiators as bare strings vs {title, description}
   * objects, or consulting_practice under an "engagements" key instead of
   * "notable_engagements"). Source profile data for these optional sections
   * can also contain items that are little more than a label with every
   * descriptive field blank. This normalizes known-problematic sections
   * into the shape their renderer expects and drops items/sections with
   * nothing substantive to display, so a section header is never printed
   * with a blank body underneath it.
   *
   * @param string $section
   *   The section key (e.g. 'strategic_differentiators').
   * @param mixed $content
   *   The raw section content.
   *
   * @return mixed|null
   *   Normalized content ready for the section's renderer, or NULL if the
   *   section has nothing meaningful to display and should be skipped.
   */
  protected function prepareSectionContent(string $section, $content) {
    switch ($section) {
      case 'strategic_differentiators':
        return $this->normalizeStrategicDifferentiators($content);

      case 'consulting_practice':
        return $this->normalizeConsultingPractice($content);

      case 'early_career':
        return $this->normalizeEarlyCareer($content);

      case 'leadership_philosophy':
        return $this->normalizeLeadershipPhilosophy($content);

      case 'demonstration_projects':
        return $this->normalizeDemonstrationProjects($content);

      default:
        // All other sections keep their existing pass-through behavior.
        return $content;
    }
  }

  /**
   * Normalize strategic_differentiators to an array of {title, description}.
   *
   * Accepts either the tailored-prompt shape (array of bare strings) or the
   * source-profile shape (array of {title, description} objects). Drops
   * any item where both title and description are empty.
   */
  protected function normalizeStrategicDifferentiators($content): ?array {
    if (!is_array($content)) {
      return NULL;
    }

    $items = [];
    foreach ($content as $item) {
      if (is_string($item)) {
        $item = trim($item);
        // The tailoring AI often emits these as "Heading: description"
        // strings rather than {title, description} objects. Split on the
        // first colon so the heading still renders bold with the
        // description as regular body text underneath, matching the
        // {title, description} rendering path.
        if (preg_match('/^([^:]{2,80}):\s*(.+)$/s', $item, $matches)) {
          $title = trim($matches[1]);
          $description = trim($matches[2]);
        }
        else {
          $title = $item;
          $description = '';
        }
      }
      elseif (is_array($item)) {
        $title = trim($item['title'] ?? '');
        $description = trim($item['description'] ?? '');
      }
      else {
        continue;
      }

      if ($title === '' && $description === '') {
        continue;
      }

      $items[] = ['title' => $title, 'description' => $description];
    }

    return $items ?: NULL;
  }

  /**
   * Normalize consulting_practice, tolerating an "engagements" key alias
   * for "notable_engagements", and dropping engagements with no client,
   * role, project name, or description at all.
   */
  protected function normalizeConsultingPractice($content): ?array {
    if (!is_array($content)) {
      return NULL;
    }

    $rawEngagements = $content['notable_engagements'] ?? $content['engagements'] ?? [];
    $engagements = [];
    if (is_array($rawEngagements)) {
      foreach ($rawEngagements as $engagement) {
        if (!is_array($engagement)) {
          continue;
        }
        $client = trim($engagement['client'] ?? '');
        $role = trim($engagement['role'] ?? '');
        $project = trim($engagement['project_name'] ?? '');
        $description = trim($engagement['description'] ?? '');

        if ($client === '' && $role === '' && $project === '' && $description === '') {
          continue;
        }

        $engagements[] = [
          'client' => $client,
          'role' => $role ?: $project,
          'description' => $description,
        ];
      }
    }

    $hasHeaderContent = !empty(trim($content['company'] ?? ''));

    if (!$hasHeaderContent && empty($engagements)) {
      return NULL;
    }

    $normalized = $content;
    unset($normalized['engagements']);
    $normalized['notable_engagements'] = $engagements;
    return $normalized;
  }

  /**
   * Normalize early_career into a flat array of display strings, regardless
   * of whether it arrived as bare strings, {summary, positions}, or the
   * ad hoc {company, title, description} shape the tailoring AI tends to
   * produce for this loosely-specified section.
   */
  protected function normalizeEarlyCareer($content): ?array {
    if (is_string($content)) {
      $content = trim($content) === '' ? [] : [$content];
    }
    if (!is_array($content)) {
      return NULL;
    }

    // Object format with an explicit summary/positions shape.
    if (array_key_exists('summary', $content) || array_key_exists('positions', $content)) {
      $lines = [];
      $summary = trim($content['summary'] ?? '');
      if ($summary !== '') {
        $lines[] = $summary;
      }
      foreach ($content['positions'] ?? [] as $pos) {
        if (!is_array($pos)) {
          continue;
        }
        $parts = [];
        if (!empty($pos['company'])) {
          $parts[] = trim($pos['company']);
        }
        if (!empty($pos['duration'])) {
          $parts[] = '(' . trim($pos['duration']) . ')';
        }
        if (!empty($pos['focus'])) {
          $parts[] = '– ' . trim($pos['focus']);
        }
        $line = trim(implode(' ', $parts));
        if ($line !== '') {
          $lines[] = $line;
        }
      }
      return $lines ?: NULL;
    }

    // Flat list: bare strings, or ad hoc {company, title, description} items.
    $lines = [];
    $leadingDateRange = NULL;
    foreach ($content as $item) {
      if (is_string($item)) {
        $line = trim($item);
        if ($line !== '') {
          $lines[] = $line;
        }
        continue;
      }
      if (!is_array($item)) {
        continue;
      }

      $company = trim($item['company'] ?? '');
      $title = trim($item['title'] ?? '');
      $description = trim($item['description'] ?? '');

      // A bare year range (e.g. "2000-2011") is more useful as an overall
      // leading label for the section than as a standalone bullet or a
      // colon-joined prefix on whichever item happens to carry it — capture
      // the first one seen and prepend it to the final output instead.
      if ($company !== '' && $this->looksLikeDateRange($company)) {
        if ($leadingDateRange === NULL) {
          $leadingDateRange = $company;
        }
        $company = '';
      }

      $parts = array_filter([$company, $title, $description], fn($p) => $p !== '');
      $line = trim(implode(': ', $parts));
      if ($line !== '') {
        $lines[] = $line;
      }
    }

    // Drop exact duplicate lines (the tailoring AI occasionally repeats
    // near-identical summary text across multiple items).
    $lines = array_values(array_unique($lines));

    if ($leadingDateRange !== NULL) {
      if (!empty($lines)) {
        $lines[0] = "{$leadingDateRange} — {$lines[0]}";
      }
      else {
        $lines[] = $leadingDateRange;
      }
    }

    return $lines ?: NULL;
  }

  /**
   * Normalize leadership_philosophy into a deduplicated array of paragraph
   * strings, tolerating a bare string, an array of strings, {statement},
   * or an array of {principle} objects.
   */
  protected function normalizeLeadershipPhilosophy($content): ?array {
    if (is_string($content)) {
      $content = trim($content) === '' ? [] : [$content];
    }
    elseif (is_array($content) && !empty($content['statement']) && !isset($content[0])) {
      $content = [$content];
    }
    if (!is_array($content)) {
      return NULL;
    }

    $paragraphs = [];
    $seen = [];
    foreach ($content as $item) {
      $text = '';
      if (is_string($item)) {
        $text = trim($item);
      }
      elseif (is_array($item)) {
        $text = trim($item['principle'] ?? $item['statement'] ?? $item['summary'] ?? $item['text'] ?? '');
      }

      if ($text === '') {
        continue;
      }

      // Dedupe near-identical paragraphs (the source data has repeated the
      // same idea reworded several times) by comparing a normalized prefix.
      $fingerprint = strtolower(substr(preg_replace('/\s+/', ' ', $text), 0, 100));
      if (isset($seen[$fingerprint])) {
        continue;
      }
      $seen[$fingerprint] = TRUE;

      $paragraphs[] = $text;

      // Keep the section focused — cap at 2 distinct paragraphs.
      if (count($paragraphs) >= 2) {
        break;
      }
    }

    return $paragraphs ?: NULL;
  }

  /**
   * Normalize demonstration_projects: drop items with no name, description,
   * URL, or technologies at all, and dedupe/collapse near-duplicate project
   * names (e.g. a project listed both standalone and as part of a longer,
   * more descriptive combined name).
   */
  protected function normalizeDemonstrationProjects($content): ?array {
    if (!is_array($content)) {
      return NULL;
    }

    $accepted = [];
    foreach ($content as $project) {
      if (!is_array($project)) {
        continue;
      }
      $name = trim($project['name'] ?? '');
      $description = trim($project['description'] ?? '');
      $url = trim($project['url'] ?? '');
      $technologies = $project['technologies'] ?? [];

      if ($name === '' && $description === '' && $url === '' && empty($technologies)) {
        continue;
      }

      $normalizedName = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $name));
      $normalizedName = trim(preg_replace('/\s+/', ' ', $normalizedName));

      $isSubsumed = FALSE;
      foreach ($accepted as $index => $existing) {
        if ($normalizedName === '' || $existing['_normalized_name'] === '') {
          continue;
        }
        if ($normalizedName === $existing['_normalized_name']) {
          $isSubsumed = TRUE;
          break;
        }
        // If this name is fully contained within an already-accepted,
        // more descriptive name (or vice versa), keep only the longer one.
        if (str_contains($existing['_normalized_name'], $normalizedName)) {
          $isSubsumed = TRUE;
          break;
        }
        if (str_contains($normalizedName, $existing['_normalized_name'])) {
          unset($accepted[$index]);
        }
      }

      if ($isSubsumed) {
        continue;
      }

      $accepted[] = [
        'name' => $name,
        'description' => $description,
        'url' => $url,
        'technologies' => $technologies,
        '_normalized_name' => $normalizedName,
      ];
    }

    $accepted = array_values(array_map(function ($item) {
      unset($item['_normalized_name']);
      return $item;
    }, $accepted));

    return $accepted ?: NULL;
  }

  /**
   * Determine whether a string looks like a bare year range, e.g.
   * "2000-2011" or "2000 – Present".
   */
  protected function looksLikeDateRange(string $text): bool {
    return (bool) preg_match('/^\s*\d{4}\s*[-–—]\s*(\d{4}|present)\s*$/i', $text);
  }

  /**
   * Render a section header.
   *
   * @param string $label
   *   The section label.
   */
  protected function renderSectionHeader(string $label): void {
    $style = $this->styleSchema['styles']['section_header'] ?? [];

    $this->pdf->Ln($style['margin_top_pt'] ?? 14);
    $this->applyFontStyle('section_header');

    // Apply text transform.
    if (($style['text_transform'] ?? '') === 'uppercase') {
      $label = strtoupper($label);
    }

    $this->pdf->Cell(0, 0, $label, 0, 1, 'L');

    // Border bottom.
    if (!empty($style['border_bottom'])) {
      $border = $style['border_bottom'];
      $this->pdf->SetLineWidth($border['width_pt'] ?? 0.5);
      $color = $border['color'] ?? '#000000';
      $hex = ltrim($color, '#');
      $this->pdf->SetDrawColor(
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
      );
      $this->pdf->Line(
        $this->pdf->GetX(),
        $this->pdf->GetY() + 2,
        $this->pdf->getPageWidth() - $this->pdf->getMargins()['right'],
        $this->pdf->GetY() + 2
      );
    }

    $this->pdf->Ln($style['margin_bottom_pt'] ?? 4);
  }

  /**
   * Render executive profile section.
   *
   * @param mixed $content
   *   The section content (string or array with 'summary').
   */
  protected function renderExecutiveProfile($content): void {
    // Handle both string format (tailored resume) and array format (consolidated)
    $summary = is_string($content) ? $content : ($content['summary'] ?? '');
    
    if (!empty($summary)) {
      $this->applyFontStyle('body_text');
      $this->pdf->MultiCell(0, 0, $summary, 0, 'L', FALSE, 1);
      $this->pdf->Ln(4);
    }

    // Key metrics as bullets (only if array format).
    if (is_array($content) && !empty($content['key_metrics'])) {
      $this->renderBulletList($content['key_metrics'], 'bullet_item');
    }
  }

  /**
   * Render strategic differentiators.
   *
   * @param array $items
   *   The differentiators array.
   */
  protected function renderStrategicDifferentiators(array $items): void {
    foreach ($items as $item) {
      $title = $item['title'] ?? '';
      $description = $item['description'] ?? '';

      // Render title in bold on its own line (no colon).
      $this->applyFontStyle('differentiator_title');
      $this->pdf->Cell(0, 0, $title, 0, 1, 'L');
      
      // Write description on the next line in regular text.
      if (!empty($description)) {
        $this->applyFontStyle('differentiator_description');
        $this->pdf->MultiCell(0, 0, $description, 0, 'L', FALSE, 1);
      }

      $this->pdf->Ln(4);
    }
  }

  /**
   * Render professional experience section.
   *
   * @param array $experiences
   *   The experiences array.
   */
  protected function renderProfessionalExperience(array $experiences): void {
    foreach ($experiences as $index => $exp) {
      // New tailored schema: one company entry with nested positions.
      if (!empty($exp['positions']) && is_array($exp['positions'])) {
        foreach ($exp['positions'] as $position_index => $position) {
          if ($index > 0 || $position_index > 0) {
            $this->pdf->Ln(8);
          }

          $title = $position['title'] ?? ($exp['title'] ?? '');
          $start_date = $position['start_date'] ?? ($exp['start_date'] ?? '');
          $end_date = $position['end_date'] ?? ($exp['end_date'] ?? '');
          $this->renderTwoColumnLine(
            $title,
            $this->formatDateRange($start_date, $end_date),
            'job_title',
            'date_range'
          );

          $this->applyFontStyle('company_name');
          $companyLine = $exp['company'] ?? ($position['company'] ?? '');
          $location = $position['location'] ?? ($exp['location'] ?? '');
          if (!empty($location)) {
            $companyLine .= ' | ' . $location;
          }
          $this->pdf->Cell(0, 0, $companyLine, 0, 1, 'L');
          $this->pdf->Ln(2);

          $company_context = $position['company_context'] ?? ($exp['company_context'] ?? '');
          if (!empty($company_context)) {
            $this->applyFontStyle('company_context');
            $this->pdf->MultiCell(0, 0, $company_context, 0, 'L', FALSE, 1);
            $this->pdf->Ln(4);
          }

          $achievements = $position['achievements'] ?? [];
          foreach ($achievements as $achievement) {
            $text = is_array($achievement) ? ($achievement['text'] ?? '') : $achievement;
            if (!empty($text)) {
              $this->renderBulletItem($text, 'achievement_bullet');
            }
          }
        }
        continue;
      }

      if ($index > 0) {
        $this->pdf->Ln(8);
      }

      // Legacy schema fallback.
      $this->renderTwoColumnLine(
        $exp['title'] ?? '',
        $this->formatDateRange($exp['start_date'] ?? '', $exp['end_date'] ?? ''),
        'job_title',
        'date_range'
      );

      $this->applyFontStyle('company_name');
      $companyLine = $exp['company'] ?? '';
      if (!empty($exp['location'])) {
        $companyLine .= ' | ' . $exp['location'];
      }
      $this->pdf->Cell(0, 0, $companyLine, 0, 1, 'L');
      $this->pdf->Ln(2);

      if (!empty($exp['company_context'])) {
        $this->applyFontStyle('company_context');
        $this->pdf->MultiCell(0, 0, $exp['company_context'], 0, 'L', FALSE, 1);
        $this->pdf->Ln(4);
      }

      $categories = $exp['responsibility_categories'] ?? [];
      foreach ($categories as $category) {
        if (!empty($category['category'])) {
          $this->applyFontStyle('category_header');
          $this->pdf->Cell(0, 0, $category['category'], 0, 1, 'L');
          $this->pdf->Ln(2);
        }

        $achievements = $category['achievements'] ?? [];
        foreach ($achievements as $achievement) {
          $text = is_array($achievement) ? ($achievement['text'] ?? '') : $achievement;
          if (!empty($text)) {
            $this->renderBulletItem($text, 'achievement_bullet');
          }
        }
      }
    }
  }

  /**
   * Render consulting practice section.
   *
   * @param array $content
   *   The section content.
   */
  protected function renderConsultingPractice(array $content): void {
    // Similar to professional experience.
    if (!empty($content['company'])) {
      $this->renderTwoColumnLine(
        $content['title'] ?? 'Principal Consultant',
        $this->formatDateRange($content['start_date'] ?? '', $content['end_date'] ?? ''),
        'job_title',
        'date_range'
      );

      $this->applyFontStyle('company_name');
      $this->pdf->Cell(0, 0, $content['company'], 0, 1, 'L');
      $this->pdf->Ln(4);
    }

    // Notable engagements.
    $engagements = $content['notable_engagements'] ?? [];
    foreach ($engagements as $engagement) {
      $this->applyFontStyle('engagement_client');
      $text = $engagement['client'] ?? '';
      if (!empty($engagement['role'])) {
        $text .= ' – ' . $engagement['role'];
      }
      $this->pdf->Cell(0, 0, $text, 0, 1, 'L');

      if (!empty($engagement['description'])) {
        $this->applyFontStyle('body_text');
        $this->pdf->MultiCell(0, 0, $engagement['description'], 0, 'L', FALSE, 1);
      }
      $this->pdf->Ln(2);
    }
  }

  /**
   * Render early career section.
   *
   * @param mixed $content
   *   The section content (array of strings, or object with positions).
   */
  protected function renderEarlyCareer($content): void {
    $this->applyFontStyle('body_text');
    
    // Handle array of simple strings (like ["2000-2011"]).
    if (is_array($content) && isset($content[0]) && is_string($content[0])) {
      foreach ($content as $item) {
        $this->pdf->MultiCell(0, 0, $item, 0, 'L', FALSE, 1);
        $this->pdf->Ln(2);
      }
      return;
    }
    
    // Handle object format with summary and positions.
    if (is_array($content)) {
      // Summary.
      if (!empty($content['summary'])) {
        $this->pdf->MultiCell(0, 0, $content['summary'], 0, 'L', FALSE, 1);
        $this->pdf->Ln(4);
      }

      // Positions.
      $positions = $content['positions'] ?? [];
      foreach ($positions as $pos) {
        $this->applyFontStyle('early_career_company');
        $text = $pos['company'] ?? '';
        if (!empty($pos['duration'])) {
          $text .= ' (' . $pos['duration'] . ')';
        }
        if (!empty($pos['focus'])) {
          $text .= ' – ' . $pos['focus'];
        }
        $this->pdf->MultiCell(0, 0, $text, 0, 'L', FALSE, 1);
        $this->pdf->Ln(2);
      }
    }
    // Handle simple string.
    elseif (is_string($content)) {
      $this->pdf->MultiCell(0, 0, $content, 0, 'L', FALSE, 1);
      $this->pdf->Ln(2);
    }
  }

  /**
   * Render education section.
   *
   * @param array $items
   *   The education items.
   */
  protected function renderEducation(array $items): void {
    foreach ($items as $edu) {
      // Institution and dates.
      $this->renderTwoColumnLine(
        $edu['institution'] ?? '',
        $this->formatDateRange($edu['start_date'] ?? '', $edu['end_date'] ?? ''),
        'institution_name',
        'education_date'
      );

      // Degree.
      if (!empty($edu['degree'])) {
        $this->applyFontStyle('degree_name');
        $degreeText = $edu['degree'];
        if (!empty($edu['field'])) {
          $degreeText .= ' in ' . $edu['field'];
        }
        $this->pdf->Cell(0, 0, $degreeText, 0, 1, 'L');
      }

      $this->pdf->Ln(4);
    }
  }

  /**
   * Render technical expertise section.
   *
   * @param array $content
   *   The section content.
   */
  protected function renderTechnicalExpertise(array $content): void {
    $categories = $content['categories'] ?? [];

    foreach ($categories as $category) {
      $name = $category['name'] ?? '';
      $skills = $category['skills'] ?? [];

      // Render category name in bold on its own line.
      $this->applyFontStyle('skill_category');
      $this->pdf->SetFont('', 'B');
      $this->pdf->Cell(0, 0, $name . ':', 0, 1, 'L');

      // Render skills on new line with indent.
      $this->applyFontStyle('skill_list');
      $margins = $this->pdf->getMargins();
      $this->pdf->SetX($margins['left'] + 12);
      $skillText = implode(', ', $skills);
      $this->pdf->MultiCell(0, 0, $skillText, 0, 'L', FALSE, 1);

      // Subcategories.
      $subcategories = $category['subcategories'] ?? [];
      foreach ($subcategories as $sub) {
        $this->applyFontStyle('skill_subcategory');
        $this->pdf->SetX($margins['left'] + 12);
        $this->pdf->SetFont('', 'B');
        $this->pdf->Cell(0, 0, ($sub['industry'] ?? '') . ':', 0, 1, 'L');

        $this->applyFontStyle('skill_list');
        $this->pdf->SetX($margins['left'] + 24);
        $subSkills = implode(', ', $sub['skills'] ?? []);
        $this->pdf->MultiCell(0, 0, $subSkills, 0, 'L', FALSE, 1);
      }

      $this->pdf->Ln(4);
    }
  }

  /**
   * Render organizational philosophy section.
   *
   * @param mixed $content
   *   The section content (string, array of strings, or object).
   */
  protected function renderOrganizationalPhilosophy($content): void {
    $this->applyFontStyle('body_text');
    
    // Handle array of strings.
    if (is_array($content)) {
      foreach ($content as $paragraph) {
        if (is_string($paragraph)) {
          $this->pdf->MultiCell(0, 0, $paragraph, 0, 'L', FALSE, 1);
          $this->pdf->Ln(4);
        }
      }
    }
    // Handle simple string.
    elseif (is_string($content)) {
      $this->pdf->MultiCell(0, 0, $content, 0, 'L', FALSE, 1);
      $this->pdf->Ln(4);
    }
  }

  /**
   * Render leadership philosophy section.
   *
   * @param mixed $content
   *   The section content (string, array of strings, or object with 'statement').
   */
  protected function renderLeadershipPhilosophy($content): void {
    $this->applyFontStyle('body_text');
    
    // Handle array of strings (tailored format).
    if (is_array($content) && isset($content[0]) && is_string($content[0])) {
      foreach ($content as $paragraph) {
        if (is_string($paragraph)) {
          $this->pdf->MultiCell(0, 0, $paragraph, 0, 'L', FALSE, 1);
          $this->pdf->Ln(4);
        }
      }
    }
    // Handle object with 'statement' (consolidated format).
    elseif (is_array($content) && !empty($content['statement'])) {
      $this->pdf->MultiCell(0, 0, $content['statement'], 0, 'L', FALSE, 1);
      $this->pdf->Ln(4);

      if (!empty($content['influences'])) {
        $this->renderBulletList($content['influences'], 'bullet_item');
      }
    }
    // Handle simple string.
    elseif (is_string($content)) {
      $this->pdf->MultiCell(0, 0, $content, 0, 'L', FALSE, 1);
      $this->pdf->Ln(4);
    }
  }

  /**
   * Render demonstration projects section.
   *
   * @param array $projects
   *   The projects array.
   */
  protected function renderDemonstrationProjects(array $projects): void {
    foreach ($projects as $project) {
      $this->applyFontStyle('project_name');
      $name = $project['name'] ?? '';
      $url = $project['url'] ?? '';

      if (!empty($url)) {
        $this->pdf->Cell(0, 0, $name, 0, 1, 'L', FALSE, $url);
        $this->applyFontStyle('project_url');
        $this->pdf->Cell(0, 0, $url, 0, 1, 'L', FALSE, $url);
      }
      else {
        $this->pdf->Cell(0, 0, $name, 0, 1, 'L');
      }

      if (!empty($project['description'])) {
        $this->applyFontStyle('project_description');
        $this->pdf->MultiCell(0, 0, $project['description'], 0, 'L', FALSE, 1);
      }

      if (!empty($project['technologies'])) {
        $this->applyFontStyle('project_technologies');
        $prefix = $this->getStyleProperty('project_technologies', 'prefix', 'Technologies: ');
        $techText = $prefix . implode(', ', $project['technologies']);
        $this->pdf->Cell(0, 0, $techText, 0, 1, 'L');
      }

      $this->pdf->Ln(4);
    }
  }

  /**
   * Render publications section.
   *
   * @param array $publications
   *   The publications array.
   */
  protected function renderPublications(array $publications): void {
    foreach ($publications as $publication) {
      // Title.
      $this->applyFontStyle('publication_title');
      $title = $publication['title'] ?? '';
      $this->pdf->MultiCell(0, 0, $title, 0, 'L', FALSE, 1);

      // Authors.
      if (!empty($publication['authors'])) {
        $this->applyFontStyle('publication_authors');
        $authors = is_array($publication['authors']) ? implode(', ', $publication['authors']) : $publication['authors'];
        $this->pdf->MultiCell(0, 0, $authors, 0, 'L', FALSE, 1);
      }

      // Publication venue and date.
      $details = [];
      if (!empty($publication['publication'])) {
        $details[] = $publication['publication'];
      }
      if (!empty($publication['date'])) {
        $details[] = $publication['date'];
      }
      if (!empty($details)) {
        $this->applyFontStyle('publication_details');
        $this->pdf->Cell(0, 0, implode(', ', $details), 0, 1, 'L');
      }

      // DOI or URL.
      if (!empty($publication['doi'])) {
        $this->applyFontStyle('contact_link');
        $doi = $publication['doi'];
        $doiUrl = strpos($doi, 'http') === 0 ? $doi : 'https://doi.org/' . $doi;
        $this->pdf->Cell(0, 0, 'DOI: ' . $doi, 0, 1, 'L', FALSE, $doiUrl);
      }
      elseif (!empty($publication['url'])) {
        $this->applyFontStyle('contact_link');
        $this->pdf->Cell(0, 0, $publication['url'], 0, 1, 'L', FALSE, $publication['url']);
      }

      // Description.
      if (!empty($publication['description'])) {
        $this->applyFontStyle('body_text');
        $this->pdf->Ln(2);
        $this->pdf->MultiCell(0, 0, $publication['description'], 0, 'L', FALSE, 1);
      }

      $this->pdf->Ln(4);
    }
  }

  /**
   * Render patents section.
   *
   * @param array $patents
   *   The patents array.
   */
  protected function renderPatents(array $patents): void {
    foreach ($patents as $patent) {
      // Title and patent number.
      $this->applyFontStyle('patent_title');
      $title = $patent['title'] ?? '';
      $this->pdf->MultiCell(0, 0, $title, 0, 'L', FALSE, 1);

      // Patent number.
      if (!empty($patent['patent_number'])) {
        $this->applyFontStyle('patent_number');
        $this->pdf->Cell(0, 0, $patent['patent_number'], 0, 1, 'L');
      }

      // Status and dates.
      $statusParts = [];
      if (!empty($patent['status'])) {
        $statusParts[] = ucfirst($patent['status']);
      }
      if (!empty($patent['filing_date'])) {
        $statusParts[] = 'Filed: ' . $patent['filing_date'];
      }
      if (!empty($patent['grant_date'])) {
        $statusParts[] = 'Granted: ' . $patent['grant_date'];
      }
      if (!empty($statusParts)) {
        $this->applyFontStyle('patent_details');
        $this->pdf->Cell(0, 0, implode(' | ', $statusParts), 0, 1, 'L');
      }

      // Inventors.
      if (!empty($patent['inventors'])) {
        $this->applyFontStyle('body_text');
        $inventors = is_array($patent['inventors']) ? implode(', ', $patent['inventors']) : $patent['inventors'];
        $this->pdf->Cell(0, 0, 'Inventors: ' . $inventors, 0, 1, 'L');
      }

      // Assignee.
      if (!empty($patent['assignee'])) {
        $this->applyFontStyle('body_text');
        $this->pdf->Cell(0, 0, 'Assignee: ' . $patent['assignee'], 0, 1, 'L');
      }

      // URL.
      if (!empty($patent['url'])) {
        $this->applyFontStyle('contact_link');
        $this->pdf->Cell(0, 0, $patent['url'], 0, 1, 'L', FALSE, $patent['url']);
      }

      // Description.
      if (!empty($patent['description'])) {
        $this->applyFontStyle('body_text');
        $this->pdf->Ln(2);
        $this->pdf->MultiCell(0, 0, $patent['description'], 0, 'L', FALSE, 1);
      }

      $this->pdf->Ln(4);
    }
  }

  /**
   * Render certifications section.
   *
   * @param array $certifications
   *   The certifications array.
   */
  protected function renderCertifications(array $certifications): void {
    foreach ($certifications as $cert) {
      // Certification name and date.
      $dateText = '';
      if (!empty($cert['issue_date'])) {
        $dateText = $cert['issue_date'];
        if (!empty($cert['expiration_date'])) {
          $dateText .= ' – ' . $cert['expiration_date'];
        }
      }
      
      if (!empty($dateText)) {
        $this->renderTwoColumnLine(
          $cert['name'] ?? '',
          $dateText,
          'certification_name',
          'certification_date'
        );
      }
      else {
        $this->applyFontStyle('certification_name');
        $this->pdf->Cell(0, 0, $cert['name'] ?? '', 0, 1, 'L');
      }

      // Issuing organization.
      if (!empty($cert['issuing_organization'])) {
        $this->applyFontStyle('certification_org');
        $this->pdf->Cell(0, 0, $cert['issuing_organization'], 0, 1, 'L');
      }

      // Credential ID.
      if (!empty($cert['credential_id'])) {
        $this->applyFontStyle('body_text');
        $this->pdf->Cell(0, 0, 'Credential ID: ' . $cert['credential_id'], 0, 1, 'L');
      }

      // Verification URL.
      if (!empty($cert['verification_url'])) {
        $this->applyFontStyle('contact_link');
        $url = $cert['verification_url'];
        $this->pdf->Cell(0, 0, $url, 0, 1, 'L', FALSE, $url);
      }

      $this->pdf->Ln(4);
    }
  }

  /**
   * Render awards and honors section.
   *
   * @param array $awards
   *   The awards array.
   */
  protected function renderAwardsAndHonors(array $awards): void {
    foreach ($awards as $award) {
      // Award title and date.
      if (!empty($award['date'])) {
        $this->renderTwoColumnLine(
          $award['title'] ?? '',
          $award['date'],
          'award_title',
          'award_date'
        );
      }
      else {
        $this->applyFontStyle('award_title');
        $this->pdf->Cell(0, 0, $award['title'] ?? '', 0, 1, 'L');
      }

      // Issuing organization.
      if (!empty($award['issuing_organization'])) {
        $this->applyFontStyle('award_org');
        $this->pdf->Cell(0, 0, $award['issuing_organization'], 0, 1, 'L');
      }

      // Description.
      if (!empty($award['description'])) {
        $this->applyFontStyle('body_text');
        $this->pdf->Ln(2);
        $this->pdf->MultiCell(0, 0, $award['description'], 0, 'L', FALSE, 1);
      }

      $this->pdf->Ln(4);
    }
  }

  /**
   * Render languages section.
   *
   * @param array $languages
   *   The languages array.
   */
  protected function renderLanguages(array $languages): void {
    $this->applyFontStyle('body_text');
    
    $languageList = [];
    foreach ($languages as $lang) {
      $langName = $lang['language'] ?? '';
      $proficiency = $lang['proficiency'] ?? '';
      
      if (!empty($langName)) {
        if (!empty($proficiency)) {
          $languageList[] = $langName . ' (' . ucfirst($proficiency) . ')';
        }
        else {
          $languageList[] = $langName;
        }
      }
    }
    
    if (!empty($languageList)) {
      $this->pdf->MultiCell(0, 0, implode(', ', $languageList), 0, 'L', FALSE, 1);
      $this->pdf->Ln(4);
    }
  }

  /**
   * Render a two-column line (left and right aligned).
   *
   * @param string $left
   *   Left text.
   * @param string $right
   *   Right text.
   * @param string $leftStyle
   *   Style for left text.
   * @param string $rightStyle
   *   Style for right text.
   */
  protected function renderTwoColumnLine(string $left, string $right, string $leftStyle, string $rightStyle): void {
    $pageWidth = $this->pdf->getPageWidth();
    $margins = $this->pdf->getMargins();
    $contentWidth = $pageWidth - $margins['left'] - $margins['right'];

    // Right text width.
    $this->applyFontStyle($rightStyle);
    $rightWidth = $this->pdf->GetStringWidth($right) + 10;

    // Left text.
    $this->applyFontStyle($leftStyle);
    $leftWidth = $contentWidth - $rightWidth;
    $this->pdf->Cell($leftWidth, 0, $left, 0, 0, 'L');

    // Right text.
    $this->applyFontStyle($rightStyle);
    $this->pdf->Cell($rightWidth, 0, $right, 0, 1, 'R');
  }

  /**
   * Render a bullet list.
   *
   * @param array $items
   *   The list items.
   * @param string $style
   *   The style name.
   */
  protected function renderBulletList(array $items, string $style = 'bullet_item'): void {
    foreach ($items as $item) {
      $text = $this->formatBulletItem($item);
      if (!empty($text)) {
        $this->renderBulletItem($text, $style);
      }
    }
  }

  /**
   * Format a bullet item from various data structures.
   *
   * @param mixed $item
   *   The item to format (string or array).
   *
   * @return string
   *   The formatted text.
   */
  protected function formatBulletItem($item): string {
    // Simple string.
    if (is_string($item)) {
      return $item;
    }

    // Not an array - skip.
    if (!is_array($item)) {
      return '';
    }

    // Standard text property.
    if (!empty($item['text'])) {
      return $item['text'];
    }

    // Key metrics format: {metric, value, context}.
    if (!empty($item['metric']) && isset($item['value'])) {
      $text = $item['metric'] . ': ' . $item['value'];
      if (!empty($item['context'])) {
        $text .= ' (' . $item['context'] . ')';
      }
      return $text;
    }

    // Achievement format: {achievement, impact, metrics[]}.
    if (!empty($item['achievement'])) {
      $text = $item['achievement'];
      if (!empty($item['impact'])) {
        $text .= ' - ' . $item['impact'];
      }
      return $text;
    }

    // Skill format: {skill, years, level}.
    if (!empty($item['skill'])) {
      $text = $item['skill'];
      if (!empty($item['years'])) {
        $text .= ' (' . $item['years'] . ' years)';
      }
      return $text;
    }

    // Name/description format.
    if (!empty($item['name'])) {
      $text = $item['name'];
      if (!empty($item['description'])) {
        $text .= ': ' . $item['description'];
      }
      return $text;
    }

    // Unknown format - log and skip (don't output raw JSON).
    \Drupal::logger('job_hunter')->warning('Unknown bullet item format: @keys', [
      '@keys' => implode(', ', array_keys($item)),
    ]);
    return '';
  }

  /**
   * Render a single bullet item.
   *
   * @param string $text
   *   The item text.
   * @param string $style
   *   The style name.
   */
  protected function renderBulletItem(string $text, string $style = 'bullet_item'): void {
    $this->applyFontStyle($style);

    $bullet = $this->getStyleProperty($style, 'bullet', '-');
    $indent = $this->getStyleProperty($style, 'indent_pt', 12);
    $marginBottom = $this->getStyleProperty($style, 'margin_bottom_pt', 2);

    $margins = $this->pdf->getMargins();
    $this->pdf->SetX($margins['left'] + $indent);

    $fullText = $bullet . ' ' . $text;
    $this->pdf->MultiCell(0, 0, $fullText, 0, 'L', FALSE, 1);
    $this->pdf->Ln($marginBottom);
  }

  /**
   * Format a date range string.
   *
   * @param string $start
   *   Start date.
   * @param string $end
   *   End date.
   *
   * @return string
   *   Formatted date range.
   */
  protected function formatDateRange(string $start, string $end): string {
    if (empty($start) && empty($end)) {
      return '';
    }
    if (empty($end) || strtolower($end) === 'present') {
      return $start . ' – Present';
    }
    return $start . ' – ' . $end;
  }

}
