# Code Review: ResumePdfService.php

## Overview
The `ResumePdfService` generates PDF resumes from JSON content using TCPDF library and configurable style schemas. It handles PDF layout, styling, and file system operations.

---

## ✅ Strengths

### 1. **Flexible Architecture**
- Style schema-based rendering (separates data from presentation)
- Dot-notation path helpers for nested data access
- Support for multiple style schemas

### 2. **File System Management**
- Uses Drupal file system service (not raw file operations)
- User-specific directory structure for privacy
- Proper directory creation with permissions

### 3. **TCPDF Integration**
- Custom page size support
- Comprehensive PDF metadata (creator, author, title)
- Font management with weight/style mapping
- Margin and page break handling

---

## ⚠️ Issues & Recommendations

### 1. **Missing Input Validation** (HIGH)
**Current State:** No validation of content or style schema name

**Issue:** Invalid content or missing schema silently fails.

**Recommendation:**
```php
public function generatePdf(array $content, string $style_schema_name = 'keith_aumiller'): ?string {
    // Validate inputs
    if (empty($content)) {
        throw new \InvalidArgumentException('Content array cannot be empty');
    }
    
    if (empty($style_schema_name) || !is_string($style_schema_name)) {
        throw new \InvalidArgumentException('Style schema name must be a non-empty string');
    }
    
    // Prevent directory traversal attacks
    if (strpos($style_schema_name, '/') !== false || 
        strpos($style_schema_name, '\\') !== false ||
        strpos($style_schema_name, '..') !== false) {
        throw new \InvalidArgumentException(
            'Invalid style schema name: contains path separators'
        );
    }
    
    // Validate required content fields
    if (empty($content['contact_info'])) {
        $this->logger->warning('Resume missing contact_info section');
    }
    
    $this->content = $content;
    
    // Load the style schema
    if (!$this->loadStyleSchema($style_schema_name)) {
        return NULL;
    }
    
    // ... rest of method
}
```

### 2. **No File Path Validation in generateAndSavePdf** (HIGH)
**Current State:** `$filename` parameter not validated

**Issue:** Could create files outside intended directory via path traversal.

**Recommendation:**
```php
public function generateAndSavePdf(
    array $content,
    string $filename,
    string $style_schema_name = 'keith_aumiller',
    ?int $userId = NULL
): ?string {
    // Validate filename (prevent path traversal)
    if (strpos($filename, '/') !== false || 
        strpos($filename, '\\') !== false ||
        strpos($filename, '..') !== false) {
        throw new \InvalidArgumentException('Filename cannot contain path separators');
    }
    
    // Ensure filename has .pdf extension
    if (!str_ends_with(strtolower($filename), '.pdf')) {
        $filename .= '.pdf';
    }
    
    // Sanitize filename
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    
    if (strlen($filename) > 255) {
        $filename = substr($filename, 0, 250) . '.pdf';
    }
    
    $pdfContent = $this->generatePdf($content, $style_schema_name);

    if ($pdfContent === NULL) {
        return NULL;
    }

    // Use provided userId or current user
    $uid = $userId ?? \Drupal::currentUser()->id();
    
    if (!is_numeric($uid) || $uid <= 0) {
        throw new \InvalidArgumentException('Invalid user ID');
    }
    
    // ... rest of method
}
```

### 3. **JSON Schema File Loading Vulnerable** (MEDIUM)
**Current State:** `loadStyleSchema()` doesn't validate loaded JSON

**Issue:** Invalid/malicious JSON schema could crash PDF generation.

**Recommendation:**
```php
protected function loadStyleSchema(string $name): bool {
    $module_path = \Drupal::service('extension.list.module')->getPath('job_hunter');
    
    // Validate schema name doesn't contain path traversal
    if (strpos($name, '/') !== false || strpos($name, '..') !== false) {
        $this->logger->error('Invalid schema name: contains path traversal');
        return FALSE;
    }
    
    $schema_path = $module_path . '/config/resume_styles/' . $name . '.json';

    if (!file_exists($schema_path)) {
        $this->logger->error('Style schema not found: @path', ['@path' => $schema_path]);
        return FALSE;
    }
    
    // Validate file is in expected directory
    $real_module_path = realpath($module_path . '/config/resume_styles');
    $real_schema_path = realpath($schema_path);
    
    if (strpos($real_schema_path, $real_module_path) !== 0) {
        $this->logger->error('Schema path is outside allowed directory: @path', 
                           ['@path' => $real_schema_path]);
        return FALSE;
    }

    $json = file_get_contents($schema_path);
    $this->styleSchema = json_decode($json, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->error('Invalid style schema JSON: @error', 
                           ['@error' => json_last_error_msg()]);
        return FALSE;
    }
    
    // Validate required schema fields
    if (empty($this->styleSchema['page']) || empty($this->styleSchema['styles'])) {
        $this->logger->error('Style schema missing required fields');
        return FALSE;
    }

    return TRUE;
}
```

