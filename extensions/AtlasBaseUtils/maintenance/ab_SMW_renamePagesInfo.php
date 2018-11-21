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



function getAllNamespaces() {
	$all_namespaces = SearchEngine::searchableNamespaces();
	$selected_namespaces = array();
	foreach ( $all_namespaces as $ns => $name ) {
		$selected_namespaces[] = $ns;
	}
	return $selected_namespaces;
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

$namespaces = getAllNamespaces();

//property names used to select pages and move target for this script
$moveTo_PropertyName = 'Seite verschieben nach';   //this property sets the new page name (string)
$moveTrigger_PropertyName = 'Seite verschieben';	//this property indicates that the page should be moved (true/false)

//property objects created from above properties
$moveTo_Property = SMWDIProperty::newFromUserLabel( $moveTo_PropertyName);   //SMWPropertyValue::makeProperty( $moveTo_PropertyName);
$moveTrigger_Property = SMWDIProperty::newFromUserLabel( $moveTo_PropertyName);	//SMWPropertyValue::makeProperty( $moveTrigger_PropertyName);

$numMoves = 0;
$numReplaces = 0;
$smwStore=smwfGetStore();
$linkCache = &LinkCache::singleton();

//get an array of pages which have the moveTrigger property set:
$movePages_Array= $smwStore->getAllPropertySubjects( $moveTrigger_Property);

//for each of these pages do: 1. move the page 2. replace all references to it in other pages
foreach ( $movePages_Array as $movePage) {
     	$oldTitle = $movePage->getTitle();
	$oldText = $oldTitle->getText();
        print "Found page to move: ".$oldText."\n";

     	//try to retrieve values for moveTo property for the current page
	$newTitle_Array = $smwStore->getPropertyValues( $movePage, $moveTo_Property);
     	if ($newTitle_Array) {
          	//If more than 1 value is set for the moveTo property on a given page, use only the first one
		$newText = $newTitle_Array[0]->getString();
		print "Neuer Titel: ".$newText."\n";

		$newTitle = Title::makeTitleSafe( $oldTitle->getNamespace(), $newText);
               	print "...Value for new title found. Validating...\n";

		//check if page can be moved at all. If not, do not handle this page!
		$err = $oldTitle->isValidMoveOperation( $newTitle );
                if ( $oldTitle->userCan( 'move', true ) && !is_array( $err ) ) {
			//seems valid page title, go on...
			print "...Validated new title:  ".$newText."\n";

			//at this point oldtext and newtext hold the replacement values.
			//now search for pages which have oldtext in content
			$replace_Array = ReplaceTextSearch::doSearchQuery( $oldText, $namespaces, null, null );
			if ( $replace_Array->numRows() > 0) {
				foreach ( $replace_Array as $replaceTarget) {
					$articleText = $replaceTarget->page_title;
					print "......Found reference in page: ".$replaceTarget->page_title."\n";

					$wikiPage = WikiPage::factory( Title::makeTitleSafe( $replaceTarget->page_namespace, $articleText) );
					if ( !$wikiPage ) {
						print "......ERROR: WikiPage could not be found.\n";
					} else {
						//everything seems ok so far...
						$wpRevision = $wikiPage->getRevision();
						$wpContent = $wpRevision->getContent( Revision::RAW );
						$wpText = ContentHandler::getContentText( $wpContent );
						$num_matches;
						$new_wpText = str_replace( $oldText, $newText, $wpText, $num_matches );
						if ( $num_matches > 0 ) {
							//if there is something to do, edit page with new content
							$edit_summary = 'SMW_renamePages.php: updated references.';
			                                $flags = EDIT_MINOR;
                        			        if ( $wgUser->isAllowed( 'bot' ) )
			                                        $flags |= EDIT_FORCE_BOT;
                        			        //next 2 lines actually edits page...
							$new_wpContent = new WikitextContent( $new_wpText );
							//$wikiPage->doEditContent( $new_wpContent, $edit_summary, $flags );
							print "......".$num_matches." references updated.\n";
							$numReplaces = $numReplaces + $num_matches;
						} else {
                                                	print "......This should not happen (replacement not found...)\n";
                                        	}
					} //if ( !$article )
                                 } //foreach(...
			} else {
                        	print "...No references to this page found.\n";
                	} //if ( $replace_Array->numRows() > 0)

			//now finaly move the page...
			$reason = 'SMW_renamePages.php: moved due to changed data.';
			$create_redirect = false;
			//next line actually moves page...
			//$oldTitle->moveTo( $newTitle, true, $reason, $create_redirect );
			print "...Page moved.\n";

			//$smwStore->deleteSubject($oldTitle);

			//update properties, so that this page is not touched on next run...
			//$updatejob = new SMWUpdateJob($newTitle);
                        //$updatejob->run();
			print "...Semantic data refreshed.\n\n";
			$numMoves = $numMoves+1;
                } else {
                	print "...WARNING: New title is no valid wiki page title. Page not moved. (";
			foreach ( $err as $errItem){
				foreach ($errItem as $errItemText) {
				print $errItemText." : ";
				}
			}
			print ")\n";
			//update properties to fix unsaved changes in semantic data
                        $updatejob = new SMWUpdateJob($oldTitle);
                        $updatejob->run();
                        print "...Semantic data refreshed.\n\n";
        	}
     	} else {
		print "...WARNING: Property for target page title not set. Page not moved.\n";
	}
}

echo "SUMMARY:\n   Page Moves: ".$numMoves."\n   References fixed: ".$numReplaces."\n";



$linkCache->clear(); // avoid memory leaks
