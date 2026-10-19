<?php
declare(strict_types=1);
use Brightery\Core\{Container};
use Brightery\Core\Events\Hooks;
use Brightery\Core\Jobs\Registry;
use Brightery\Core\Scheduler\Schedule;
use Brightery\Core\Tenancy\TenantInvitations;
use Brightery\Core\Mail\{Outbox,EmailVerification,PasswordReset};
use Brightery\Core\RateBuckets;
use Brightery\Core\Media\ImageVariants;
use Brightery\Core\Auth\SessionRegistry;
/** Sample core maintenance task, extensible in additional module boot.php files. */
return static function(Container $container, Hooks $hooks):void {
    $container->get(Registry::class)->register('system.invites.prune',static function(array $payload)use($container):void {
        $container->get(TenantInvitations::class)->prune();
    });
    $container->get(Schedule::class)->dailyUtc('system.invites.daily','system.invites.prune',3);
    $container->get(Registry::class)->register('system.mail.send',static function(array $payload)use($container):void {
        $container->get(Outbox::class)->deliver((int)($payload['id']??0));
    });
    $container->get(Registry::class)->register('system.security.prune',static function(array $payload)use($container):void {
        $container->get(RateBuckets::class)->prune();
        $container->get(SessionRegistry::class)->prune();
        $container->get(EmailVerification::class)->prune();
        $container->get(Outbox::class)->prune();
        $container->get(PasswordReset::class)->prune();
    });
    $container->get(Schedule::class)->dailyUtc('system.security.daily','system.security.prune',4);
    $container->get(Registry::class)->register('system.media.thumbnail',static function(array $payload)use($container):void {
        $mediaId=filter_var($payload['media_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$mediaId)throw new \RuntimeException('Invalid thumbnail media identifier');
        $container->get(ImageVariants::class)->create((int)$mediaId,320,320,'contain');
    });
};
