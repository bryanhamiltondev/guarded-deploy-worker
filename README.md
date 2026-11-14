# guarded-deploy-worker

**You can't run CI on cheap shared hosting. You can still refuse to ever paste code into a file manager.**

This is a one-shot PHP patch worker: upload it next to the file you need to change, hit its URL once with a token, and it patches with more paranoia than most deploy pipelines - then deletes itself.

Extracted and sanitized from real production use (a cPanel host with no SSH workflow, no CI, no way to run tests before push). Battle-tested across roughly 60 deploys, including multi-file sweeps of 20+ files at once.

## Why it exists

The workflow it replaces: hand-editing production PHP in a cPanel file manager. The problem isn't just that pasting code into a web editor mangles it - it's that nothing checks your work. One bad anchor and you've silently broken a page for everyone.

The worker inverts that. The patch is a file you write carefully, lint locally, and rehearse against a copy of the live source. The server run is the *only* thing that's careless-proof: every anchor is verified before anything is written, and every failure mode restores the original bytes.

## The contract

1. **Token-gated.** Wrong or missing token renders the site's 404. Nothing about the worker is discoverable.
2. **Needle guards.** Every search string must match its expected count *exactly*, or the worker aborts having written nothing. No first-match `str_replace` roulette.
3. **Idempotent.** A unique patch marker aborts re-runs. Safe to hit the URL twice by accident.
4. **Backup, lint, verify, restore.** Copies each target, writes, runs `php -l` on PHP targets, verifies the marker landed, and restores + reports on any failure.
5. **Self-cleaning.** Backups and the worker itself are deleted only after a full pass. (Set `SELF_DELETE = false` for risky patches - keep the `.bak` until you've confirmed the result, revert in one move.)

## How to use it

1. Copy `src/guarded-deploy-worker.php`. Fill in `TOKEN`, `PATCHES`, and optionally `PATCH_MARKER`.
2. `php -l` it locally. Rehearse it against a downloaded copy of the live file - not your local dev copy; they drift.
3. Upload to the web root. Hit `https://your-site.example/guarded-deploy-worker.php?t=TOKEN` once.
4. Read the output. Every line is a verdict: `OK`, or a precise failure with the file and needle that failed.
5. Verify the live result cache-busted (`?cb=<timestamp>`). CDNs and opcache lie about freshness.

## Scars the skeleton is wearing

Hard-won, each cost a real incident:

- **A worker that globs `*.php` can match itself.** Patch-all workers must skip `realpath(__FILE__)` - and verify-fail branches must clean their own backups, or the leftover `.bak` ships your token to the web.
- **Backslashes pass through three lossy layers** (python -> PHP single-quoted literal -> generated JS). Never hand-count escapes; build byte strings programmatically and verify by decoding, not by regex.
- **Balance checks must be delta-based.** `count('{') - count('}')` on real-world JS is nonzero from string literals. Compare before-vs-after, never demand zero.
- **A stale cache is the default explanation for "the patch didn't work."** Page caches, XML caches, opcache, GSC reports - all serve yesterday. Verify the asset chain fresh before re-patching; double-patching a healthy file is how you *cause* the bug you're chasing.
- **Two stacked bugs mask each other.** Fix one and the second surfaces as a "new" bug. When a fix "doesn't work," ask what the *other* failure mode is.
- **A clean 404 can be an empty route param wearing lipstick.** Fetch the script directly with the param before blaming routing.

## Requirements

PHP 8.0+ (the skeleton uses `str_contains` / `str_starts_with` / `str_ends_with`; swap them for `strpos` shims if your host boots 7.4). No dependencies. Write access to the web root, which on a cPanel box you already have.

## License

MIT
