<?php
declare(strict_types=1);
namespace Brightery\Core\Tenancy;

/** Stable, explicit permission policy. Never map a tenant role to global user groups. */
final class TenantPolicy {
    public const ROLES=['owner','admin','editor','viewer'];
    private const PERMISSIONS=[
        'owner'=>['workspace.view','members.view','members.manage','invites.create','content.read','content.write','settings.manage'],
        'admin'=>['workspace.view','members.view','members.manage','invites.create','content.read','content.write','settings.manage'],
        'editor'=>['workspace.view','members.view','content.read','content.write'],
        'viewer'=>['workspace.view','content.read'],
    ];
    public static function validRole(string $role):bool {return in_array($role,self::ROLES,true);}
    public static function allows(string $role,string $permission):bool {
        return isset(self::PERMISSIONS[$role])&&in_array($permission,self::PERMISSIONS[$role],true);
    }
    /** Admins can invite/manage only editors and viewers. Ownership cannot be delegated by an admin. */
    public static function mayGrant(string $actorRole,string $role):bool {
        return self::validRole($role)&&($actorRole==='owner'||($actorRole==='admin'&&in_array($role,['editor','viewer'],true)));
    }
    public static function mayChange(string $actorRole,string $currentRole,string $newRole):bool {
        return self::mayGrant($actorRole,$newRole)&&($actorRole==='owner'||in_array($currentRole,['editor','viewer'],true));
    }
}
