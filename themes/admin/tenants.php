<div class="panel">
  <div class="panel-heading"><div><h2>Tenant companies</h2><p>Central control plane. Tenant domains never expose /admin routes. Domain ownership/TLS must be configured externally before production activation.</p></div><span class="pill"><?= $isMulti?'MULTI-TENANT':'SINGLE MODE' ?></span></div>
  <div class="bf-tenants-grid">
    <form action="/admin/tenants/create" method="post" class="bf-tenant-form">
      <input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><h3>Create company</h3>
      <label>Company name <input name="name" maxlength="160" required placeholder="Acme Holdings"></label>
      <label>URL slug <input name="slug" pattern="[a-z0-9-]+" maxlength="80" required placeholder="acme"></label>
      <button type="submit" class="btn btn-primary">Create tenant</button>
    </form>
    <form action="/admin/tenants/domain" method="post" class="bf-tenant-form">
      <input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><h3>Assign domain</h3>
      <label>Tenant ID <input name="tenant_id" type="number" min="1" required></label>
      <label>Domain <input name="hostname" required placeholder="acme.example.com"></label>
      <button type="submit" class="btn btn-secondary">Assign domain</button>
    </form>
  </div>
  <div class="bf-tenants-grid">
    <form action="/admin/tenants/invite" method="post" class="bf-tenant-form">
      <input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><h3>Invite first owner or a team member</h3>
      <p>Assign the tenant domain first. An expiring invitation is sent by encrypted queued email; raw links are never shown.</p>
      <label>Tenant ID <input type="number" name="tenant_id" min="1" required></label>
      <label>Invitee email <input name="email" type="email" maxlength="190" required></label>
      <label>Role <select name="role"><option value="owner">Owner</option><option value="admin">Admin</option><option value="editor">Editor</option><option value="viewer">Viewer</option></select></label>
      <button class="btn btn-primary" type="submit">Create onboarding link</button>
    </form>
  </div>
  <div class="bf-tenants-grid">
    <form action="/admin/tenants/media-quota" method="post" class="bf-tenant-form">
      <input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><h3>Set company media quota</h3>
      <label>Tenant ID <input name="tenant_id" type="number" min="1" required></label>
      <label>Storage limit (MiB) <input name="max_mib" type="number" min="1" max="1048576" value="512" required></label>
      <label>Maximum original files <input name="max_files" type="number" min="1" max="1000000" value="2000" required></label>
      <p>Generated thumbnails also count toward storage usage. Limits below current usage block new additions, not reads.</p>
      <button class="btn btn-primary" type="submit">Save media limits</button>
    </form>
  </div>
  <div class="table-wrap"><table class="bf-table"><thead><tr><th>Company</th><th>Slug</th><th>Domains</th><th>Status</th><th>Action</th></tr></thead><tbody>
  <?php foreach($tenants as $tenant): ?><tr><td><strong><?=esc($tenant['display_name'])?></strong> <small>#<?=esc($tenant['id'])?></small></td><td><?=esc($tenant['slug'])?></td><td><?=esc($tenant['domains']??'—')?></td><td><span class="pill <?= $tenant['status']==='active'?'pill-green':'' ?>"><?=esc($tenant['status'])?></span></td><td><form action="/admin/tenants/status" method="post"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="tenant_id" value="<?=esc($tenant['id'])?>"><input type="hidden" name="status" value="<?=$tenant['status']==='active'?'suspended':'active'?>"><button class="btn btn-small btn-secondary" type="submit"><?=$tenant['status']==='active'?'Suspend':'Activate'?></button></form></td></tr><?php endforeach; ?>
  <?php if(!$tenants): ?><tr><td colspan="5">No tenants yet. Create your first company above.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
