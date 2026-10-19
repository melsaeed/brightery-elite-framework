#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Generates a non-authenticated, disposable browser fixture, never deployed to production. */
require dirname(__DIR__).'/bootstrap/autoload.php';
use Brightery\Core\Form\FormBlueprint;
$schema=['title'=>'Browser test form','fields'=>[['name'=>'email','label'=>'Email','type'=>'email','required'=>true],['name'=>'sector','label'=>'Sector','type'=>'select','required'=>false,'options'=>['b2b'=>'B2B']]]];
$name='browser_demo';$forms=[];$csrf='fixture-only';$preview=(new FormBlueprint())->render($schema);
ob_start();require base_path('themes/admin/form-studio.php');$markup=ob_get_clean();
$css=base_path('public/assets/css/app.css');$js=base_path('public/assets/js/form-studio.js');
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><link rel="stylesheet" href="file://'.esc($css).'"></head><body><main style="padding:24px">'.$markup.'</main><script src="file://'.esc($js).'" defer></script></body></html>';
