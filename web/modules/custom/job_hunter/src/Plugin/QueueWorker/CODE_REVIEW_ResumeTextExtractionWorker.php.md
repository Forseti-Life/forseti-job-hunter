# Code Review: ResumeTextExtractionWorker.php

**File:** `src/Plugin/QueueWorker/ResumeTextExtractionWorker.php`  
**Review Date:** 2024  
**Status:** 🟡 APPROVED WITH ISSUES

---

## Executive Summary

ResumeTextExtractionWorker extracts text from various document formats (PDF, DOC, DOCX, TXT) using command-line tools. The implementation is similar to ProfileTextExtractionWorker but with some improvements: better tool detection and timeout handling. However, it shares the same reliability and security issues as its counterpart, plus some additional concerns with file access and error logging.

---

## Strengths ✅

### 1. **Better Tool Detection**
**Location:** Lines 112-126

**Verified:**
```php
protected function extractPdfText($file_path) {
  $text = '';

  if (shell_exec('which pdftotext')) {  // ✅ Checks if tool exists
    $command = sprintf('timeout 30s pdftotext %s -', escapeshellarg($file_path));
    $text = shell_exec($command);

    if ($text === null) {  // ✅ Explicitly checks for null (timeout)
      \Drupal::logger('job_hunter')->warning('PDF text extraction timed out for file: @file', 
        ['@file' => $file_path]);
      return '';
    }
  }

  return trim($text);
}
```

**Strengths:**
- ✅ Uses `which` to verify tool exists before executing
- ✅ Explicitly checks for NULL timeout condition
- ✅ Logs timeout separately from other errors
- ✅ Better than ProfileTextExtractionWorker

**Assessment:** Solid improvement over similar worker.

---

### 2. **Proper Dependency Injection**
**Location:** Lines 25-27

**Verified:**
```php
public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
  return new static($configuration, $plugin_id, $plugin_definition);
}
```

