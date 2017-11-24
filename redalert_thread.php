<?php
if (isset($_GET['bid'])) {
	$bid=trim($_GET['bid']);
} else die('No/invalid MiLight-Bridge-ID!');

ignore_user_abort(true);

require("includes/incl_milight.php");
$CONFIG_FILENAME = 'data/config.xml';

//config.xml dateisystem rechte überprüfen
if(!file_exists($CONFIG_FILENAME)) {
    //echo "Kann die Konfiguration (".$CONFIG_FILENAME.") nicht finden!\n";
    exit(1);
}
if(!is_readable($CONFIG_FILENAME)) {
    //echo "Kann die Konfiguration (".$CONFIG_FILENAME.") nicht lesen!\n";
    exit(2);
}

//config.xml einlesen
libxml_use_internal_errors(true);
$xml = simplexml_load_file($CONFIG_FILENAME);
if (!$xml) {
    //echo "Kann die Konfiguration (".$CONFIG_FILENAME.") nicht laden!\n";
    foreach(libxml_get_errors() as $error) {
        //echo "\t", $error->message;
    }
    exit(4);
}

// Abbrechen wenn AlertState NICHT red ist
if ($xml->global->AlertState != "red") exit();

// Bridge aus Config anhand der ID ermitteln
$MiLightBridge = $xml->xpath("//milightwifis/milightwifi/id[text()='".$bid."']/parent::*");
$MiLightBridge = $MiLightBridge[0];

// IP der Bridge ermitteln
$milightIP = trim((string)$MiLightBridge->address);
if(!filter_var($milightIP, FILTER_VALIDATE_IP)) {
    $milightIPCheck = @gethostbyname(trim((string)$MiLightBridge->address));
    if($milightIP == $milightIPCheck) {
        //$msg="MiLight-Bridge ".$milightIP." is not availible. Check IP or Hostname. \n";
        //echo $msg;
        return;
    } else {
        $milightIP = $milightIPCheck;
    }
}

// Connect zur Bridge und alle Lampen der Bridge auf ROT:DUNKEL setzen
$milight = new Milight($milightIP,(integer)$MiLightBridge->port);
$milight->setRgbwActiveGroup(0);
$milight->rgbwSetColorHexString("#FF0000");
$milight->rgbwBrightnessPercent(40,0);
sleep(1);

// Zwischen hell und dunkel wechseln solange AlertState RED ist
do {	
	set_time_limit(30);
	//config.xml einlesen
	unset($xml);
	libxml_use_internal_errors(true);
	$xml = simplexml_load_file($CONFIG_FILENAME);
	if (!$xml) {
	    //echo "Kann die Konfiguration (".$CONFIG_FILENAME.") nicht laden!\n";
	    foreach(libxml_get_errors() as $error) {
	        //echo "\t", $error->message;
	    }
	    exit(4);
	}

	$milight->setRgbwActiveGroup(0);
    $milight->rgbwBrightnessPercent(100,0);
	sleep(1);

	$milight->setRgbwActiveGroup(0);
    $milight->rgbwBrightnessPercent(40,0);
	sleep(2.5);
} while ($xml->global->AlertState == "red");
unset($milight);


function switch_milight($device, $action) {
    global $xml;

    if($device->address->masterdip == "") {
        //echo "ERROR: masterdip (MiLight WiFi-Bridge-ID) ist ungültig für device id ".$device->id."\n";
        return;
    }
    if($device->address->slavedip == "") {
        //echo "ERROR: slavedip (Gruppe auf MiLight WiFi-Bridge) ist ungültig für device id ".$device->id."\n";
        return;
    }
    if($device->address->tx433version == "") {
        //echo "ERROR: tx433version (MiLight Lampentyp) ist ungültig für device id ".$device->id."\n";
        return;
    }
    if($device->address->slavedip < 0 || $device->address->slavedip > 4) {
        //echo "ERROR: slavedip (Gruppe auf MiLight WiFi-Bridge) muss zwischen 0-4 liegen für device id ".$device->id."\n";
        return;
    }
    if($device->address->tx433version < 1 || $device->address->tx433version > 2) {
        //echo "ERROR: tx433version (MiLight Lampentyp) muss zwischen 1-2 liegen für device id ".$device->id."\n";
        return;
    }

    $MiLightBridge = $xml->xpath("//milightwifis/milightwifi/id[text()='".$device->address->masterdip."']/parent::*");
    $MiLightBridge = $MiLightBridge[0];

    $milightIP = trim((string)$MiLightBridge->address);
    if(!filter_var($milightIP, FILTER_VALIDATE_IP)) {
        $milightIPCheck = @gethostbyname(trim((string)$MiLightBridge->address));
        if($milightIP == $milightIPCheck) {
            $msg="MiLight-Bridge ".$milightIP." is not availible. Check IP or Hostname. \n";
            //echo $msg;
            return;
        } else {
            $milightIP = $milightIPCheck;
        }
    }

    $BulbType="";$BulbCmd="";
    if ($device->address->tx433version == "1") $BulbType="WHITE";
    elseif ($device->address->tx433version == "2") $BulbType="RGBW";

    $milight = new Milight($milightIP,(integer)$MiLightBridge->port);

    if($action == "ON") {
        switch($BulbType){
            case "WHITE":
                $milight->whiteSendOnToGroup((integer)$device->address->slavedip);
                break;
            case "RGBW":
                if ($device->milight->mode != "Farbe" && $device->milight->mode != "Weiß" && $device->milight->mode != "Nacht") {
                	$milight->rgbwSendOnToGroup((integer)$device->address->slavedip);
                }
                break;
        }
    }
    elseif($action == "OFF") {
        switch($BulbType){
            case "WHITE":
                $milight->whiteSendOffToGroup((integer)$device->address->slavedip);
                break;
            case "RGBW":
                $milight->rgbwSendOffToGroup((integer)$device->address->slavedip);
                break;
        }
    }
    unset($milight);
    
    //echo $device->name . " wurde geschaltet: ".$action."\n";
    return($action);
}

