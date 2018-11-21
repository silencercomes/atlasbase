<?php
/**


**/



require_once ( getenv('MW_INSTALL_PATH') !== false
    ? getenv('MW_INSTALL_PATH')."/maintenance/commandLine.inc"
    : dirname( __FILE__ ) . '/../../../maintenance/commandLine.inc' );


use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\IResultWrapper;

class ReplaceTextSearch {

        /**
         * @param string $search
         * @param array $namespaces
         * @param string $category
         * @param string $prefix
         * @param bool $use_regex
         * @return IResultWrapper Resulting rows
         */
        public static function doSearchQuery(
                $search, $namespaces, $category, $prefix, $use_regex = false
        ) {
                $dbr = wfGetDB( DB_REPLICA );
                $tables = [ 'page', 'revision', 'text' ];
                $vars = [ 'page_id', 'page_namespace', 'page_title', 'old_text' ];
                if ( $use_regex ) {
                        $comparisonCond = self::regexCond( $dbr, 'old_text', $search );
                } else {
                        $any = $dbr->anyString();
                        $comparisonCond = 'old_text ' . $dbr->buildLike( $any, $search, $any );
                }
                $conds = [
                        $comparisonCond,
                        'page_namespace' => $namespaces,
                        'rev_id = page_latest',
                        'rev_text_id = old_id'
                ];

                self::categoryCondition( $category, $tables, $conds );
                self::prefixCondition( $prefix, $conds );
                $options = [
                        'ORDER BY' => 'page_namespace, page_title',
                        // 250 seems like a reasonable limit for one screen.
                        // @TODO - should probably be a setting.
                        'LIMIT' => 250
                ];

                return $dbr->select( $tables, $vars, $conds, __METHOD__, $options );
        }

        /**
         * @param string $category
         * @param array &$tables
         * @param array &$conds
         */
        public static function categoryCondition( $category, &$tables, &$conds ) {
                if ( strval( $category ) !== '' ) {
                        $category = Title::newFromText( $category )->getDbKey();
                        $tables[] = 'categorylinks';
                        $conds[] = 'page_id = cl_from';
                        $conds['cl_to'] = $category;
                }
        }

        /**
         * @param string $prefix
         * @param array &$conds
         */
        public static function prefixCondition( $prefix, &$conds ) {
                if ( strval( $prefix ) === '' ) {
                        return;
                }

                $dbr = wfGetDB( DB_REPLICA );
                $title = Title::newFromText( $prefix );
                if ( !is_null( $title ) ) {
                        $prefix = $title->getDbKey();
                }
                $any = $dbr->anyString();
                $conds[] = 'page_title ' . $dbr->buildLike( $prefix, $any );
        }

