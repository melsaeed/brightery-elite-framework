<section class="panel bf-generated-crud" data-bf-crud>
  <div class="panel-heading"><div><h2><?=esc($schema['title'])?></h2><p><?=esc($schema['scope'])?> records · <?=esc((string)$listing['total'])?> total</p></div><span class="pill">MVC MODULE · v1.6</span></div>
  <?php $trash=($listing['view']??'active')==='trash';?>
  <?php if(($schema['soft_delete']??false)&&$canWrite):?><nav class="bf-crud-pages" aria-label="Record state"><a href="<?=esc($prefix)?>" <?=$trash?'':'aria-current="page"'?>>Active records</a> · <a href="<?=esc($prefix)?>?view=trash" <?=$trash?'aria-current="page"':''?>>Trash</a></nav><?php endif;?>
  <?php if($error):?><p role="alert" class="bf-validation-error"><?=esc($error)?></p><?php endif;?>
  <form action="<?=esc($prefix)?>" method="get" class="bf-crud-search">
    <?php if($trash):?><input type="hidden" name="view" value="trash"><?php endif;?>
    <label class="bf-field"><span>Search text</span><input type="search" name="search" value="<?=esc((string)($query['search']??''))?>" maxlength="100" placeholder="Search records"></label>
    <label class="bf-field"><span>Filter field</span><select name="filter"><option value="">All fields</option><?php foreach($schema['fields'] as $f):if($f['type']==='many_to_many')continue;?><option value="<?=esc($f['name'])?>" <?=($query['filter']??'')===$f['name']?'selected':''?>><?=esc($f['label'])?></option><?php endforeach;?></select></label>
    <label class="bf-field"><span>Equals</span><input name="value" value="<?=esc((string)($query['value']??''))?>" placeholder="Exact value"></label>
    <label class="bf-field"><span>Sort by</span><select name="sort"><option value="id">ID</option><?php foreach($schema['fields'] as $f):if($f['type']==='many_to_many')continue;?><option value="<?=esc($f['name'])?>" <?=($query['sort']??'id')===$f['name']?'selected':''?>><?=esc($f['label'])?></option><?php endforeach;?></select></label>
    <label class="bf-field"><span>Order</span><select name="direction"><option value="DESC" <?=($query['direction']??'DESC')==='DESC'?'selected':''?>>Descending</option><option value="ASC" <?=($query['direction']??'DESC')==='ASC'?'selected':''?>>Ascending</option></select></label>
    <label class="bf-field"><span>Per page</span><select name="per_page"><?php foreach([10,20,50,100] as $amount):?><option value="<?=$amount?>" <?=(int)($query['per_page']??20)===$amount?'selected':''?>><?=$amount?></option><?php endforeach;?></select></label>
    <button type="submit" class="btn btn-secondary">Apply</button><a href="<?=esc($prefix)?>">Clear</a> <a class="btn btn-secondary" href="<?=esc($prefix)?>/export?<?=esc(http_build_query(array_diff_key($query,['view'=>true])))?>">Export first 100 matching rows (CSV)</a>
  </form>
  <div class="bf-crud-layout"><div class="bf-crud-list">
    <?php if($trash && $canWrite):?><form action="<?=esc($prefix)?>/bulk-restore" method="post" id="bf-crud-restore"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><label>Type RESTORE to confirm <input name="confirm" required pattern="RESTORE" maxlength="7"></label><button type="submit" class="btn btn-secondary">Restore selected</button></form><?php endif;?>
    <?php if(!$trash):?><form id="bf-crud-bulk" action="<?=esc($prefix)?>/bulk-delete" method="post" class="bf-crud-bulk" data-bf-bulk>
      <input type="hidden" name="_csrf" value="<?=esc($csrf)?>">
      <label class="bf-field"><span>Type DELETE to confirm selected records</span><input name="confirm" placeholder="DELETE" pattern="DELETE" maxlength="6" required autocomplete="off" aria-label="Type DELETE to confirm bulk deletion"></label>
      <button class="btn btn-secondary" type="submit">Delete selected (<output data-bf-selected-count>0</output>)</button><small>At most 50 records at once. All selected records must be accessible.</small>
    </form><?php endif;?>
    <div class="bf-table-scroll"><table class="bf-crud-table"><thead><tr><th><?php if(!$trash||$canWrite):?><input type="checkbox" aria-label="Select all visible records" data-bf-select-all><?php endif;?></th><th>ID</th><?php foreach($schema['fields'] as $f):?><th><?=esc($f['label'])?></th><?php endforeach;?><th>Actions</th></tr></thead><tbody>
    <?php foreach($records as $record):?><tr>
      <td><?php if(!$trash||$canWrite):?><input type="checkbox" name="ids[]" value="<?=esc((string)$record['id'])?>" form="<?=$trash?'bf-crud-restore':'bf-crud-bulk'?>" data-bf-row-select aria-label="Select record <?=esc((string)$record['id'])?>"><?php endif;?></td>
      <td><?=esc((string)$record['id'])?></td>
      <?php foreach($schema['fields'] as $f):?><td><?=esc($f['type']==='many_to_many'?'—':(string)($record[$f['name']]??''))?></td><?php endforeach;?>
      <td><?php if($trash):?><form action="<?=esc($prefix)?>/restore" method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="id" value="<?=esc((string)$record['id'])?>"><button class="btn btn-secondary" type="submit">Restore</button></form><?php else:?><a href="<?=esc($prefix)?>?<?=esc(http_build_query(array_merge($query,['id'=>$record['id']])))?>">Edit</a>
        <form action="<?=esc($prefix)?>/delete" method="post" class="inline-form" onsubmit="return confirm('Delete this record?')"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="id" value="<?=esc((string)$record['id'])?>"><button class="btn btn-secondary" type="submit">Delete</button></form>
      <?php endif;?></td></tr><?php endforeach;?>
    <?php if($records===[]):?><tr><td colspan="<?=count($schema['fields'])+3?>">No matching records</td></tr><?php endif;?></tbody></table></div>
    <nav aria-label="Record pages" class="bf-crud-pages"><span>Page <?=esc((string)$listing['page'])?> of <?=esc((string)$listing['pages'])?></span>
    <?php if($listing['page']>1):?><a href="<?=esc($prefix)?>?<?=esc(http_build_query(array_merge($query,['page'=>$listing['page']-1])))?>">← Previous</a><?php endif;?>
    <?php if($listing['page']<$listing['pages']):?><a href="<?=esc($prefix)?>?<?=esc(http_build_query(array_merge($query,['page'=>$listing['page']+1])))?>">Next →</a><?php endif;?></nav>
  </div>
  <?php if(!$trash):?><div class="bf-crud-form"><h3><?= $editing?'Edit record #'.esc((string)$editing['id']):'Create record'?></h3>
    <form action="<?=esc($prefix)?>/save" method="post" class="form-stack"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><?php if($editing):?><input type="hidden" name="id" value="<?=esc((string)$editing['id'])?>"><?php endif;?>
    <?php foreach($schema['fields'] as $f):$key=$f['name'];$value=$editing[$key]??'';?><label class="bf-field"><span><?=esc($f['label'])?><?=$f['required']?' *':''?></span>
      <?php if($f['type']==='textarea'):?><textarea name="<?=esc($key)?>" maxlength="<?=esc((string)($f['rules']['max_length']??5000))?>" minlength="<?=esc((string)($f['rules']['min_length']??0))?>" rows="3" <?=$f['required']?'required':''?>><?=esc((string)$value)?></textarea>
      <?php elseif($f['type']==='boolean'):?><select name="<?=esc($key)?>"><option value="0" <?=!$value?'selected':''?>>No</option><option value="1" <?=$value?'selected':''?>>Yes</option></select>
      <?php elseif($f['type']==='many_to_many'):?>
        <span class="bf-relation-combo" data-bf-multi-relation data-url="<?=esc($prefix.'/relations')?>" data-field="<?=esc($key)?>">
          <input type="search" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-label="Find <?=esc($f['references'])?> records" placeholder="Search <?=esc($f['references'])?>…" data-bf-relation-search>
          <span class="bf-relation-results" role="listbox" hidden data-bf-relation-results></span>
          <span data-bf-multi-selected>
            <?php foreach(($relations[$key]??[]) as $chosen):?><span class="bf-multi-tag" data-bf-multi-tag data-id="<?=esc((string)$chosen['id'])?>"><input type="hidden" name="<?=esc($key)?>[]" value="<?=esc((string)$chosen['id'])?>"><span><?=esc($chosen['label'])?></span> <button type="button" aria-label="Remove <?=esc($chosen['label'])?>" data-bf-remove-tag>×</button></span><?php endforeach;?>
          </span>
          <small>Select up to 50 authorized records; remove a chip to detach it.</small>
        </span>
      <?php elseif($f['type']==='relation'):?>
        <span class="bf-relation-combo" data-bf-relation data-url="<?=esc($prefix.'/relations')?>" data-field="<?=esc($key)?>">
          <input type="search" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-label="Search <?=esc($f['references'])?> records" placeholder="Search <?=esc($f['references'])?>…" value="<?=esc((string)($relations[$key][0]['label']??''))?>" data-bf-relation-search>
          <input type="hidden" name="<?=esc($key)?>" value="<?=esc((string)$value)?>" data-bf-relation-value>
          <span class="bf-relation-results" role="listbox" hidden data-bf-relation-results></span>
          <small>Search records from your authorized workspace. Clear the search to remove the selection.</small>
        </span>
      <?php else:$kind=match($f['type']){'integer','decimal'=>'number',default=>$f['type']};?><input name="<?=esc($key)?>" type="<?=esc($kind)?>" value="<?=esc((string)$value)?>" <?=$f['required']?'required':''?> <?php if($f['type']==='decimal'):?>step="0.01"<?php elseif($f['type']==='integer'):?>step="1"<?php endif;?> maxlength="<?=esc((string)($f['rules']['max_length']??(in_array($f['type'],['text','email'],true)?255:64)))?>" <?php if(isset($f['rules']['min_length'])):?>minlength="<?=esc((string)$f['rules']['min_length'])?>"<?php endif;?> <?php if(isset($f['rules']['min'])):?>min="<?=esc((string)$f['rules']['min'])?>"<?php endif;?> <?php if(isset($f['rules']['max'])):?>max="<?=esc((string)$f['rules']['max'])?>"<?php endif;?>><?php endif;?></label><?php endforeach;?>
      <button class="btn btn-primary" type="submit"><?=$editing?'Save changes':'Create record'?></button><?php if($editing):?><a href="<?=esc($prefix)?>">Cancel</a><?php endif;?></form>
  </div><?php endif;?></div>
</section>
<script defer src="<?=esc(\Brightery\Core\App::get()->service(\Brightery\Core\Ui\AssetManager::class)->url('js/crud-controls.js'))?>"></script>
