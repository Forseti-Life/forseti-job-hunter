# Code Review: ResumeController.php

**File:** `ResumeController.php`  
**Size:** 467 lines  
**Status:** ✅ **APPROVED WITH MINOR IMPROVEMENTS**

---

## Executive Summary

This controller handles PDF generation and download operations for resumes. It's a well-structured controller (467 lines) that generates tailored resume PDFs, manages PDF history, and handles secure downloads. The controller already uses constructor dependency injection and implements basic security measures.

**Issues Identified:**
- 🟠 **Security:** Content-Disposition header encoding and file read limits needed
- 🟠 **Architecture:** Partial service locator usage for FileSystem and Time services
- 🟡 **Performance:** Large PDF files loaded entirely into memory
- 🟡 **Code Quality:** Some code duplication in filename generation

---

## Security Analysis

### 1. 🟠 Content-Disposition Header Encoding

**Issue (Lines 211, 437):** Filename inserted directly into Content-Disposition header without proper encoding.

**Current Code:**
```php
$response->headers->set('Content-Disposition', 'attachment; filename="' . $pdfRecord['filename'] . '"');
```

**Recommendation:**
```php
// Use RFC 5987 encoding for filenames with special characters
$encodedFilename = rawurlencode($filename);
$response->headers->set('Content-Disposition', 
  'attachment; filename="' . addslashes($filename) . '"; ' .
  "filename*=UTF-8''" . $encodedFilename
);
```

### 2. 🟠 File Read Memory Limits

**Issue (Line 207):** `file_get_contents()` loads entire PDF into memory without size limits.

**Current Code:**
```php
$pdfContent = file_get_contents($realPath);
```

**Recommendation:**
```php
// Check file size before reading
$fileSize = filesize($realPath);
$maxSize = 10 * 1024 * 1024; // 10MB limit

if ($fileSize > $maxSize) {
  throw new \Exception('PDF file too large to download');
}

$pdfContent = file_get_contents($realPath);
```

### 3. ✅ Access Control (PROPERLY IMPLEMENTED)

**Lines 194-196, 246-251:** User ownership verification is correctly implemented:
```php
if ((int) $pdfRecord['uid'] !== $userId) {
  throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access denied.');
}
```

### 4. ✅ Secure File Storage (PROPERLY IMPLEMENTED)

**Line 126:** Files are stored in private directory outside web root:
```php
$directory = 'private://job_hunter/resumes/' . $userId . '/tailoredresumes';
```

### 5. ✅ Filename Sanitization (PROPERLY IMPLEMENTED)

**Lines 454-465:** Dedicated `sanitizeFilename()` method properly sanitizes filenames:
```php
protected function sanitizeFilename(string $string): string {
  $string = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $string);
  $string = preg_replace('/\s+/', '_', $string);
  $string = substr($string, 0, 50);
  return rtrim($string, '_');
}
```

---

## Architecture Analysis

### 1. ✅ Constructor Dependency Injection (PROPERLY IMPLEMENTED)

**Lines 39-42:** Controller properly uses constructor injection for core dependencies:
```php
public function __construct(Connection $database, ResumePdfService $pdf_service) {
  $this->database = $database;
  $this->pdfService = $pdf_service;
}
```

### 2. 🟠 Partial Service Locator Usage

**Issue:** FileSystem and Time services accessed via service locator instead of DI.

**Lines 128, 200, 255:**
```php
$fileSystem = \Drupal::service('file_system');
```

**Lines 145, 158:**
```php
\Drupal::time()->getRequestTime()
```

**Recommendation:**
```php
class ResumeController extends ControllerBase {
  
  protected Connection $database;
  protected ResumePdfService $pdfService;
  protected FileSystemInterface $fileSystem;
  protected TimeInterface $time;
  
  public function __construct(
    Connection $database, 
    ResumePdfService $pdf_service,
    FileSystemInterface $file_system,
    TimeInterface $time
  ) {
    $this->database = $database;
    $this->pdfService = $pdf_service;
    $this->fileSystem = $file_system;
    $this->time = $time;
  }
  
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('job_hunter.resume_pdf_service'),
      $container->get('file_system'),
      $container->get('datetime.time')
    );
  }
}
```

