<section class="panel"><div class="panel-heading"><h2><?=esc($plan['module'])?> · schema published</h2><span class="pill">MIGRATION PENDING</span></div><p>The app-owned blueprint and additive SQL migration were written. No database changes have been run.</p>
<pre class="bf-builder-sql">php bin/console module:migrate-disabled <?=esc($plan['module'])?> --apply
php bin/console module:enable <?=esc($plan['module'])?>
php bin/console module:validate</pre><p>Run these commands in a maintenance window after database backup and SQL review. The module must remain disabled until migration succeeds.</p><a href="/admin/developer/evolve">Back to Evolution Studio</a></section>
