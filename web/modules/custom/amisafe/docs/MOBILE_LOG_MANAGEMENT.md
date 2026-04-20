# Mobile Console Log Management

## Overview
The AmISafe module now includes a comprehensive console log management system for debugging mobile app issues. This system allows users to upload console logs from their mobile devices and administrators to review them through a web interface.

## Features

### For Mobile Apps
- **Log Upload API**: Simple REST API endpoint for uploading console logs
- **Automatic Replacement**: Only stores the most recent log per user
- **Device Information**: Optional device and app version metadata

### For Administrators
- **Log Management Page**: Web interface at `/admin/amisafe/logs`
- **Log Viewer**: View full console logs in a modal with syntax highlighting
- **Device Information**: View detailed device metadata
- **Download Logs**: Download logs as text files
- **Copy to Clipboard**: Quick copy functionality for sharing logs
- **Delete Logs**: Remove logs when no longer needed

## API Documentation

### Upload Log Endpoint

**URL**: `/api/amisafe/log/upload`  
**Method**: `POST`  
**Content-Type**: `application/json`  
**Authentication**: None required

**Request Body**:
```json
{
  "user_id": "user@example.com",
  "log_content": "Console log content here...",
  "device_info": "{\"model\": \"iPhone 14\", \"os\": \"iOS 17.2\"}",
  "app_version": "1.0.5"
}
```

**Required Fields**:
- `user_id` (string): Unique identifier for the user (email, device ID, UUID, etc.)
- `log_content` (string): The complete console log content

**Optional Fields**:
- `device_info` (string): JSON string with device information
- `app_version` (string): App version identifier

**Success Response**:
```json
{
  "success": true,
  "message": "Log uploaded successfully",
  "timestamp": 1705404521
}
```

**Error Response**:
```json
{
  "success": false,
  "error": "Missing required fields: user_id and log_content"
}
```

### Get Log Endpoint

**URL**: `/api/amisafe/log/{log_id}`  
**Method**: `GET`  
**Authentication**: Requires admin permissions

**Success Response**:
```json
{
  "success": true,
  "log": {
    "id": 1,
    "user_id": "user@example.com",
    "log_content": "Console log content...",
    "device_info": "{\"model\": \"iPhone 14\"}",
    "app_version": "1.0.5",
    "uploaded_at": "2026-01-15 14:30:25"
  }
}
```

### Delete Log Endpoint

**URL**: `/api/amisafe/log/{log_id}/delete`  
**Method**: `POST`  
**Authentication**: Requires admin permissions

**Success Response**:
```json
{
  "success": true,
  "message": "Log deleted successfully"
}
```

## Mobile App Integration

### React Native Example

```javascript
const uploadConsoleLog = async (userId, logContent) => {
  try {
    const response = await fetch('https://forseti.life/api/amisafe/log/upload', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        user_id: userId,
        log_content: logContent,
        device_info: JSON.stringify({
          model: DeviceInfo.getModel(),
          os: Platform.OS,
          osVersion: Platform.Version,
        }),
        app_version: DeviceInfo.getVersion(),
      }),
    });

    const data = await response.json();
    
    if (data.success) {
      console.log('Log uploaded successfully');
    } else {
      console.error('Failed to upload log:', data.error);
    }
  } catch (error) {
    console.error('Upload error:', error);
  }
};
```

### Collecting Console Logs

```javascript
// Store console logs in a buffer
let consoleBuffer = [];
const MAX_LOG_SIZE = 100000; // 100KB limit

const originalConsoleLog = console.log;
const originalConsoleError = console.error;
const originalConsoleWarn = console.warn;

console.log = (...args) => {
  const message = args.map(arg => 
    typeof arg === 'object' ? JSON.stringify(arg) : String(arg)
  ).join(' ');
  
  consoleBuffer.push(`[LOG] ${new Date().toISOString()} - ${message}`);
  originalConsoleLog.apply(console, args);
};

console.error = (...args) => {
  const message = args.map(arg => 
    typeof arg === 'object' ? JSON.stringify(arg) : String(arg)
  ).join(' ');
  
  consoleBuffer.push(`[ERROR] ${new Date().toISOString()} - ${message}`);
  originalConsoleError.apply(console, args);
};

console.warn = (...args) => {
  const message = args.map(arg => 
    typeof arg === 'object' ? JSON.stringify(arg) : String(arg)
  ).join(' ');
  
  consoleBuffer.push(`[WARN] ${new Date().toISOString()} - ${message}`);
  originalConsoleWarn.apply(console, args);
};

// Upload logs when app closes or on demand
const uploadLogs = () => {
  const logContent = consoleBuffer.join('\n');
  if (logContent.length > MAX_LOG_SIZE) {
    logContent = logContent.slice(-MAX_LOG_SIZE); // Keep last 100KB
  }
  
  uploadConsoleLog(getUserId(), logContent);
};
```

## Database Schema

```sql
CREATE TABLE amisafe_user_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id VARCHAR(255) NOT NULL,
  log_content LONGTEXT NOT NULL,
  device_info TEXT,
  app_version VARCHAR(50),
  uploaded_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY unique_user (user_id),
  KEY user_id (user_id),
  KEY uploaded_at (uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Admin Interface

### Access the Log Management Page

1. Navigate to `/admin/amisafe/logs` (requires admin permissions)
2. View list of all uploaded logs with user IDs, app versions, and upload times
3. Click "View Log" to open the full console log in a modal
4. Click "View Device Info" to see device metadata
5. Use "Copy to Clipboard" to copy logs for sharing
6. Use "Download" to save logs as text files
7. Click "Delete" to remove logs that are no longer needed

### Features

- **Searchable Table**: Easy to find logs by user ID
- **Modal Viewer**: Full-screen log viewer with syntax highlighting
- **Metadata Display**: View device info and app version
- **Quick Actions**: Copy, download, and delete with one click
- **Responsive Design**: Works on desktop and mobile browsers

## Storage Policy

- Only the **most recent log per user** is stored
- When a new log is uploaded for an existing user, the old log is automatically replaced
- Logs are stored in the database (not as files on disk)
- Maximum log size is limited by database LONGTEXT field (up to 4GB)

## Security Considerations

- **Upload Endpoint**: Public access (no authentication required) for mobile apps
- **Admin Endpoints**: Require "administer site configuration" permission
- **Input Validation**: All inputs are validated and sanitized
- **SQL Injection Protection**: Uses Drupal's database abstraction layer
- **XSS Protection**: Log content is displayed as plain text, not HTML

## Troubleshooting

### Logs not appearing
- Check that the database table `amisafe_user_logs` exists
- Verify the upload endpoint is accessible: `/api/amisafe/log/upload`
- Check PHP error logs for any server-side errors

### Upload failures
- Verify the request has correct Content-Type header
- Ensure JSON is valid
- Check required fields are present
- Review network logs for HTTP errors

### Permission denied
- Ensure admin user has "administer site configuration" permission
- Clear Drupal cache: `drush cache:rebuild`
- Check routing configuration in `amisafe.routing.yml`

## Future Enhancements

- Log filtering and search functionality
- Export multiple logs as ZIP archive
- Automatic log analysis and error detection
- Email notifications for critical errors
- Log retention policies (auto-delete after X days)
- Analytics dashboard for common errors
