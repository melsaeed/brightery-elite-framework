<div class="split-panels"><section class="panel"><div class="panel-heading"><div><h2><?=esc($plan['module'])?> · change plan</h2><p>Append <?=count($plan['added'])?> nullable column(s). Nothing has been written.</p></div><span class="pill">DRY RUN</span></div><div class="form-stack">
<?php foreach($plan['added'] as $f):?><div class="bf-module-file"><b><?=esc($f['name'])?></b> · <?=esc($f['type'])?> · <?=esc($f['label'])?></div><?php endforeach;?>
<p>Migration file: <code><?=esc($plan['migration'])?></code></p><p>Existing PHP and migrations are preserved.</p></div></section>
<section class="panel"><div class="panel-heading"><div><h2>Review migration SQL</h2><p>Requires disabled module and database backup before apply.</p></div></div>
<pre class="bf-builder-sql"><?=esc($plan['sql'])?></pre>
<?php if($enabled):?><p role="status" class="bf-validation-error">This module is currently enabled. Disable it before applying the reviewed plan.</p><?php endif;?>
<form method="post" action="/admin/developer/evolve/apply" class="form-stack"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="module" value="<?=esc($plan['module'])?>"><input type="hidden" name="digest" value="<?=esc($plan['digest'])?>"><input type="hidden" name="blueprint" value="<?=esc(json_encode($plan['schema'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))?>">
<label class="bf-field"><span>Type <b>EVOLVE <?=esc($plan['module'])?></b> to confirm</span><input name="confirmation" required autocomplete="off"></label>
<button class="btn btn-primary" type="submit" <?=$enabled?'disabled':''?>>Generate migration</button></form></section></div>
