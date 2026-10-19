<?php
declare(strict_types=1);
namespace Modules\System\Controllers;
use Brightery\Core\{App,Auth,Security,View};
use Brightery\Core\Jobs\Queue;
use Brightery\Core\Scheduler\Schedule;
use Brightery\Core\Audit\AuditTrail;
use Brightery\Core\I18n\Translator;
use Brightery\Core\Http\Response;
use Brightery\Core\Operations\{HealthReport,ReleaseCoordinator,ReleasePreflight};
use Brightery\Core\Database\Migrator;
use Brightery\Core\Observability\RequestSummary;
final class OperationsController {
    public static function dashboard():Response {
        $view=App::get()->service(View::class);
        $user=App::get()->service(Auth::class)->user();
        $content=$view->render('admin/operations',[
            'counts'=>App::get()->service(Queue::class)->stats(),
            'health'=>App::get()->service(HealthReport::class)->snapshot(),
            'lastRelease'=>(new ReleaseCoordinator(App::get()->service(ReleasePreflight::class),App::get()->service(Migrator::class),base_path('storage/private')))->current(),
            'schedules'=>App::get()->service(Schedule::class)->definitions(),
            'audit'=>App::get()->service(AuditTrail::class)->recent(25),
            'requests'=>RequestSummary::read(base_path('storage/logs/requests.jsonl'),2000),
        ]);
        return Response::html($view->render('admin/layout',[
            'title'=>'Operations','content'=>$content,'user'=>$user,
            'csrf'=>App::get()->service(Security::class)->csrf(),
            'translator'=>App::get()->service(Translator::class),
        ]));
    }
}
