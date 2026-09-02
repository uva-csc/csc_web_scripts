<?php

/**
 * Resaves past "event" nodes whose field_end_date never got computed, which
 * crashes the "Add to Calendar" block (csc_site_blocks's
 * calendar-link-block.html.twig) with:
 *   TypeError: DateTime::__construct(): Argument #1 ($datetime) must be of
 *   type string, array given
 *
 * Root cause: node.field_end_date.value in that Twig template falls back to
 * FieldItemList::getValue() (returning []) when the field has no item, and
 * []|date('U') throws. csc_event_next_occurrence_entity_presave() normally
 * computes field_end_date on every save, but these nodes never got that
 * treatment (created/imported before the logic existed and never resaved
 * since). Re-saving each node runs the presave hook and populates
 * field_end_date + field_next_occurrence — exactly what "edit and save" does
 * manually in the UI. This script does not touch field_date (the Smart Date
 * source data) at all.
 *
 * Run from the site root (this repo is checked out at cscddev/scripts):
 *   ddev drush scr scripts/fix_broken_event_dates.php -- --dry-run
 *   ddev drush scr scripts/fix_broken_event_dates.php
 *
 * --dry-run prints what would happen without saving anything.
 *
 * Node IDs below were identified 2026-09-02 via a query against
 * node__field_end_date for type=event nodes with an empty field_end_date,
 * cross-checked against field_date to confirm each is a genuinely past
 * event with at least one date instance (excludes unpublished nodes with no
 * date data at all, e.g. nid 605/606, which is a separate, unrelated issue).
 * Safe to re-run: nodes that already have field_end_date populated are
 * skipped.
 */

$args = $extra ?? [];
$dry_run = in_array('--dry-run', $args, true);

$nids = [
  15, 39, 41, 42, 130, 133, 152, 178, 179, 209,
  213, 214, 215, 218, 219, 221, 224, 248, 256, 257,
  275, 276, 277, 313, 314, 315, 316, 317, 319, 332,
  360, 366, 369, 375, 380, 385, 389, 390, 393, 394,
  399, 530, 538, 555, 556, 562, 563, 596, 621, 624,
];

$storage = \Drupal::entityTypeManager()->getStorage('node');
$fixed = [];
$skipped = [];

foreach ($nids as $nid) {
  /** @var \Drupal\node\NodeInterface|null $node */
  $node = $storage->load($nid);

  if (!$node) {
    $skipped[] = "$nid: node not found";
    continue;
  }

  if ($node->bundle() !== 'event') {
    $skipped[] = "$nid: not an event node (bundle={$node->bundle()})";
    continue;
  }

  if ($node->get('field_date')->isEmpty()) {
    $skipped[] = "$nid: field_date is empty, nothing to compute from";
    continue;
  }

  if (!$node->get('field_end_date')->isEmpty()) {
    $skipped[] = "$nid: field_end_date is already populated, skipping";
    continue;
  }

  if ($dry_run) {
    print "[DRY RUN] Would resave node $nid: " . $node->getTitle() . "\n";
    continue;
  }

  $node->save();

  // Reload to confirm the presave hook actually populated the field.
  $node = $storage->load($nid);
  if ($node->get('field_end_date')->isEmpty()) {
    $skipped[] = "$nid: resaved but field_end_date is STILL empty - needs manual review";
    continue;
  }

  $fixed[] = "$nid: " . $node->getTitle() . " -> field_end_date=" . $node->get('field_end_date')->value;
}

print "\n--- Fixed (" . count($fixed) . ") ---\n";
print implode("\n", $fixed) . "\n";

print "\n--- Skipped (" . count($skipped) . ") ---\n";
print implode("\n", $skipped) . "\n";

if ($dry_run) {
  print "\nThis was a DRY RUN. No nodes were changed. Run again without --dry-run to apply the fix.\n";
}
