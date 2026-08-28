<?php

/**
 * Creates "Remote video" media entities from a CSV of title/upload_date/url.
 *
 * Run inside the ddev web container via drush's script runner:
 *   ddev drush scr /path/to/import_remote_videos.php -- /path/to/sharat_videos_approved.csv
 *   ddev drush scr /path/to/import_remote_videos.php -- /path/to/sharat_videos_approved.csv --tag=94
 *   ddev drush scr /path/to/import_remote_videos.php -- /path/to/sharat_videos_approved.csv --tag=94,95,96
 *   ddev drush scr /path/to/import_remote_videos.php -- /path/to/sharat_videos_approved.csv --dry-run
 *
 * --tag=<tid>[,<tid>...] is optional: a single term id, or a comma-delimited
 * list (no spaces). When given, those taxonomy terms are set on field_tags
 * for every media entity created in this run (existing/skipped rows are
 * left alone). Every term id must already exist.
 *
 * Skips any URL that already exists on a remote_video media entity, so it's
 * safe to re-run after fixing a bad row.
 */

use Drupal\media\Entity\Media;
use Drupal\taxonomy\Entity\Term;

$args = $extra ?? [];
$dry_run = in_array('--dry-run', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--dry-run'));

$tag_tids = [];
foreach ($args as $arg) {
  if (str_starts_with($arg, '--tag=')) {
    $tag_tids = array_filter(array_map('intval', explode(',', substr($arg, strlen('--tag=')))));
  }
}
$args = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--tag=')));

if ($tag_tids) {
  $labels = [];
  foreach ($tag_tids as $tid) {
    $tag_term = Term::load($tid);
    if (!$tag_term) {
      echo "Tag term $tid does not exist.\n";
      return;
    }
    $labels[] = "$tid ({$tag_term->label()})";
  }
  echo 'Tagging created media with terms: ' . implode(', ', $labels) . "\n";
}

if (empty($args[0])) {
  echo "Usage: drush scr import_remote_videos.php -- <path-to-csv> [--tag=<tid>[,<tid>...]] [--dry-run]\n";
  return;
}

$csv_path = $args[0];
if (!is_readable($csv_path)) {
  echo "Cannot read CSV file: $csv_path\n";
  return;
}

// Build a lookup of existing remote_video URLs so re-runs are safe.
$storage = \Drupal::entityTypeManager()->getStorage('media');
$existing_ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('bundle', 'remote_video')
  ->execute();
$existing_urls = [];
foreach (Media::loadMultiple($existing_ids) as $media) {
  $url = $media->get('field_media_oembed_video')->value;
  if ($url) {
    $existing_urls[trim($url)] = $media->id();
  }
}

$handle = fopen($csv_path, 'r');
$header = fgetcsv($handle);
$created = 0;
$skipped = 0;
$row_num = 1;

while (($row = fgetcsv($handle)) !== FALSE) {
  $row_num++;
  $data = array_combine($header, $row);
  $title = trim($data['title'] ?? '');
  $upload_date = trim($data['upload_date'] ?? '');
  $url = trim($data['url'] ?? '');

  if ($title === '' || $url === '') {
    echo "Row $row_num: missing title or url, skipping.\n";
    $skipped++;
    continue;
  }

  if (isset($existing_urls[$url])) {
    echo "Row $row_num: already exists as media {$existing_urls[$url]} ($title), skipping.\n";
    $skipped++;
    continue;
  }

  if ($dry_run) {
    echo "Row $row_num: [dry-run] would create \"$title\" -> $url\n";
    $created++;
    continue;
  }

  $media = Media::create([
    'bundle' => 'remote_video',
    'name' => $title,
    'field_media_oembed_video' => $url,
    'field_title' => $title,
    'status' => 1,
    'uid' => 1,
  ]);
  if ($upload_date) {
    $media->setCreatedTime(strtotime($upload_date));
  }
  if ($tag_tids) {
    $media->set('field_tags', array_map(fn($tid) => ['target_id' => $tid], $tag_tids));
  }
  $media->save();

  echo "Row $row_num: created media {$media->id()} - $title\n";
  $created++;
}

fclose($handle);

$verb = $dry_run ? 'would be created' : 'created';
echo "\nDone. $created $verb, $skipped skipped.\n";