function toggle_milight($id, $cmd, $value) {
    global $xml;
    $DryMode = false;

    $device = $xml->xpath("//devices/device/id[text()='".$id."']/parent::*");
    $device = $device[0];

    if($device->address->masterdip == "") {
        //echo "ERROR: masterdip (MiLight WiFi-Bridge-ID) ist ungültig für device id ".$device->id."\n";
        return;
    }
    if($device->address->slavedip == "") {
        //echo "ERROR: slavedip (Gruppe auf MiLight WiFi-Bridge) ist ungültig für device id ".$device->id."\n";
        return;
    }
    if($device->address->tx433version == "") {
        //echo "ERROR: tx433version (MiLight Lampentyp) ist ungültig für device id ".$device->id."\n";
        return;
    }
    if($device->address->slavedip < 0 || $device->address->slavedip > 4) {
        //echo "ERROR: slavedip (Gruppe auf MiLight WiFi-Bridge) muss zwischen 0-4 liegen für device id ".$device->id."\n";
        return;
    }
    if($device->address->tx433version < 1 || $device->address->tx433version > 2) {
        //echo "ERROR: tx433version (MiLight Lampentyp) muss zwischen 1-2 liegen für device id ".$device->id."\n";
        return;
    }

    $MiLightBridge = $xml->xpath("//milightwifis/milightwifi/id[text()='".$device->address->masterdip."']/parent::*");
    $MiLightBridge = $MiLightBridge[0];

    $milightIP = trim((string)$MiLightBridge->address);
    if(!filter_var($milightIP, FILTER_VALIDATE_IP)) {
        $milightIPCheck = @gethostbyname(trim((string)$MiLightBridge->address));
        if($milightIP == $milightIPCheck) {
            $msg="MiLight-Bridge ".$milightIP." is not availible. Check IP or Hostname. \n";
            //echo $msg;
            return;
        } else {
            $milightIP = $milightIPCheck;
        }
    }

    $BulbType="";
    if ($device->address->tx433version == "1") $BulbType="WHITE";
    elseif ($device->address->tx433version == "2") $BulbType="RGBW";

    $milight = new Milight($milightIP,(integer)$MiLightBridge->port);

    switch($BulbType){
        case "WHITE":
            // NOCH NICHT IMPLEMENTIERT
            break;
        case "RGBW":
            if (!$DryMode) {
                $milight->setRgbwActiveGroup((integer)$device->address->slavedip);
            
                if ($cmd == "SetColor") {
                    $milight->rgbwSetColorHexString(trim($value));
                    $milight->rgbwBrightnessPercent((integer)$device->milight->brightnesscolor,(integer)$device->address->slavedip);
                }
                elseif ($cmd == "SetBrightness") {
                    $milight->rgbwBrightnessPercent((integer)$value,(integer)$device->address->slavedip);
                }
                elseif ($cmd == "SetToWhite") {
                    $milight->rgbwSetGroupToWhite((integer)$device->address->slavedip);
                    $milight->rgbwBrightnessPercent((integer)$device->milight->brightnesswhite,(integer)$device->address->slavedip);
                }
                elseif ($cmd == "SetToNightMode") {
                    $milight->rgbwSendOffToGroup((integer)$device->address->slavedip);
                    $milight->command("rgbwGroup".(integer)$device->address->slavedip."NightMode");
                }
                elseif ($cmd == "rgbwDiscoMode" || $cmd == "rgbwDiscoSlower" || $cmd == "rgbwDiscoFaster") {
                    if ($cmd == "rgbwDiscoMode" || $device->milight->mode == "Programm") { 
	                    $milight->rgbwSendOnToActiveGroup();
	                    $milight->command(trim($cmd));
                        
                        if ($cmd == "rgbwDiscoMode") {
                            sleep(1);
                            $milight->setRgbwActiveGroup((integer)$device->address->slavedip);
                            $milight->rgbwBrightnessPercent((integer)$device->milight->brightnessdisco,(integer)$device->address->slavedip);
	                    }
	                }
                }
            }
            break;
    }
    unset($milight);
    
    //echo $device->name . " wurde geschaltet: ".$action."  ";
    if (!$DryMode) return("#OK#");
    else return("#".$id."#".$cmd."#".$value."#");
}

sleep(0.25);
foreach($xml->devices->device as $device) {
	if ($device->vendor == "milight" && (integer)$device->address->masterdip == (integer)$bid) {
		if ($device->status == "OFF") switch_milight($device,"OFF");
		elseif ($device->status == "ON") {
			switch_milight($device,"ON");
			sleep(0.2);
			if ($device->milight->mode == "Weiß") {
              	$MR = toggle_milight($device->id,"SetToWhite",'');
            } elseif ($device->milight->mode == "Farbe") {
    	        $MR = toggle_milight($device->id,"SetColor",$device->milight->color);
            } elseif ($device->milight->mode == "Nacht") {
      	      $MR = toggle_milight($device->id,"SetToNightMode",'');
            } elseif ($device->milight->mode == "Programm") {
                switch_milight($device,"OFF");            
            }
  		}
		
		sleep(0.25);
	}
}
?>