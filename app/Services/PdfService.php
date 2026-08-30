<?php
namespace App\Services;
final class PdfService {
    public static function simple(array $lines): string {
        $cut=fn($x)=>function_exists('mb_substr')?mb_substr((string)$x,0,105):substr((string)$x,0,105);$pages=array_chunk(array_map($cut,$lines),38);
        if(!$pages)$pages=[['SIUGOALS']];
        $objs=[];$objs[1]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $pageIds=[];$contentIds=[];$next=3;
        foreach($pages as $pg){$contentIds[]=$next++;$pageIds[]=$next++;}
        $pagesId=$next++;$catalogId=$next++;
        foreach($pages as $idx=>$pg){$content="BT /F1 11 Tf 48 794 Td ";$first=true;foreach($pg as $line){$line=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$line);if(!$first)$content.='0 -19 Td ';$content.='('.$line.') Tj ';$first=false;}$content.='ET';$objs[$contentIds[$idx]]='<< /Length '.strlen($content).' >>' . "\nstream\n".$content."\nendstream";$objs[$pageIds[$idx]]='<< /Type /Page /Parent '.$pagesId.' 0 R /Resources << /Font << /F1 1 0 R >> >> /MediaBox [0 0 612 842] /Contents '.$contentIds[$idx].' 0 R >>';}
        $kids=implode(' ',array_map(fn($id)=>$id.' 0 R',$pageIds));$objs[$pagesId]='<< /Type /Pages /Kids ['.$kids.'] /Count '.count($pageIds).' >>';$objs[$catalogId]='<< /Type /Catalog /Pages '.$pagesId.' 0 R >>';ksort($objs);
        $pdf="%PDF-1.4\n";$offs=[0=>0];foreach($objs as $id=>$o){$offs[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$o."\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objs));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++)$pdf.=isset($offs[$i])?sprintf('%010d 00000 n ',$offs[$i])."\n":"0000000000 00000 f \n";$pdf.='trailer << /Size '.($max+1).' /Root '.$catalogId." 0 R >>\nstartxref\n".$xref."\n%%EOF";return $pdf;
    }
}
