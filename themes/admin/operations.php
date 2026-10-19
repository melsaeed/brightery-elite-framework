<section class="panel" style="padding:24px;margin-bottom:20px">
 <h2>HTTP performance <small class="muted">— latest <?=number_format((int)$requests['count'])?> requests</small></h2>
 <p class="muted">Privacy-preserving application telemetry (not a load-test measurement). Request bodies, query strings, user identities, tokens, and IP addresses are excluded.</p>
 <div class="bf-ops-grid" role="list">
 <div class="bf-ops-stat" role="listitem"><span class="pill">p50 latency</span><strong><?=esc($requests['p50_ms']===null?'No samples':$requests['p50_ms'].' ms')?></strong></div>
 <div class="bf-ops-stat" role="listitem"><span class="pill">p95 latency</span><strong><?=esc($requests['p95_ms']===null?'No samples':$requests['p95_ms'].' ms')?></strong></div>
 <div class="bf-ops-stat" role="listitem"><span class="pill">5xx responses</span><strong><?=number_format((int)($requests['statuses']['5xx']??0))?></strong></div>
 <div class="bf-ops-stat" role="listitem"><span class="pill">4xx responses</span><strong><?=number_format((int)($requests['statuses']['4xx']??0))?></strong></div>
 </div>
</section>
<section class="panel" style="padding:24px;margin-bottom:20px">
 <h2>Operations overview</h2>
 <p class="muted">Central diagnostics only. Worker heartbeats are informational; lease safety depends on handlers renewing long-running queue leases.</p>
 <div class="bf-ops-grid" role="list">
 <div class="bf-ops-stat" role="listitem"><span class="pill">Queue assessment</span><strong><?=esc(strtoupper($health['assessment']['status']))?></strong></div>
 <div class="bf-ops-stat" role="listitem"><span class="pill">Healthy workers</span><strong><?= (int)$health['assessment']['active_workers']?></strong></div>
 <div class="bf-ops-stat" role="listitem"><span class="pill">Oldest pending</span><strong><?=esc($health['assessment']['oldest_pending_seconds']===null?'None':$health['assessment']['oldest_pending_seconds'].'s')?></strong></div>
 <div class="bf-ops-stat" role="listitem"><span class="pill">Last finalized release</span><strong><?=esc($lastRelease['version']??'Not recorded')?></strong></div>
 </div>
 <h3>Recent CLI queue workers</h3>
 <div class="table-wrap"><table class="bf-table"><thead><tr><th>Worker</th><th>State</th><th>Age (s)</th><th>Jobs</th></tr></thead><tbody>
 <?php foreach($health['workers'] as $worker): ?>
 <tr><td><code><?=esc(substr($worker['worker_id'],0,8))?>…</code></td><td><?=esc($worker['healthy']?$worker['state']:'stale/stopped')?></td><td><?= (int)$worker['age_seconds']?></td><td><?= (int)$worker['jobs_processed']?></td></tr>
 <?php endforeach; ?>
 </tbody></table></div>
</section>
<section class="panel" style="padding:24px">
 <h2>Queue health</h2><p class="muted">Atomic leases, retries, and failure visibility. Worker execution is controlled using CLI/Supervisor.</p>
 <div class="bf-ops-grid" role="list">
 <?php foreach($counts as $state=>$count): ?>
  <div class="bf-ops-stat" role="listitem"><span class="pill"><?=esc(ucfirst($state))?></span><strong><?=number_format($count)?></strong></div>
 <?php endforeach; ?>
 </div>
</section>
<section class="panel" style="padding:24px;margin-top:20px">
 <h2>Scheduled tasks (UTC)</h2><div class="table-wrap"><table class="bf-table"><thead><tr><th>Task</th><th>Handler</th><th>Frequency</th></tr></thead><tbody>
 <?php foreach($schedules as $task): ?>
 <tr><td><?=esc($task['name'])?></td><td><code><?=esc($task['handler'])?></code></td><td><?=esc($task['type']==='daily'?sprintf('Daily %02d:%02d',intdiv($task['value'],60),$task['value']%60):'Every '.$task['value'].' min')?></td></tr>
 <?php endforeach; ?>
 </tbody></table></div>
</section>
<section class="panel" style="padding:24px;margin-top:20px">
 <h2>Central security events</h2><p class="muted">Tenant audit trails remain on their own workspaces and are not included here.</p>
 <div class="table-wrap"><table class="bf-table"><thead><tr><th>Time (UTC)</th><th>Actor</th><th>Action</th><th>Subject</th></tr></thead><tbody>
 <?php foreach($audit as $event): ?>
  <tr><td><?=esc($event['occurred_at'])?></td><td><?=esc($event['actor_id']??'System')?></td><td><code><?=esc($event['action'])?></code></td><td><?=esc(($event['subject_type']??'').' #'.($event['subject_id']??''))?></td></tr>
 <?php endforeach; ?>
 </tbody></table></div>
</section>