### 4. **Font Mapping Incomplete** (MEDIUM)
**Current State:** Hard-coded font map

**Issue:** Fonts not in map silently default.

**Recommendation:**
```php
protected function applyFontStyle(string $style_name): void {
    $styles = $this->styleSchema['styles'] ?? [];
    $fonts = $this->styleSchema['fonts'] ?? [];
    $style = $styles[$style_name] ?? $styles['body_text'] ?? [];

    if (empty($style)) {
        $this->logger->warning(
            'Style not found: @style, using body_text fallback',
            ['@style' => $style_name]
        );
    }

    // Get font definition
    $fontKey = $style['font'] ?? 'primary';
    $font = $fonts[$fontKey] ?? ['family' => 'Helvetica', 'weight' => 'normal'];
    
    if (!isset($fonts[$fontKey])) {
        $this->logger->warning(
            'Font not found: @font, using Helvetica fallback',
            ['@font' => $fontKey]
        );
    }

    // Map font weight/style to TCPDF style string
    $tcpdfStyle = '';
    if (($font['weight'] ?? 'normal') === 'bold') {
        $tcpdfStyle .= 'B';
    }
    if (($font['style'] ?? 'normal') === 'italic') {
        $tcpdfStyle .= 'I';
    }

    $family = $font['family'] ?? 'Helvetica';
    $family = strtolower($family);
    
    // Map common fonts to TCPDF built-in fonts
    $fontMap = [
        'tahoma' => 'helvetica',
        'arial' => 'helvetica',
        'times new roman' => 'times',
        'times' => 'times',
        'courier new' => 'courier',
        'courier' => 'courier',
    ];
    
    $family = $fontMap[$family] ?? $family;
    
    // Validate font is TCPDF-compatible
    $valid_fonts = ['helvetica', 'times', 'courier', 'symbol', 'zapfdingbats'];
    if (!in_array($family, $valid_fonts)) {
        $this->logger->warning(
            'Font not TCPDF-compatible: @font, using Helvetica',
            ['@font' => $family]
        );
        $family = 'helvetica';
    }

    $size = max(6, min(72, (int) ($style['size_pt'] ?? 11))); // Reasonable bounds

    $this->pdf->SetFont($family, $tcpdfStyle, $size);

    // Set text color
    $color = $style['color'] ?? '#000000';
    $this->setColorFromHex($color);
}
```

### 5. **Color Validation Missing** (MEDIUM)
**Current State:** `setColorFromHex()` doesn't validate hex format

**Issue:** Invalid colors cause issues.

**Recommendation:**
```php
protected function setColorFromHex(string $hex): void {
    // Validate hex format
    if (!preg_match('/^#?[0-9A-Fa-f]{6}$/', $hex)) {
        $this->logger->warning('Invalid hex color: @hex, using black', ['@hex' => $hex]);
        $hex = '#000000';
    }
    
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    
    // Validate RGB values (should be 0-255)
    if ($r < 0 || $r > 255 || $g < 0 || $g > 255 || $b < 0 || $b > 255) {
        $this->logger->warning('Invalid RGB values from hex: @hex', ['@hex' => '#' . $hex]);
        $r = $g = $b = 0; // Default to black
    }
    
    $this->pdf->SetTextColor($r, $g, $b);
}
```

### 6. **No Error Recovery in Output** (MEDIUM)
**Current State:** PDF output errors not caught

**Issue:** Could leave corrupt file.

**Recommendation:**
```php
public function generatePdf(array $content, string $style_schema_name = 'keith_aumiller'): ?string {
    // ... initialization and rendering ...
    
    try {
        // Return PDF content
        return $this->pdf->Output('', 'S');
    } catch (\Exception $e) {
        $this->logger->error('Failed to output PDF: @error', [
            '@error' => $e->getMessage(),
        ]);
        return NULL;
    }
}

public function generateAndSavePdf(...): ?string {
    $pdfContent = $this->generatePdf($content, $style_schema_name);

    if ($pdfContent === NULL) {
        $this->logger->error('PDF generation failed, not saving file');
        return NULL;
    }
    
    try {
        // Create user-specific tailored resumes directory
        $directory = 'private://job_hunter/resumes/' . $uid . '/tailoredresumes';
        $this->fileSystem->prepareDirectory(
            $directory,
            FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
        );

        // Save file
        $filepath = $directory . '/' . $filename;
        $saved = $this->fileSystem->saveData($pdfContent, $filepath, 
                                             FileSystemInterface::EXISTS_REPLACE);
        
        if (!$saved) {
            throw new \Exception('File save operation returned false');
        }
        
        $this->logger->info('PDF saved: @path', ['@path' => $saved]);
        return $saved;
        
    } catch (\Exception $e) {
        $this->logger->error('Failed to save PDF: @error', [
            '@error' => $e->getMessage(),
        ]);
        return NULL;
    }
}
```

