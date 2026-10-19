<section class="panel">
  <div class="panel-heading"><div><h2>Module diagnostics</h2><p>Read-only checks for installed module files, metadata and generated CRUD structure.</p></div><span class="pill">STUDIO 1.5</span></div>
  <form method="get" action="/admin/developer/diagnostics" class="form-stack">
    <label class="bf-field"><span>Module</span><select name="module" required><?php foreach($catalog as $name=>$manifest):?><option value="<?=esc($name)?>" <?=$selected===$name?'selected':''?>><?=esc($name)?> · <?=esc($manifest['version'])?></option><?php endforeach;?></select></label>
    <button class="btn btn-primary" type="submit">Run read-only checks</button>
  </form>
  <?php if($report):?><h3><?=esc($report['name'])?> · <?=$report['ok']?'Checks passed':'Checks require attention'?></h3>
  <div class="bf-table-scroll"><table class="bf-crud-table"><thead><tr><th>Check</th><th>Result</th><th>Details</th></tr></thead><tbody>
  <?php foreach($report['checks'] as $c):?><tr><td><?=esc($c['name'])?></td><td><?=$c['ok']?'Pass':'Failed'?></td><td><?=esc($c['detail'])?></td></tr><?php endforeach;?></tbody></table></div>
  <?php endif;?><p class="hint">This screen does not execute module PHP or SQL, inspect production data, run migrations, or provide a security audit.</p>
</section>
