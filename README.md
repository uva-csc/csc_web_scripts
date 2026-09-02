# csc_scripts

One-off / occasionally-repeated Drush scripts for csc.virginia.edu (Drupal 11)
— data imports, migrations, and cleanup tasks that don't belong in `csc_suite`
because they're run by hand a handful of times rather than living as
permanent site functionality.

If a script here turns out to be needed repeatedly (weekly/monthly, or by
someone other than a developer), graduate it into a proper Drush command in
`csc_suite`'s `csc_admin` module instead of leaving it here.

## Layout

Flat directory, one task per script (plus whatever data file it reads).
Name files for what they do, not when: `import_remote_videos.php`, not
`2026-08-import.php`. If a task grows multiple scripts (import + rollback,
say), prefix them consistently so they sort together.

## Running a script

Clone this repo into the site root (same level as `modules/`, `themes/`) on
whichever environment you're running against, then from the site root:

```bash
# local (ddev)
ddev drush scr scripts/<script>.php -- <args>

# dev / prod
drush scr scripts/<script>.php -- <args>
```

Every script here should support a `--dry-run` flag that reports what it
would do without writing anything, and should be safe to re-run (skip
already-done work rather than duplicating it). Check each script's own
header comment for its specific usage and arguments.

## Current scripts

- `import_remote_videos.php` / `rollback_remote_videos.php` — creates (or
  undoes creating) "Remote video" media entities from a CSV of
  title/upload_date/url, with an optional `--tag=<tid>[,<tid>...]` to apply
  Content Filters taxonomy terms on import. `sharat_videos_approved.csv` is
  the data file used for the Sharat Jois YouTube video import.
- `fix_broken_event_dates.php` — resaves past `event` nodes whose
  `field_end_date` never got computed (crashes the "Add to Calendar" block
  with a Twig `DateTime` TypeError); resaving reruns
  `csc_event_next_occurrence`'s presave hook, the same fix as editing and
  saving the node by hand. `broken_event_dates_2026-09-02.csv` lists the 50
  affected nodes found on cscddev (nid, title, URL, start/end date, time
  range).