### 3. 🟡 Code Duplication

**Issue:** Filename generation logic is duplicated in three places (lines 105-114, 355-363, 383-384).

**Recommendation:**
```php
protected function generateFilename(
  array $content, 
  ?string $companyName = NULL, 
  ?string $jobTitle = NULL,
  bool $includeTimestamp = FALSE
): string {
  $name = $content['contact_info']['full_name'] ?? 'Resume';
  $filename = $this->sanitizeFilename($name);
  
  if ($companyName) {
    $filename .= '_' . $this->sanitizeFilename($companyName);
  }
  if ($jobTitle) {
    $filename .= '_' . $this->sanitizeFilename($jobTitle);
  }
  if ($includeTimestamp) {
    $filename .= '_' . date('Ymd_His');
  }
  
  return $filename . '.pdf';
}
```

---

## Performance Analysis

### 1. 🟡 Large PDF File Handling

**Issue (Line 207):** Entire PDF loaded into memory with `file_get_contents()`.

**Current Code:**
```php
$pdfContent = file_get_contents($realPath);
```

**Recommendation (for very large PDFs):**
```php
// For streaming large files
$response = new BinaryFileResponse($realPath);
$response->headers->set('Content-Type', 'application/pdf');
$response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
return $response;
```

### 2. 🟡 Inefficient Query After Deletion

**Issue (Lines 268-275):** Query for latest PDF happens after deletion, requiring extra database query.

**Current Pattern:**
```php
// Delete PDF
$this->database->delete('jobhunter_pdf_history')
  ->condition('id', $pdf_id)
  ->execute();

// Then query for latest
$latestPdf = $this->database->select('jobhunter_pdf_history', 'ph')
  ->fields('ph', ['filepath', 'created'])
  // ... rest of query
```

**Recommendation:**
```php
// Query for latest before deletion
$latestPdf = $this->database->select('jobhunter_pdf_history', 'ph')
  ->fields('ph', ['filepath', 'created'])
  ->condition('uid', $userId)
  ->condition('job_id', $pdfRecord['job_id'])
  ->condition('id', $pdf_id, '!=') // Exclude current PDF
  ->orderBy('created', 'DESC')
  ->range(0, 1)
  ->execute()
  ->fetchAssoc();

// Then delete
$this->database->delete('jobhunter_pdf_history')
  ->condition('id', $pdf_id)
  ->execute();
```

---

## Error Handling

### 1. 🟡 Inconsistent Error Response Formats

**Issue:** Some methods throw exceptions while others return JSON error responses.

**Lines 190-196 (throws exception):**
```php
if (!$pdfRecord) {
  throw new NotFoundHttpException('PDF not found.');
}
```

**Lines 239-243 (returns JSON):**
```php
if (!$pdfRecord) {
  return new JsonResponse(['success' => FALSE, 'message' => 'PDF not found.'], 404);
}
```

**Recommendation:** Use consistent approach - exceptions for download endpoints, JSON for API endpoints.

### 2. 🟡 Silent File Deletion Failure

**Issue (Line 259):** `unlink()` may fail silently without logging.

**Current Code:**
```php
if ($realPath && file_exists($realPath)) {
  unlink($realPath);
}
```

**Recommendation:**
```php
if ($realPath && file_exists($realPath)) {
  if (!unlink($realPath)) {
    \Drupal::logger('job_hunter')->warning('Failed to delete PDF file: @path', ['@path' => $realPath]);
  }
}
```

### 3. 🟡 Missing Error Logging

**Issue:** No logging of important operations (PDF generation, deletion, access denials).

