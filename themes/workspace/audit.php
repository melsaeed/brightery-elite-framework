<section class="panel bf-workspace-panel">
 <div class="panel-heading"><h2>Recent security events</h2><p class="muted">Scoped to this company. Event metadata excludes credential fields.</p></div>
 <div class="table-wrap"><table class="bf-table"><thead><tr><th>UTC timestamp</th><th>Actor</th><th>Event</th><th>Subject</th></tr></thead><tbody>
 <?php foreach($events as $event): ?>
 <tr><td><?=esc($event['occurred_at'])?></td><td><?=esc($event['actor_id']??'System')?></td><td><code><?=esc($event['action'])?></code></td><td><?=esc(($event['subject_type']??'').' #'.($event['subject_id']??''))?></td></tr>
 <?php endforeach; ?>
 <?php if(!$events):?><tr><td colspan="4" class="muted">No recorded events yet.</td></tr><?php endif;?>
 </tbody></table></div>
</section>
