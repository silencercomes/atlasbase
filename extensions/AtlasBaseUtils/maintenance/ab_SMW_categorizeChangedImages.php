<?php
/**  Automatische Kategorisierung von Seiten im Namensraum Datei, welche zu einem kürzlich geändertem Datensatz gehören
Ablauf:
	* Suche alle Seiten in Kategorie Atlas, welche kürzlich geändert wurden
	* Für jede Seite:
		* Ermittle UID, Erscheinungsjahr und Familie aus Attributen
		* Suche alle Seiten in Namensraum NS_FILE, welche mit der UID beginnt
		* Für jede Seite:
			* Aktualisiere Dateiseite mit neuen Kategorien
**/



require_once ( getenv('MW_INSTALL_PATH') !== false
    ? getenv('MW_INSTALL_PATH')."/maintenance/commandLine.inc"
    : dirname( __FILE__ ) . '/../../../maintenance/commandLine.inc' );


function doSearchQueryRecentChanges( $namespaces, $category, $prefix, $timestamp ) {
	$dbr = wfGetDB( DB_SLAVE );

	$include_ns = $dbr->makeList( $namespaces );

	$tables = array( 'page', 'recentchanges');
	$vars = array( 'page_id', 'page_namespace', 'page_title', 'rc_timestamp');
	$conds = array(
		"rc_timestamp >= $timestamp",
		"page_namespace in ($include_ns)",
		'page_id = rc_cur_id'
	);
	if ( ! empty( $category ) ) {
		$category = str_replace( ' ', '_', $dbr->escapeLike( $category ) );
		$tables[] = 'categorylinks';
		$conds[] = 'page_id = cl_from';
		$conds[] = "cl_to = '$category'";
	}
	if ( ! empty( $prefix ) ) {
		$prefix = $dbr->escapeLike( str_replace( ' ', '_', $prefix ) );
		$conds[] = "page_title like '$prefix%'";
	}
	$sort = array( 'ORDER BY' => 'rc_timestamp, page_namespace, page_title' );

	return $dbr->select( $tables, $vars, $conds, __METHOD__ , $sort );
}

function doSearchQuery( $namespaces, $category, $prefix ) {
        $dbr = wfGetDB( DB_SLAVE );

        $include_ns = $dbr->makeList( $namespaces );

        $tables = array( 'page' );
        $vars = array( 'page_id', 'page_namespace', 'page_title');
        $conds = array(
                "page_namespace in ($include_ns)"
        );
        if ( ! empty( $category ) ) {
                $category = str_replace( ' ', '_', $dbr->escapeLike( $category ) );
                $tables[] = 'categorylinks';
                $conds[] = 'page_id = cl_from';
                $conds[] = "cl_to = '$category'";
        }
        if ( ! empty( $prefix ) ) {
                $prefix = $dbr->escapeLike( str_replace( ' ', '_', $prefix ) );
                $conds[] = "page_title like '$prefix%'";
        }
        $sort = array( 'ORDER BY' => 'page_namespace, page_title' );

        return $dbr->select( $tables, $vars, $conds, __METHOD__ , $sort );
}

function buildFileContent(array $arrayVerlagDI, array $arrayJahrDI, array $arrayFamilieDI, $fileName) {
        $defaultJahr = "o.J.";  
        $defaultFamilie = "-1";

        $Verlag = "";
        if (count( $arrayVerlagDI) > 0) {
                foreach ( $arrayVerlagDI as $VerlagDI) {
                        $Verlag .=$VerlagDI->getString().";";
                }
        }

        if (count ($arrayJahrDI) > 0) {
                $Jahr = $arrayJahrDI[0]->getNumber();
                if ($Jahr < 0) $Jahr = $defaultJahr;
        } else {
                $Jahr = $defaultJahr;
        }
         
        if (count( $arrayFamilieDI) > 0) {
                $Familie = $arrayFamilieDI[0]->getString();
        } else {
                $Familie = $defaultFamilie;
        }
        $Familie = strtok(  $Familie, " ");

        $fileNamePart = substr( $fileName, 17, strpos($fileName, ".", 16)-17);          //DAC-nummer + Leerzeichen = 17 Zeichen. fileNamePart ist jetzt alles zwischen ID und Dateiendung
        if (substr_compare( $fileNamePart, "Nachweis", 0, min( 8, strlen($fileNamePart))) == 0) $fileNamePart = "Nachweis";
        if (substr_compare( $fileNamePart, "Abbildungen", 0, min( 8, strlen($fileNamePart))) == 0) $fileNamePart = "Abbildungen";
        $TypAbb = "";
        switch ($fileNamePart) {
                case 'Einband1':
                case 'Einband2':
                        $TypAbb = "AbbEinband";
                        break;
                case 'Haupttitel':
                case 'Inhalt':
                        $TypAbb = "AbbTitelInhalt";
                        break;
                case 'Kartenmuster':
                case 'Kartendetail':
                        $TypAbb = "AbbKartenBeispiel";
                        break;
                case 'Nachweis':
                        $TypAbb = "AbbNachweis";
                        break;
                case 'Abbildungen':
                        $TypAbb = "AbbDetail";
                        break;
                default:
                        $TypAbb = 'AbbSonstige';
        }

        $newFileContent = "{{KatAbb \n";
        $newFileContent .= "|Verlag=".$Verlag."\n";
        $newFileContent .= "|Erscheinungsjahr=".$Jahr."\n";
        $newFileContent .= "|Familie=".$Familie."\n";
        $newFileContent .= "|TypAbb=".$TypAbb."\n";
        $newFileContent .= "}}";

        return $newFileContent;
}



