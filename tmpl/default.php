<?php

/**
 * @package     Joomla.Site
 * @subpackage  mod_pposmap
 *
 * @copyright   (C) 2024 pablop76, Inc. <https://web-service.com.pl>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;

$document = $this->app->getDocument();
$wa = $document->getWebAssetManager();
$wa->getRegistry()->addExtensionRegistryFile('mod_pposmap');

$wa->useScript('mod_pposmap.custom');

$wa->useStyle('mod_pposmap.style');

$tokenmapbox = $params->get('tokenmapbox', '');
$stylemapbox = $params->get('stylemapbox', 'mapbox://styles/mapbox/streets-v12');
$listofpoints = $params->get('listofpoints', '');
$zoommapbox = $params->get('zoommapbox', '1');
$geotitle = $params->get('geotitle', '');
$geodescription = $params->get('geodescription', '');
$markermapbox = $params->get('markermapbox', '');
$pointslistmapbox = $params->get('pointslistmapbox', '');
$positionpointslistmapbox = $params->get('positionpointslistmapbox', 'https://placehold.co/300x200');
$popupimage = $params->get('popupimage', '');
$mapboxorleaflet = $params->get('mapboxorleaflet', '');

if(!$mapboxorleaflet){
  $wa->useScript('mapbox');
  $wa->useStyle('stylemapbox');
}else{
  $wa->useScript('leafletjs');
  $wa->useStyle('leafletcss');
}

$document->addScriptOptions('mod_pposmap.vars', ['tokenmapbox' => $tokenmapbox]);
$document->addScriptOptions('mod_pposmap.vars', ['stylemapbox' => $stylemapbox]);
$document->addScriptOptions('mod_pposmap.vars', ['listofpoints' => $listofpoints]);
$document->addScriptOptions('mod_pposmap.vars', ['zoommapbox' => $zoommapbox]);
$document->addScriptOptions('mod_pposmap.vars', ['geotitle' => $geotitle]);
$document->addScriptOptions('mod_pposmap.vars', ['geodescription' => $geodescription]);
$document->addScriptOptions('mod_pposmap.vars', ['markermapbox' => $markermapbox]);
$document->addScriptOptions('mod_pposmap.vars', ['popupimage' => $popupimage]);
$document->addScriptOptions('mod_pposmap.vars', ['mapboxorleaflet' => $mapboxorleaflet]);

?>

<?php if ($positionpointslistmapbox){?>
  <!-- Start slideshow -->
   <div class="flex-container table-pposmap">
<?php if ($pointslistmapbox) {?>
<div class="list-items-container">
  <?php
$points = $listofpoints;
// ograniczenia długości  ciągu description
if (!function_exists('limit_string')) {
  function limit_string( $string, $limit, $end = "..." ){
    $string = explode( ' ', $string, $limit );
    if( count( $string ) >= $limit ){
      array_pop( $string );
      $string = implode( " ", $string ) . $end;
    } else {
      $string = implode( " ", $string );
    }
  
    return $string;
  }
}

for ($i = 0; $i <= count((array)$listofpoints)-1; $i++) {
    $point = $points->{"listofpoints" . $i};?>
    <div data-index='<?php echo $i+1;?>' class="list-item">
    <h3 class="mapbox-popup-title"><?php echo $point->geotitle; ?></h3>
    <p class="mapbox-popup-description"><?php echo limit_string ($point->geodescription, 9); ?></p>
    </div>
    <?php
}?>
</div>
<?php
}
?>
  <div id="map"></div>
   </div>
   <!-- End slideshow -->
<?php
}
else{?>
  <!-- Start table -->
  <div id="map"></div>
<?php if ($pointslistmapbox) {?>
<div class="table-responsive">

<table class="table table-pposmap table-hover">
  <thead class="table-light">
    <tr>
      <th scope="col"><?php echo Text::_('MOD_PPOSMAP_TABLE_ID')?></th>
      <th scope="col"><?php echo Text::_('MOD_PPOSMAP_TABLE_TITLE') ?></th>
      <th scope="col"><?php echo Text::_('MOD_PPOSMAP_TABLE_DESCRIPTION') ?></th>
    </tr>
  </thead>
  <tbody>
  <?php
$points = $listofpoints;
for ($i = 0; $i <= count((array)$listofpoints)-1; $i++) {
    $point = $points->{"listofpoints" . $i};?>
    <tr data-index='<?php echo $i+1;?>'>
      <th scope="row"><?php echo $i+1;?></th>
      <td><?php echo $point->geotitle; ?></td>
      <td><?php echo $point->geodescription; ?></td>
    </tr>
    <?php
}?>
  </tbody>
</table>
</div>

<?php
}
?>
   <!-- End table -->
<?php
} ?>
