<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/catalog-seo.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!preg_match('~^/materialy/katalog/(.*?)page/([^/]+)/$~', $path, $match)) catalogNotFound();
try { $_GET['page'] = catalogPageNumber($match[2]); } catch (InvalidArgumentException $e) { catalogNotFound(); }
$parts = array_values(array_filter(explode('/',trim($match[1],'/'))));
if (($parts[0]??'')==='tip' && count($parts)===2) $_GET['type']=$parts[1];
elseif ($parts) {
    if (count($parts)>3 || !in_array($parts[0],['pedagogi','doshkolnikam','shkolnikam','studentam-spo'],true)) catalogNotFound();
    foreach ($parts as $i=>$value) $_GET[['ac','at','as'][$i]]=$value;
    if(!catalogOptionsExist($db,array_combine(array_slice(['ac','at','as'],0,count($parts)),$parts))) catalogNotFound();
}
if ($_GET['page']===1) { parse_str((string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_QUERY),$query);unset($query['page']);header('Location: /materialy/katalog/'.$match[1].($query?'?'.http_build_query($query):''),true,301);exit; }
require __DIR__.'/materials-catalog.php';
