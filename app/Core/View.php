<?php
namespace App\Core;
final class View {
  public static function render(string $view,array $data=[]): void { extract($data,EXTR_SKIP); $base=__DIR__.'/../Views/'; $path=$base.$view.'.php'; if(!is_file($path)) throw new \RuntimeException('View not found: '.$view); include $path; }
}
