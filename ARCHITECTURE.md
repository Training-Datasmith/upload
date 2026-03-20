# Architecture: upload

## Purpose

A FuelPHP package for handling HTTP file uploads. Validates uploaded files against configurable rules (size, extension, MIME type whitelists/blacklists), renames and moves them to a destination path, and exposes the result set as an iterable container.

## Directory Structure

```
src/
  Upload.php            - Main container: implements ArrayAccess, Iterator, Countable over uploaded files
  File.php              - Represents a single uploaded file with validation and move logic
  File_Error.php        - Value object describing a file error condition
  No_Files_Exception.php - Thrown when no files are present in the upload
  Providers/
    Fuel_Service_Provider.php - Registers Upload as a FuelPHP service
```

## Key Design Decisions

- **Container pattern**: `Upload` wraps `$_FILES` and presents the uploaded file set as an iterable collection of `File` objects, hiding the awkward `$_FILES` structure.
- **Whitelist/blacklist validation**: Configurable arrays for extension, file type, and MIME type filtering — supports either allowlist or blocklist strategies per upload instance.
- **Lazy processing**: By default, `auto_process` is `false`, so validation and moving only happen when `process()` is called explicitly, giving the application control over when I/O occurs.
- **Normalization and randomization**: Optional filename normalization (replacing unsafe characters) and random filename generation prevent naming conflicts and directory traversal risks.

## Extension Points

- Pass a `moveCallback` to intercept the file-move step for custom storage backends (e.g., S3 upload).
- Pass a `langCallback` to localise validation error messages.

## Dependency Flow

```
$upload = new Upload($_FILES, $config)
  └─> Upload::process()
        └─> File::validate()    — check size, extension, MIME type rules
        └─> File::move()        — rename and copy to destination path
  └─> foreach ($upload as $file) — iterate results
```