**Recommendation:**
```php
// Log successful operations
\Drupal::logger('job_hunter')->info('PDF generated for user @uid, job @job_id', [
  '@uid' => $userId,
  '@job_id' => $job_id,
]);

// Log access denials
\Drupal::logger('job_hunter')->warning('User @uid attempted to access PDF @pdf_id', [
  '@uid' => $userId,
  '@pdf_id' => $pdf_id,
]);
```

---

## Code Quality

### 1. ✅ Type Hints (PROPERLY USED)

Controller methods use proper type hints for parameters and return types:
```php
public function generateTailoredPdf(int $job_id): JsonResponse
public function downloadPdfById(int $pdf_id): Response
```

### 2. ✅ Documentation

Methods have proper DocBlocks explaining purpose and parameters.

### 3. 🟡 Magic Numbers

**Line 460:** Maximum filename length (50) is hardcoded.

**Recommendation:**
```php
private const MAX_FILENAME_LENGTH = 50;

$string = substr($string, 0, self::MAX_FILENAME_LENGTH);
```

---

## Testing Recommendations

1. **PDF Generation Tests:**
   - Generate PDF for job with tailored resume
   - Generate PDF with missing tailored resume (fallback to base)
   - Handle invalid JSON in tailored resume
   - Verify filename sanitization with special characters
   - Test with very long names (>50 chars)

2. **Security Tests:**
   - User cannot access another user's PDF
   - User cannot download PDF they don't own
   - User cannot delete PDF they don't own
   - Verify files stored in private directory
   - Test Content-Disposition header encoding

3. **Error Handling Tests:**
   - PDF generation failure (ResumePdfService returns NULL)
   - File save failure
   - Missing PDF file on disk
   - Database query failures

4. **PDF Deletion Tests:**
   - Delete PDF updates tailored_resumes table correctly
   - Delete last PDF clears tailored_resumes path
   - Delete middle PDF updates to latest remaining

---

## Validated Implementation Checklist

- [x] Are files stored outside web root? (private://)
- [x] Are filenames sanitized? (sanitizeFilename() method)
- [x] Are users restricted to their own PDFs? (uid checks)
- [x] Is constructor DI used? (database, pdfService)
- [x] Are proper type hints used?
- [x] Is access control implemented?
- [ ] Is file size checked before reading?
- [ ] Is Content-Disposition header properly encoded?
- [ ] Are FileSystem/Time services injected?
- [ ] Are file operations logged?
- [ ] Is error handling consistent?

---

## Recommendations Priority

| Priority | Issue | Recommendation |
|----------|-------|-----------------|
| 🟠 HIGH | Content-Disposition encoding | Add RFC 5987 encoding for special chars |
| 🟠 HIGH | File size limits | Check file size before loading into memory |
| 🟠 HIGH | Service locator usage | Inject FileSystem and Time services |
| 🟡 MEDIUM | Code duplication | Extract filename generation to method |
| 🟡 MEDIUM | Missing logging | Log PDF operations and access denials |
| 🟡 MEDIUM | Inconsistent errors | Standardize error response format |
| 🟡 MEDIUM | Magic numbers | Use constants for max filename length |
| 🟡 LOW | Query optimization | Fetch latest PDF before deletion |

---

## Estimated Effort

- **Security improvements (encoding, size limits):** 1-2 hours
- **Dependency injection refactoring:** 1 hour
- **Code quality (duplication, constants):** 1 hour
- **Logging improvements:** 30 minutes
- **Testing:** 2-3 hours

**Total Estimated Effort:** 5-7 hours

---

## Implementation Order

1. **First (Security):** Content-Disposition encoding and file size limits
2. **Second (Architecture):** Inject FileSystem and Time services
3. **Third (Code Quality):** Extract filename generation, add constants
4. **Fourth (Observability):** Add logging for operations
5. **Fifth (Testing):** Add comprehensive tests

---

**Review Confidence:** HIGH  
**Last Updated:** 2026-02-13  
**Reviewer Notes:** Controller is well-structured with proper DI, access control, and secure file storage. Minor improvements needed for header encoding, service injection, and logging.

