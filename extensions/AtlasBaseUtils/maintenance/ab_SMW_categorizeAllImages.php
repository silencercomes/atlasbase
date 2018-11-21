<?php
/**  Automatische Kategorisierung von Seiten im Namensraum Datei, welche zu einem Datensatz gehören
Ablauf:
  * Suche alle Seiten im Namensraum Datei, die mit "DAC-" anfangen
  * Für jede Seite:
    * Ermittle UID aus Titel
    * Suche Datensatz mit passender UID
    * Ermittle Jahr + Familie aus Datensatz
    * Aktualisiere Dateiseite mit neuen Kategorien
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

        $fileNamePart = substr( $fileName, 17, strpos($fileName, ".", 16)-17);    //DAC-nummer + Leerzeichen = 17 Zeichen. fileNamePart ist jetzt alles zwischen ID und Dateiendung
        if (substr_compare( $fileNamePart, "Nachweis", 0, min( 8, strlen($fileNamePart))) == 0) $fileNamePart = "Nachweis";
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
$debugError = false;
$showProgress = true;

//------------------Variables section -----------------
$fileIterationCount = 0;
$oldUID = "";
$namespaces = array(NS_FILE);              //set namespaces to search --> FILE == 6
$propertyNameUID = 'UID';            //name of property holding UID of page
$propertyUID = SMWDIProperty::newFromUserLabel( $propertyNameUID);   //property object for name
$propertyNameVerlag = 'Verlag';
$propertyVerlag = SMWDIProperty::newFromUserLabel( $propertyNameVerlag);
$propertyNameJahr = 'Erscheinungsjahr';
$propertyJahr = SMWDIProperty::newFromUserLabel( $propertyNameJahr);
$propertyNameFamilie = 'Familie';
$propertyFamilie = SMWDIProperty::newFromUserLabel( $propertyNameFamilie);


//------------------Main code--------------------------
//search for all pages in Namespace FILE which start with "DAC-"

$fileArray = ReplaceTextSearch::doSearchQuery( $namespaces, null, "DAC-" );

if ($showProgress) print "Anzahl zu prüfender Bilder: ".$fileArray->numRows()."\n";

//Für Jede gefundene Seite Werte prüfen
foreach ( $fileArray as $filePage) {
        $fileIterationCount += 1;
        // print "." every 10 and number every 100 iterations...
        if ($showProgress && ($fileIterationCount % 10 == 0)) {
                if ($fileIterationCount % 100 == 0) {
                        print $fileIterationCount;
                        if ($fileIterationCount % 1000 == 0) print "\n";
                } else {
                        print ".";
                }
        }

        if ($debugInfo) print "Bearbeite Seite ".$filePage->page_title."\n";

        $UID = substr( $filePage->page_title, 0, 16);

        //prüfe, ob die UID die gleiche ist. Da mehrere Bilder pro Eintrag möglich sind, spart das Arbeit...
        if (strcmp( $UID, $oldUID) != 0) {
                $UIDDV = SMWDataItem::newFromSerialization( SMWDataItem::TYPE_STRING, $UID);
                $UIDpages = $smwStore->getPropertySubjects( $propertyUID, $UIDDV);
        }

        $numUIDpages = count( $UIDpages);
        if ( $numUIDpages > 0) {
                if (($debugError || $debugInfo) && $numUIDpages > 1) {
                        if (strcmp( $UID, $oldUID) != 0) {       // Fehler nur einmal anzeigen...
                                print "FEHLER: Mehrfachverwendung von ID = ".$UID." festgestellt. Verwende Daten des letzten Eintrages.\n";
                        }
                }

                //Ermittle Werte für Jahr und Familie aus Eintrag im Wiki
                foreach ( $UIDpages as $UIDpage) {
                        $arrayVerlagDI = $smwStore->getPropertyValues( $UIDpage, $propertyVerlag);
                        $arrayJahrDI = $smwStore->getPropertyValues( $UIDpage, $propertyJahr);
                        $arrayFamilieDI = $smwStore->getPropertyValues( $UIDpage, $propertyFamilie);
                        $newFileContent = buildFileContent( $arrayVerlagDI, $arrayJahrDI, $arrayFamilieDI, $filePage->page_title);
                }

                $fileArticle = new Article( Title::makeTitleSafe( $filePage->page_namespace, $filePage->page_title) );
                if ( !$fileArticle ) {
                        if ($debugError || $debugInfo) print "FEHLER: Dateiseite ".$filePage->page_title." nicht gefunden.\n";
                } else {
                        $oldFileContent = $fileArticle->getContent();
                        if ($debugInfo) print "------- Alter Seiteninhalt:\n".$oldFileContent."\n";
                        if ($debugInfo) print "------- Neuer Seiteninhalt:\n".$newFileContent."\n";
                        $edit_summary = 'SMW_categorizeImages.php: Kategorien aktualisiert.';
                        $flags = EDIT_MINOR;
                        if ( $wgUser->isAllowed( 'bot' ) )  $flags |= EDIT_FORCE_BOT;

                        //next line actually edits page...
                        if (strcmp( $newFileContent, $oldFileContent) != 0) {  //nur Seite editieren, wenn sich wirklich was geändert hat!
                                if ($debugInfo) print "Seite wird aktualisiert.\n";
                                //$fileArticle->doEdit( $newFileContent, $edit_summary, $flags );
                        } else {
                                if ($debugInfo) print "Keine Veränderungen festgestellt. Seite wird nicht aktualisiert.\n";
                        }
                } //if ( !$article )
        } else {
                if ($debugError || $debugInfo) {
                        if (strcmp( $UID, $oldUID) != 0) {                      // Fehler nur einmal anzeigen...
                                print "FEHLER: Kein Datensatz mit ID = ".$UID." gefunden.\n";
                        }
                }
        } //if ( $numUIDpages > 0)

        $oldUID = $UID;
        if ($debugInfo) print "\n";
}

print "\n";

$linkCache->clear(); // avoid memory leaks
