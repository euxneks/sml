<?php

/**
 * staticMapLite 0.02
 *
 * Copyright 2009 Gerhard Koch
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @author Gerhard Koch <gerhard.koch AT ymail.com>
 *
 * USAGE: 
 *
 *  staticmap.php?center=40.714728,-73.998672&zoom=14&size=512x512&maptype=mapnik&markers=40.702147,-74.015794,blues|40.711614,-74.012318,greeng|40.718217,-73.998284,redc
 *
 * optional parameters:
 * maptype - assumed as mapnik if not present
 * markers - won't show any markers if not present
 *
 */ 

error_reporting(0);
ini_set('display_errors','off');

Class staticMapLite {

	protected $tileSize = 256;
	protected $tileSrcUrl = array(	'mapnik' => 'http://localhost/osm/{Z}/{X}/{Y}.png'
	);
	
	protected $tileDefaultSrc = 'mapnik';
	protected $markerBaseDir = 'images/markers';
	protected $osmLogo = 'images/osm_logo.png';

	protected $useTileCache = true;
	protected $tileCacheBaseDir = 'cache/tiles';

	protected $useMapCache = true;
	protected $mapCacheBaseDir = 'cache/maps';
	protected $mapCacheID = '';
	protected $mapCacheFile = '';
	protected $mapCacheExtension = 'png';
	
	protected $zoom, $lat, $lon, $width, $height, $markers, $image, $maptype;
	protected $centerX, $centerY, $offsetX, $offsetY;

	public function __construct(){
		$this->zoom = 4; //default zoom
		$this->lat = 0;
		$this->lon = 0;
		$this->width = 500;
		$this->height = 350;
		$this->markers = array();
		$this->maptype = $this->tileDefaultSrc;
	}
	
	public function parseParams(){
		global $_GET;
		
        // get zoom from GET paramter
        if ( !empty( $_GET['zoom'] ) || !empty( $_GET[ 'z' ] ) ) {
            if( !empty( $_GET['zoom'] ) ){
                $this->zoom = intval( $_GET[ 'zoom' ] );
            } else {
                $this->zoom = intval( $_GET[ 'z' ] );
            }
        }
        if($this->zoom>18)$this->zoom = 18;
        if($this->zoom<0)$this->zoom = 0; //min zoom
		
        // get lat and lon from GET paramter
        if ( !empty( $_GET['center'] ) ) {
            list($this->lat,$this->lon) = explode(',',$_GET['center']);
        } else {
            if ( empty( $_GET['c'] ) ) {
                //no center, this is not a valid request
                return;
            }
            list($this->lat,$this->lon) = explode(',',$_GET['c']);
        }
		$this->lat = floatval($this->lat);
		$this->lon = floatval($this->lon);
		
		// get zoom from GET paramter
        if(!empty( $_GET['size'] ) || !empty( $_GET[ 's' ] )){
            if ( !empty( $_GET[ 'size' ] ) ) {
                list($this->width, $this->height) = explode('x',$_GET['size']);
            } else {
                list($this->width, $this->height) = explode('x',$_GET['s']);
            }
			$this->width = intval($this->width);
			$this->height = intval($this->height);
        }

        if(!empty($_GET['m']) || !empty( $_GET['markers'] )){
            if ( !empty( $_GET['markers'] ) ) {
                $markers = explode('%7C|\|',$_GET['markers']);
            } else {
                $markers = explode('%7C|\|',$_GET['m']);
            }
			foreach($markers as $marker){
					list($markerLat, $markerLon, $markerImage, $pointX, $pointY) = explode(',',$marker);
					$markerLat = floatval($markerLat);
					$markerLon = floatval($markerLon);
                    $markerImage = basename($markerImage);
                    $pointX = intval( $pointX );
                    $pointY = intval( $pointY );
					$this->markers[] = array('lat'=>$markerLat, 'lon'=>$markerLon, 'image'=>$markerImage, 'pointY'=>$pointY, 'pointX'=>$pointX);
			}
        }

		if(!empty( $_GET['maptype'] )){
			if(array_key_exists($_GET['maptype'],$this->tileSrcUrl)) $this->maptype = $_GET['maptype'];
        }
		if(!empty( $_GET['mt'] )){
			if(array_key_exists($_GET['mt'],$this->tileSrcUrl)) $this->maptype = $_GET['mt'];
        }
	}

	public function lonToTile($long, $zoom){
		return (($long + 180) / 360) * pow(2, $zoom);
	}

	public function latToTile($lat, $zoom){
		return (1 - log(tan($lat * pi()/180) + 1 / cos($lat* pi()/180)) / pi()) /2 * pow(2, $zoom);
	}

	public function initCoords(){
		$this->centerX = $this->lonToTile($this->lon, $this->zoom);
		$this->centerY = $this->latToTile($this->lat, $this->zoom);
		$this->offsetX = floor((floor($this->centerX)-$this->centerX)*$this->tileSize);
		$this->offsetY = floor((floor($this->centerY)-$this->centerY)*$this->tileSize);
	}

	public function createBaseMap(){
		$this->image = imagecreatetruecolor($this->width, $this->height);
		$startX = floor($this->centerX-($this->width/$this->tileSize)/2);
		$startY = floor($this->centerY-($this->height/$this->tileSize)/2);
		$endX = ceil($this->centerX+($this->width/$this->tileSize)/2);
		$endY = ceil($this->centerY+($this->height/$this->tileSize)/2);
		$this->offsetX = -floor(($this->centerX-floor($this->centerX))*$this->tileSize);
		$this->offsetY = -floor(($this->centerY-floor($this->centerY))*$this->tileSize);
		$this->offsetX += floor($this->width/2);
		$this->offsetY += floor($this->height/2);
		$this->offsetX += floor($startX-floor($this->centerX))*$this->tileSize;
		$this->offsetY += floor($startY-floor($this->centerY))*$this->tileSize;

		for($x=$startX; $x<=$endX; $x++){
			for($y=$startY; $y<=$endY; $y++){
                $url = str_replace(array('{Z}','{X}','{Y}'),array($this->zoom, $x, $y), $this->tileSrcUrl[$this->maptype]);
                $tileImage = imagecreatefromstring($this->fetchTile($url));
				$destX = ($x-$startX)*$this->tileSize+$this->offsetX;
				$destY = ($y-$startY)*$this->tileSize+$this->offsetY;
				imagecopy($this->image, $tileImage, $destX, $destY, 0, 0, $this->tileSize, $this->tileSize);
			}
		}
	}


	public function placeMarkers(){
		foreach($this->markers as $marker){
			$markerLat = $marker['lat'];
			$markerLon = $marker['lon'];
			$markerImage = $marker['image'];
			$markerIndex++;
			$markerFilename = $markerImage?(file_exists($this->markerBaseDir.'/'.$markerImage.".png")?$markerImage:'lightblue'.$markerIndex):'lightblue'.$markerIndex;
			if(file_exists($this->markerBaseDir.'/'.$markerFilename.".png")){
				$markerImg = imagecreatefrompng($this->markerBaseDir.'/'.$markerFilename.".png");
			} else {
				$markerImg = imagecreatefrompng($this->markerBaseDir.'/lightblue1.png');				
			}
			$destX = floor(($this->width/2)-$this->tileSize*($this->centerX-$this->lonToTile($markerLon, $this->zoom)));
			$destY = floor(($this->height/2)-$this->tileSize*($this->centerY-$this->latToTile($markerLat, $this->zoom)));
			$destY = $destY - imagesy($markerImg);
            $destY = $destY + $marker[ 'pointY' ];
            $destX = $destX - $marker[ 'pointX' ];
			imagecopy($this->image, $markerImg, $destX, $destY, 0, 0, imagesx($markerImg), imagesy($markerImg));
		
	};
}



    public function tileUrlToFilename($url){
		return $this->tileCacheBaseDir."/".str_replace(array('http://', ':'),'',$url);
	}

	public function checkTileCache($url){
		$filename = $this->tileUrlToFilename($url);
		if(file_exists($filename)){
			return file_get_contents($filename);
		}
	}
	
	public function checkMapCache(){
		$this->mapCacheID = md5($this->serializeParams());
		$filename = $this->mapCacheIDToFilename();
		if(file_exists($filename)) return true;
	}

	public function serializeParams(){		
		return join("&",array($this->zoom,$this->lat,$this->lon,$this->width,$this->height, serialize($this->markers),$this->maptype));
	}
	
    public function mapCacheIDToFilename(){
		if(!$this->mapCacheFile){
			$this->mapCacheFile = str_replace( ':', '_', $this->mapCacheBaseDir )."/".substr($this->mapCacheID,0,2)."/".substr($this->mapCacheID,2,2)."/".substr($this->mapCacheID,4);
        }
		return $this->mapCacheFile.".".$this->mapCacheExtension;
	}


	
	public function mkdir_recursive($pathname, $mode){
		is_dir(dirname($pathname)) || $this->mkdir_recursive(dirname($pathname), $mode);
		return is_dir($pathname) || @mkdir($pathname, $mode);
	}
	public function writeTileToCache($url, $data){
        $filename = $this->tileUrlToFilename($url);
        $this->mkdir_recursive(dirname($filename),0777);
        touch( $filename );
		file_put_contents($filename, $data);
	}
	
    public function fetchTile($url){
		if($this->useTileCache && ($cached = $this->checkTileCache($url))) return $cached;
		$ch = curl_init(); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0");
		curl_setopt($ch, CURLOPT_URL, $url); 
        $tile = curl_exec($ch); 
		curl_close($ch); 
		if($this->useTileCache){
			$this->writeTileToCache($url,$tile);
		}
		return $tile;

	}

	public function copyrightNotice(){
			$logoImg = imagecreatefrompng($this->osmLogo);
			imagecopy($this->image, $logoImg, imagesx($this->image)-imagesx($logoImg), imagesy($this->image)-imagesy($logoImg), 0, 0, imagesx($logoImg), imagesy($logoImg));
		
	}
	
	public function sendHeader(){
		header('Content-Type: image/png');
		$expires = 60*60*24*14;
		header("Pragma: public");
		header("Cache-Control: maxage=".$expires);
		header('Expires: ' . gmdate('D, d M Y H:i:s', time()+$expires) . ' GMT');
	}

	public function makeMap(){
		$this->initCoords();		
		$this->createBaseMap();
		if(count($this->markers))$this->placeMarkers();
		if($this->osmLogo) $this->copyrightNotice();
	}

	public function showMap(){
		$this->parseParams();
		if($this->useMapCache){
			// use map cache, so check cache for map
			if(!$this->checkMapCache()){
				// map is not in cache, needs to be build
				$this->makeMap();
                $this->mkdir_recursive(dirname($this->mapCacheIDToFilename()),0777);
                touch( $this->image );
				imagepng($this->image,$this->mapCacheIDToFilename(),9);
				$this->sendHeader();	
				if(file_exists($this->mapCacheIDToFilename())){
					return file_get_contents($this->mapCacheIDToFilename());
				} else {
					return imagepng($this->image);		
				}
			} else {
				// map is in cache
				$this->sendHeader();	
				return file_get_contents($this->mapCacheIDToFilename());
			}

		} else {
			// no cache, make map, send headers and deliver png
			$this->makeMap();
			$this->sendHeader();	
			return imagepng($this->image);		
			
		}
	}

}
$map = new staticMapLite();
print $map->showMap();
?>
