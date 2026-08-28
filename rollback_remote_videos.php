<?php

/**
 * Deletes remote_video media entities created by import_remote_videos.php.
 *
 * Matches by URL against the same CSV used for the import, so it only ever
 * touches the 21 rows from that file - safe to run even if other remote_video
 * media were added before or after the import.
 *
 * Run inside the ddev web container via drush's script runner:
 *   ddev drush scr scripts/rollback_remote_videos.php -- scripts/sharat_videos_approved.csv
 *   ddev drush scr scripts/rollback_remote_videos.php -- scripts/sharat_videos_approved.csv --dry-run
 */

use Drupal\media\Entity\Media;

$args = $extra ?? [];
$dry_run = in_array('--dry-run', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--dry-run'));

if (empty($args[0])) {
  echo "Usage: drush scr rollback_remote_videos.php -- <path-to-csv> [--dry-run]\n";
  return;
}

$csv_path = $args[0];
if (!is_readable($csv_path)) {
  echo "Cannot read CSV file: $csv_path\n";
  return;
}

// Collect the URLs from the CSV.
$handle = fopen($csv_path, 'r');
$header = fgetcsv($handle);
$urls = [];
while (($row = fgetcsv($handle)) !== FALSE) {
  $data = array_combine($header, $row);
  $url = trim($data['url'] ?? '');
  if ($url !== '') {
    $urls[$url] = TRUE;
  }
}
fclose($handle);

// Find matching remote_video media.
$storage = \Drupal::entityTypeManager()->getStorage('media');
$ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('bundle', 'remote_video')
  ->execute();

$to_delete = [];
foreach (Media::loadMultiple($ids) as $media) {
  $url = trim((string) $media->get('field_media_oembed_video')->value);
  if (isset($urls[$url])) {
    $to_delete[] = $media;
  }
}

if (empty($to_delete)) {
  echo "No matching remote_video media found for the URLs in $csv_path.\n";
  return;
}

foreach ($to_delete as $media) {
  if ($dry_run) {
    echo "[dry-run] would delete media {$media->id()} - {$media->label()}\n";
    continue;
  }
  echo "Deleting media {$media->id()} - {$media->label()}\n";
  $media->delete();
}

$verb = $dry_run ? 'would be deleted' : 'deleted';
echo "\nDone. " . count($to_delete) . " $verb.\n";
