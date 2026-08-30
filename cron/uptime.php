<?php
declare(strict_types=1);require dirname(__DIR__).'/app/bootstrap.php';use App\Core\DB;use App\Services\UptimeService;$projects=DB::all('SELECT * FROM projects WHERE url IS NOT NULL AND url<>""');foreach($projects as $p){try{$r=UptimeService::check($p);echo $p['slug'].' '.$r['status'].' '.$r['ms']."ms\n";}catch(Throwable $e){error_log('uptime '.$p['id'].': '.$e->getMessage());}}