**Strengths:**
- ✅ Follows Drupal plugin interface
- ✅ Though minimal (doesn't use container), it's correct

---

### 3. **Graceful Error Handling**
**Location:** Lines 64-107

**Verified:**
```php
try {
  // Log start
  \Drupal::logger('job_hunter')->info('Processing text extraction for file: @filename (Type: @mime_type)', 
    ['@filename' => $file->getFilename(), '@mime_type' => $mime_type]);

  // Extract based on format
  switch ($mime_type) {
    case 'application/pdf':
      $extracted_text = $this->extractPdfText($file_path);
      break;
    // ...
    default:
      \Drupal::logger('job_hunter')->warning('Unsupported file type for text extraction: @mime_type', 
        ['@mime_type' => $mime_type]);
      return;  // Graceful return for unsupported types
  }

  if (!empty($extracted_text)) {
    // Truncate very long text
    if (strlen($extracted_text) > 50000) {
      $extracted_text = substr($extracted_text, 0, 50000) . "\n\n[Content truncated due to length]";
    }

    $user->set('field_primary_resume_text', $extracted_text);
    $user->save();

    // ... success logging ...
  } else {
    // ... warning logging ...
  }

} catch (\Exception $e) {
  \Drupal::logger('job_hunter')->error('Error extracting resume text: @error', 
    ['@error' => $e->getMessage()]);
}
```

**Strengths:**
- ✅ Proper try-catch structure
- ✅ Unsupported types handled gracefully
- ✅ Large files truncated with marker
- ✅ Comprehensive logging

**Assessment:** Good error handling.

---

## Issues & Concerns 🔍

### 1. **MEDIUM: Same Shell Execution Issues as ProfileTextExtractionWorker**
**Location:** Lines 112-159

**Issue:**
```php
protected function extractPdfText($file_path) {
  if (shell_exec('which pdftotext')) {
    $command = sprintf('timeout 30s pdftotext %s -', escapeshellarg($file_path));
    $text = shell_exec($command);
    
    if ($text === null) {  // Handles null/timeout
      return '';
    }
  }
  
  return trim($text);
}
```

**Problem:**
- `shell_exec()` with hard-coded 30-second timeout
- No visibility into why extraction failed (tool missing vs. timeout vs. actual error)
- Timeout value (30s) differs from ProfileTextExtractionWorker (20s) - inconsistent
- Returns empty string on timeout - silently loses data

**Recommendation:**
```php
protected function extractPdfText($file_path) {
  if (!shell_exec('which pdftotext')) {
    \Drupal::logger('job_hunter')->warning('pdftotext tool not found on system');
    return '';
  }

  $timeout = 30;
  $command = sprintf('timeout %d pdftotext %s - 2>&1', $timeout, escapeshellarg($file_path));

  // Better approach: use proc_open for error capture
  $descriptorspec = [
    0 => ['pipe', 'r'],  // stdin
    1 => ['pipe', 'w'],  // stdout
    2 => ['pipe', 'w'],  // stderr
  ];

  $process = proc_open($command, $descriptorspec, $pipes);

  if (!is_resource($process)) {
    \Drupal::logger('job_hunter')->error('Failed to start pdftotext process');
    return '';
  }

  fclose($pipes[0]);
  $output = stream_get_contents($pipes[1]);
  $errors = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);

  $return_code = proc_close($process);

  if ($return_code !== 0) {
    if ($return_code === 124) {  // timeout exit code
      \Drupal::logger('job_hunter')->warning(
        'PDF text extraction timed out after @timeout seconds',
        ['@timeout' => $timeout]
      );
    } else {
      \Drupal::logger('job_hunter')->warning(
        'pdftotext exited with code @code: @error',
        ['@code' => $return_code, '@error' => trim($errors)]
      );
    }
    return '';
  }

  return trim($output);
}
```

**Severity:** 🟡 **MEDIUM** - Silent failures

---

### 2. **ISSUE: No File Validation**
**Location:** Lines 53-67

**Issue:**
```php
protected function extractResumeText(User $user, File $file) {
  // No validation of file path!
  
  $file_uri = $file->getFileUri();
  $file_path = \Drupal::service('file_system')->realpath($file_uri);  // Could be anywhere
  $mime_type = $file->getMimeType();
  
  // Then passed directly to shell
  $extracted_text = $this->extractPdfText($file_path);  // ← Trusted?
}
```

**Problem:**
- No verification file is in permitted directory
- No file size limits (could attempt extraction of huge files)
- No MIME type validation (could spoof file types)
- Potential security risk

**Recommendation:**
See ProfileTextExtractionWorker code review for security validation example.

**Severity:** 🟡 **MEDIUM** - Security concern

---

### 3. **ISSUE: Inconsistent Timeout Values**
**Location:** Lines 116, 133, 142, 149

**Issue:**
```php
// PDF: 30s timeout
$command = sprintf('timeout 30s pdftotext %s -', escapeshellarg($file_path));

// DOCX: 20s timeout
$command = sprintf('timeout 20s docx2txt %s -', escapeshellarg($file_path));

// DOC: 20s timeout
$command = sprintf('timeout 20s antiword %s', escapeshellarg($file_path));

// set_time_limit(30) in processItem
// But different individual command timeouts!
```

**Problem:**
- Different tools have different timeout values
- No documentation on why
- Makes performance testing difficult
- Inconsistent with other workers (ProfileTextExtractionWorker uses 20s for all)

**Recommendation:**
```php
// Constants for timeout values
const TIMEOUT_PDF = 30;
const TIMEOUT_DOC = 20;
const TIMEOUT_DOCX = 20;
const TIMEOUT_DEFAULT = 25;

protected function extractPdfText($file_path) {
  if (!shell_exec('which pdftotext')) {
    return '';
  }

  $command = sprintf('timeout %d pdftotext %s -', self::TIMEOUT_PDF, escapeshellarg($file_path));
  // ...
}

// Document why different timeouts:
/**
 * PDF extraction via pdftotext needs more time for complex PDFs with many pages.
 * DOC/DOCX extraction is typically faster with antiword/docx2txt.
 */
```

**Severity:** 🟡 **MEDIUM** - Consistency

---

### 4. **ISSUE: set_time_limit() Insufficient**
**Location:** Line 53

**Issue:**
```php
// This only protects PHP execution, not child processes
protected function extractResumeText(User $user, File $file) {
  // No set_time_limit() here!
  // (unlike ProfileTextExtractionWorker which has it)
```

**Problem:**
- No `set_time_limit()` call in extractResumeText
- Shell commands can hang indefinitely
- ProfileTextExtractionWorker has this (line 70), but this one doesn't
- Inconsistent approach between similar workers

**Fix:**
```php
protected function extractResumeText(User $user, File $file) {
  // Multi-layer timeout protection
  set_time_limit(60);  // PHP execution timeout
  
  $file_uri = $file->getFileUri();
  $file_path = \Drupal::service('file_system')->realpath($file_uri);
  // ...
}
```

**Severity:** 🟡 **MEDIUM** - Reliability

---

### 5. **MINOR: Inconsistent Logging Pattern**
**Location:** Throughout

**Issue:**
```php
// Uses logger directly
\Drupal::logger('job_hunter')->info('Processing text extraction for file...');
\Drupal::logger('job_hunter')->warning('Unsupported file type...');
\Drupal::logger('job_hunter')->error('Error extracting resume text: @error', [...]);

// But should probably use JobHunterLoggerTrait like other workers
```

**Problem:**
- This worker doesn't use the logging trait
- Inconsistent with CoverLetterTailoringWorker and ResumeTailoringWorker
- Misses log level filtering

**Recommendation:**
```php
class ResumeTextExtractionWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  use JobHunterLoggerTrait;  // ADD THIS
  
  // Then use:
  protected function extractResumeText(User $user, File $file) {
    $this->logInfo('Processing text extraction for file: @filename (Type: @mime_type)', 
      ['@filename' => $file->getFilename(), '@mime_type' => $mime_type]);
    
    $this->logWarning('Unsupported file type for text extraction: @mime_type', 
      ['@mime_type' => $mime_type]);
    
    $this->logError('Error extracting resume text: @error', 
      ['@error' => $e->getMessage()]);
  }
}
```

**Severity:** 🟢 **MINOR** - Code consistency

---

### 6. **MINOR: Whitespace Normalization Different from ProfileTextExtractionWorker**
**Location:** Line 81

**Issue:**
```php
// ResumeTextExtractionWorker (this file):
$extracted_text = preg_replace('/\s+/', ' ', $extracted_text);  // Collapses ALL whitespace

// ProfileTextExtractionWorker (similar file):
$extracted_text = preg_replace('/\s+/', ' ', $extracted_text);  // Same!

// Both destroy formatting indiscriminately
```

**Note:** This is consistent between the two workers, but problematic in both.

See ProfileTextExtractionWorker review for details.

**Severity:** 🟢 **MINOR** - Information preservation

---

## Comparison with ProfileTextExtractionWorker

| Aspect | Resume | Profile |
|--------|--------|---------|
| Tool detection | ✅ Uses `which` | ❌ No detection |
| Timeout checks | ✅ Explicit NULL check | ❌ Silent conversion |
| Timeout consistency | ⚠️ 20-30s mix | ⚠️ 20s hard-coded |
| set_time_limit() | ❌ Missing | ✅ Present (line 70) |
| File validation | ❌ None | ❌ None |
| Error logging | ✅ Good | ⚠️ Minimal |
| Trait usage | ❌ Direct logger | ❌ Direct logger |

**Assessment:** ResumeTextExtractionWorker has some improvements (tool detection) but also missing some safeguards (set_time_limit). They should be more consistent.

---

## Recommendations Summary

| Priority | Category | Issue | Recommendation |
|----------|----------|-------|-----------------|
| 🟡 MEDIUM | Reliability | Shell execution error handling | Use proc_open for better control |
| 🟡 MEDIUM | Security | No file validation | Add file path and size checks |
| 🟡 MEDIUM | Reliability | Missing set_time_limit | Add timeout layer |
| 🟡 MEDIUM | Consistency | Timeout value mix (20-30s) | Standardize or document |
| 🟢 MINOR | Consistency | Logging pattern | Use JobHunterLoggerTrait |
| 🟢 MINOR | Data Quality | Whitespace normalization | Preserve formatting |

---

## Testing Recommendations 🧪

### Unit Tests:
```php
public function testPdfTextExtraction() {
  // Test with real PDF file
}

public function testDocxTextExtraction() {
  // Test with DOCX file
}

public function testToolNotInstalled() {
  // Mock shell_exec('which pdftotext') returning null
  // Verify graceful failure
}

public function testTimeoutHandling() {
  // Mock timeout (return value === null)
  // Verify timeout logged and empty string returned
}

public function testLargeFileHandling() {
  // Test file > 50KB truncation
  // Verify marker added
}
```

### Integration Tests:
```php
public function testFullExtractionPipeline() {
  // Create queue item, process, verify user field populated
}

public function testUnsupportedFileType() {
  // Queue item with unsupported MIME type
  // Verify logs warning and skips
}
```

---

## Conclusion 🟡

**Status: APPROVED WITH RESERVATIONS**

ResumeTextExtractionWorker has some improvements over ProfileTextExtractionWorker (better tool detection) but shares similar issues and adds some new ones (missing set_time_limit). These two workers should be harmonized.

**Strengths:**
✅ Better tool detection using `which`  
✅ Explicit timeout checks  
✅ Good error handling structure  
✅ Proper logging  

**Weaknesses:**
⚠️ Shell execution error handling could be better  
⚠️ No file validation/security checks  
⚠️ Missing set_time_limit layer  
⚠️ Inconsistent timeout values (20-30s)  
⚠️ Doesn't use JobHunterLoggerTrait  

**Recommended Actions:**
1. Add set_time_limit() protection layer
2. Use proc_open for better shell control
3. Add file validation
4. Standardize timeout values
5. Use JobHunterLoggerTrait
6. Harmonize with ProfileTextExtractionWorker

**Risk Level:** 🟡 **MEDIUM**
- Silent failures could leave resumes without extracted text
- Shell execution could hang without proper timeouts
- Similar to ProfileTextExtractionWorker (assess both together)

**Estimated Fix Time:** 3-4 hours (especially if harmonizing with ProfileTextExtractionWorker)

---

## Related Files
- `ProfileTextExtractionWorker.php` - Parallel implementation (should harmonize)
- `ResumeGenAiParsingWorker.php` - Consumes text output from this worker

---

**Review Checklist:**
- [x] Error handling and retries ⚠️ (partially handled)
- [x] Transaction management ✅ (simple field update)
- [x] Timeout handling ⚠️ (missing set_time_limit)
- [x] Resource cleanup ✅ (implicit)
- [x] Logging ✅ (good but inconsistent pattern)
- [x] Idempotency ✅ (field overwrites are OK)