### 7. **No Timeout Protection for Large PDFs** (LOW)
**Current State:** No limits on content size

**Issue:** Very large resumes could cause memory exhaustion.

**Recommendation:**
```php
private const MAX_CONTENT_SIZE = 5000000; // 5MB of content
private const MAX_PAGES = 10;

public function generatePdf(array $content, string $style_schema_name = 'keith_aumiller'): ?string {
    // Check content size
    $content_size = strlen(json_encode($content));
    if ($content_size > self::MAX_CONTENT_SIZE) {
        throw new \Exception(
            'Resume content too large: ' . round($content_size / 1024 / 1024, 2) . 'MB'
        );
    }
    
    // ... rest of method
}
```

### 8. **Missing Type Hints** (MEDIUM)
**Current State:**
```php
public function generatePdf(array $content, string $style_schema_name = 'keith_aumiller'): ?string
```

**Issue:** Some internal methods lack type hints.

**Recommendation:**
```php
protected function loadStyleSchema(string $name): bool
protected function initializePdf(): void
protected function applyFontStyle(string $style_name): void
protected function setColorFromHex(string $hex): void
protected function getContentValue(string $path, $default = ''): mixed
protected function getStyleProperty(string $style_name, string $property, $default = null): mixed
```

### 9. **User ID Validation Missing** (HIGH)
**Current State:** Line 116 uses user ID without validation

**Issue:** Could create files for arbitrary users.

**Recommendation:**
```php
public function generateAndSavePdf(
    array $content,
    string $filename,
    string $style_schema_name = 'keith_aumiller',
    ?int $userId = NULL
): ?string {
    // ... other validation ...
    
    // Use provided userId or current user
    $uid = $userId ?? \Drupal::currentUser()->id();
    
    // Validate user ID
    if (!is_numeric($uid) || $uid <= 0) {
        throw new \InvalidArgumentException('Invalid user ID: ' . var_export($uid, true));
    }
    
    // If userId provided, verify current user has permission to save for that user
    if ($userId !== null && $userId !== \Drupal::currentUser()->id()) {
        // Only admin or the user themselves can save for other users
        $current_user = \Drupal::currentUser();
        if (!$current_user->hasPermission('administer job_hunter')) {
            throw new \Drupal\Core\Access\AccessDeniedException(
                'You cannot save resumes for other users'
            );
        }
    }
    
    // ... rest of method
}
```

---

## 🔍 Testing Considerations

1. **Unit Tests:**
   - Test PDF generation with valid content
   - Test with missing/partial content
   - Test font mapping
   - Test color validation
   - Test file path handling

2. **Integration Tests:**
   - Test file system operations
   - Test with various style schemas
   - Test directory structure creation

3. **Edge Cases:**
   - Very long names/text
   - Special characters in filename
   - Invalid JSON schema
   - Missing fonts
   - Invalid color codes
   - Large content
   - Directory permission issues

---

## 📋 Summary

| Category | Status | Priority |
|----------|--------|----------|
| TCPDF Integration | ✅ Good | - |
| File System Handling | ✅ Good | - |
| Styling System | ✅ Flexible | - |
| Input Validation | ❌ Missing | HIGH |
| Security (Path Traversal) | ⚠️ Weak | HIGH |
| User ID Validation | ❌ Missing | HIGH |
| Font Validation | ⚠️ Weak | MEDIUM |
| Schema Validation | ⚠️ Weak | MEDIUM |
| Color Validation | ⚠️ Missing | MEDIUM |
| Error Handling | ⚠️ Partial | MEDIUM |

---

## Action Items

- [ ] Add comprehensive input validation for content and schema names
- [ ] Add path traversal protection for filename and schema names
- [ ] Add user ID validation and permission checks
- [ ] Add font validation and better fallbacks
- [ ] Add schema validation and structure checking
- [ ] Add color format validation
- [ ] Add maximum content size limits
- [ ] Improve error handling and recovery
- [ ] Add type hints to all methods
- [ ] Create comprehensive security tests