global $smwgEnableUpdateJobs;
global $wgServer;
$user = 'AtlasSysop';   //put any sysop user name here
$wgUser = User::newFromName( $user );
//To be on the safe side, give the sysop group all necessary rights:
$wgGroupPermissions['sysop']['suppressredirect'] = true;
$wgGroupPermissions['sysop']['move'] = true;
$wgGroupPermissions['sysop']['edit'] = true;
$wgGroupPermissions['sysop']['move-target'] = true;

$wgTitle = Title::newFromText( 'RunJobs.php' );

$smwgEnableUpdateJobs = false; // do not fork additional update jobs while running this script
$wgShowExceptionDetails = true;

$smwStore=smwfGetStore();
$linkCache = &LinkCache::singleton();

//------------------Debug settings---------------------
$debugInfo = false;
$debugError = true;
$showProgress = true;
//------------------Variables section -----------------
$fileIterationCount = 1;
$category = 'Atlas';	  						//set namespaces to search --> FILE == 6
$propertyNameUID = 'UID';						//name of property holding UID of page
$propertyUID = SMWDIProperty::newFromUserLabel( $propertyNameUID); 	//property object for name
$propertyNameVerlag = 'Verlag';
$propertyVerlag = SMWDIProperty::newFromUserLabel( $propertyNameVerlag);
$propertyNameJahr = 'Erscheinungsjahr';
$propertyJahr = SMWDIProperty::newFromUserLabel( $propertyNameJahr); 
$defaultJahr = "o.J.";							//Default, falls Jahr nicht gesetzt oder < 0
$propertyNameFamilie = 'Familie';
$propertyFamilie = SMWDIProperty::newFromUserLabel( $propertyNameFamilie);
$defaultFamilie = "-1";							//Default, falls Familie nicht gesetzt


//------------------Main code--------------------------
//search for all pages in Namespace NS_MAIN and category Atlas with recent changes 

$timestamp = date("YmdHis", strtotime('-2 year'));
$pageArray = doSearchQueryRecentChanges( array(NS_MAIN), $category, null, $timestamp );

print "Anzahl zu prüfender Änderungen: ".$pageArray->numRows()."\n";

//Für Jede gefundene Seite Werte prüfen
foreach ( $pageArray as $page) {
	// print "." every 10 and number every 100 iterations...
	if ($showProgress && ($fileIterationCount % 10 == 0)) {
		if ($fileIterationCount % 100 == 0) {
			print $fileIterationCount;
			if ($fileIterationCount % 1000 == 0) print "\n";
		} else {
		print ".";
		}
	}

	if ($debugInfo) print "Bearbeite Seite ".$page->page_title."\n";

	$pageDI = SMWDIWikiPage::newFromTitle( Title::makeTitleSafe( $page->page_namespace, $page->page_title));

	$UIDDIarray = $smwStore->getPropertyValues ( $pageDI, $propertyUID);
	if (count( $UIDDIarray) > 0) {				//die gefundene Seite hat einen Wert für UID gesetzt...
		$UID = $UIDDIarray[0]->getString();
		$VerlagDIarray = $smwStore->getPropertyValues( $pageDI, $propertyVerlag);
		$JahrDIarray = $smwStore->getPropertyValues( $pageDI, $propertyJahr);
	        $FamilieDIarray = $smwStore->getPropertyValues( $pageDI, $propertyFamilie);

		$fileArray = doSearchQuery( array(NS_FILE), null, $UID);
		foreach ($fileArray as $file) {
			if ($debugInfo) print "Bearbeite Datei: ".$file->page_title."\n";

			$fileArticle = new Article( Title::makeTitleSafe( $file->page_namespace, $file->page_title) );
                	if ( !$fileArticle ) {
                        	if ($debugError || $debugInfo) print "FEHLER: Dateiseite ".$file->page_title." nicht gefunden.\n";
	                } else {
				$newFileContent = buildFileContent( $VerlagDIarray, $JahrDIarray, $FamilieDIarray, $file->page_title);
        	                $oldFileContent = $fileArticle->getContent();
                	        if ($debugInfo) print "------- Alter Seiteninhalt:\n".$oldFileContent."\n";
        	                if ($debugInfo) print "------- Neuer Seiteninhalt:\n".$newFileContent."\n";
                	        $edit_summary = 'SMW_categorizeChangedImages.php: Kategorien aktualisisert';
                        	$flags = EDIT_MINOR;
	                        if ( $wgUser->isAllowed( 'bot' ) )
        	                        $flags |= EDIT_FORCE_BOT;

                	        //next line actually edits page...
                        	if (strcmp( $newFileContent, $oldFileContent) != 0) {      //nur Seite editieren, wenn sich wirklich was geändert hat!
	                                if ($debugInfo) print "Seite wird aktualisiert.\n";
        	                        //$fileArticle->doEdit( $newFileContent, $edit_summary, $flags );
                	        } else {
                        	        if ($debugInfo) print "Keine Veränderungen festgestellt. Seite wird nicht aktualisiert.\n";
	                        }
        	        } //if ( !$article )
		} //foreach ($fileArray
	} else { 
		if ($debugError || $debugInfo)	print "FEHLER: Der Wert für UID ist nicht gesetzt. Seite: ".$page->page_title."\n";
	} // if (count( $UIDDIarray) > 0) 
	
	$fileIterationCount += 1;
	if ($debugInfo) print "\n";
}

$linkCache->clear(); // avoid memory leaks


