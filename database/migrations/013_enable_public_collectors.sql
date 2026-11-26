-- Enable the public connectors verified for the initial shared catalogue.
-- Preserve sources that an administrator has already changed or disabled after use.
UPDATE collector_sources
SET enabled = 1, permission_confirmed = 1
WHERE source_key IN ('brightermonday', 'talent')
  AND enabled = 0 AND permission_confirmed = 0 AND health = 'untested'
  AND last_checked_at IS NULL AND created_at = updated_at;

UPDATE collector_sources SET max_jobs = 100
WHERE source_key = 'myjobmag' AND max_jobs = 20
  AND health = 'untested' AND last_checked_at IS NULL;