        /**
         * @param Database $dbr
         * @param string $column
         * @param string $regex
         * @return string query condition for regex
         */
        public static function regexCond( $dbr, $column, $regex ) {
                if ( $dbr->getType() == 'postgres' ) {
                        $op = '~';
                } else {
                        $op = 'REGEXP';
                }
                return "$column $op " . $dbr->addQuotes( $regex );
        }
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
$debugInfo = true;
$debugError = true;
$showProgress = true;

//------------------Variables section -----------------
$fileIterationCount = 0;
$oldUID = "";
$namespaces = array(NS_MAIN);  						//set namespaces to search --> FILE == 6
$propertyNameUID = 'UID';						//name of property holding UID of page
$propertyUID = SMWDIProperty::newFromUserLabel( $propertyNameUID); 	//property object for name


//------------------Main code--------------------------
//Suche alle Seiten im Namensraum NS_MAIN, deren Titel "DAC-xx.xxxx.xxxx Abbildungen" ist.
$dbr = wfGetDB( DB_SLAVE );
$pattern = array( 'DAC-', $dbr->anyChar(), $dbr->anyChar(), '.', $dbr->anyChar(), $dbr->anyChar(), $dbr->anyChar(), $dbr->anyChar(), '.', $dbr->anyChar(), $dbr->anyChar(), $dbr->anyChar(), $dbr->anyChar(), '_Abbildungen');
$pageArray = ReplaceTextSearch::doSearchQuery( $namespaces, null, $pattern);

if ($showProgress) print "Anzahl zu prüfender Seiten: ".$pageArray->numRows()."\n";

//Für Jede gefundene Seite Werte prüfen
foreach ( $pageArray as $page) {
        $fileIterationCount += 1;
	// print "." every 10 and number every 100 iterations...
	if ($showProgress && ($fileIterationCount % 10 == 0)) {
		if ($fileIterationCount % 100 == 0) {
			print $fileIterationCount;
			if ($fileIterationCount % 1000 == 0) print "\n";
		} else print ".";
	}

	if ($debugInfo) print "Bearbeite Seite ".$page->page_title."\n";

	$wikiPage = WikiPage::factory( Title::makeTitleSafe( $page->page_namespace, $page->page_title) );
        if ( !$wikiPage ) {
                if ($debugError || $debugInfo) print "FEHLER: Dateiseite ".$page->page_title." nicht gefunden.\n";
        } else {
                $wpRevision = $wikiPage->getRevision();
                $wpContent = $wpRevision->getContent( Revision::RAW );
                $wpText = ContentHandler::getContentText( $wpContent );
                $oldPageText = $wikiPage->getContent();
		$newPageText = $oldPageText;
		 //ersetzte Links, die mit einer DAC-Nummer enden durch die Vorlage LinkBack
		$newPageText = preg_replace( '/\[\[.*DAC-\d{2}\.\d{4}\.\d{4}\]\]/', '{{DACAutoLink}}', $newPageText);
		$newPageText = preg_replace( '/<br \/>/', '', $newPageText);		//zusätzliche Zeilenumbrüche <br /> entfernen
		$newPageText = preg_replace( '/<!--[\s\S]*-->/', '', $newPageText);	//HTML Kommentare entfernen

		$matches = array();
		$result = preg_match_all( '/\[\[Image:(.*)\|(.*)\|(.*)\]\]/', $newPageText, $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			if (count($match) != 4) {
				if ($debugError || $debugInfo) print "FEHLER: Dateilink konnte nicht erkannt werden.\n";
			} else {
				$searchStr = $match[0];

				$oldFileTitleText = $match[1];

				if (!preg_match( '/DAC-\d{2}\.\d{4}\.\d{4}/', $oldFileTitle)) {
					//verschiebe Dateiseite ohne redirect

					$replaceStr = "{{DACAbbildung|Size=".$match[2]."|Text=".$match[3]."}}";         //evtl. Prüfen, ob text==''
					$newFileTitleText = $wikiPage->getTitle()->getText()." ".$match[3].".jpg";

					$oldFileTitle = Title::makeTitleSafe( NS_FILE, $oldFileTitleText);
					$newFileTitle = Title::makeTitleSafe( NS_FILE, $newFileTitleText);
                			$err = $oldTitle->isValidMoveOperation( $newTitle );
			                if ( $oldTitle->userCan( 'move', true ) && !is_array( $err ) ) {
						$reason = 'ab_SMW_parseDetailPages.php: Standardisierung der Dateinamen.';
                			        $create_redirect = false;
						if ($debugInfo) print "Verschiebe Seite. ALT:".$oldFileTitle->getFullText()." NEU:".$newFileTitle->getFullText()."\n";
                        			//$oldFileTitle->moveTo( $newFileTitle, true, $reason, $create_redirect);
					}
					$newPageText = str_replace( $searchStr, $replaceStr, $newPageText);
				} else {
					if ($debugInfo) print "Abbildung muss nicht verschoben werden: ".$oldFileTitleText."\n";
					$replaceStr = '<div style="vertical-align:top;border:1px solid gray; padding:2px; margin:5px;height:auto; width:auto; display:inline-block;">';
					$replaceStr .= '  <div style="display:inline-block;padding:5px;">[[Image:'.$oldFileTitleText.'|'.$match[2];
					$replaceStr .= '|frameless|'.$match[3].']] </div>';
					$replaceStr .= '  <div style="text-align:left; font-weight:normal; width:{{{Size|400px}}}; padding:5px">{{{Text|}}}</div>';
					$newPageText = str_replace( $searchStr, $replaceStr, $newPageText);
				}
			}
		}

                if ($debugInfo) print "------- Alter Seiteninhalt:\n".$oldPageText."\n";
                if ($debugInfo) print "------- Neuer Seiteninhalt:\n".$newPageText."\n";
                $edit_summary = 'ab_SMW_parseDetailPages.php: Dateinamen und Vorlagen aktualisiert.';
                $flags = EDIT_MINOR;
                if ( $wgUser->isAllowed( 'bot' ) )
                        $flags |= EDIT_FORCE_BOT;

                //next line actually edits page...
                if (strcmp( $newPageText, $oldPageText) != 0) {   //nur Seite editieren, wenn sich wirklich was geändert hat!
                        if ($debugInfo) print "Seite wird aktualisiert.\n";
                        //$newContent = new WikitextContent( $newPageText );
                        //$wikiPage->doEditContent( $newContent, $edit_summary, $flags );
                } else {
                        if ($debugInfo) print "Keine Veränderungen festgestellt. Seite wird nicht aktualisiert.\n";
                }
        } //if ( !$wikiPage )

	$UID = substr( $page->page_title, 0, 16);

	if ($debugInfo) print "#############################################################\n";
}

print "\n";

$linkCache->clear(); // avoid memory leaks
