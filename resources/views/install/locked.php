<h2>Already installed</h2>
<p class="text-muted">
    SIUGOALS has already been installed on this environment. The installer locks itself
    permanently after its first successful run and cannot be re-run from the browser.
</p>
<p class="text-muted">
    If you genuinely need to re-run migrations (e.g. after deploying a new version), use
    <code>php database/migrate.php</code> over SSH instead — it only ever applies new,
    not-yet-applied migrations and never re-runs completed ones.
</p>
