<?php
/**
 * Lets the user import a CSV file to turn into wiki pages
 *
 * @author Yaron Koren
 */

class DTImportCSV extends SpecialPage {

	/**
	 * Constructor
	 */
	public function __construct( $name='ImportCSV' ) {
		parent::__construct( $name );
	}

	public function doesWrites() {
		return true;
	}

	function execute( $query ) {
		$this->setHeaders();

		if ( ! $this->getUser()->isAllowed( 'datatransferimport' ) ) {
			throw new PermissionsError( 'datatransferimport' );
		}

		if ( $this->getRequest()->getCheck( 'import_file' ) ) {
			$text = $this->importFromUploadAndModifyPages();
		} else {
			$text = $this->printForm();
		}

		$this->getOutput()->addModuleStyles( 'ext.datatransfer');
		$this->getOutput()->addHTML( $text );
	}

	protected function importFromUploadAndModifyPages () {

		$text = DTUtils::printImportingMessage();
		$uploadResult = ImportStreamSource::newFromUpload( "file_name" );

		if ( !$uploadResult->isOK() ) {
			$uploadError = $this->getOutput()->parse( $uploadResult->getWikiText() );
			$text .= $uploadError;
			return $text;
		}

		$source = $uploadResult->value;

		$encoding = $this->getRequest()->getVal( 'encoding' );
		$pages = array();

		$error_msg = $this->importFromFile( $source->mHandle, $encoding, $pages );

		if ( ! is_null( $error_msg ) ) {
			$text .= $error_msg;
			return $text;
		}

		$importSummary = $this->getRequest()->getVal( 'import_summary' );
		$forPagesThatExist = $this->getRequest()->getVal( 'pagesThatExist' );

		$text .= self::modifyPages( $pages, $importSummary, $forPagesThatExist );

		return $text;
	}

	protected function printForm() {
		$formText = DTUtils::printFileSelector( $this->getFiletype() );
		$utf8OptionText = "\t" . Xml::element( 'option',
				array(
					'selected' => 'selected',
					'value' => 'utf8'
				), 'UTF-8' ) . "\n";
		$utf16OptionText = "\t" . Xml::element( 'option',
				array(
					'value' => 'utf16'
				), 'UTF-16' ) . "\n";
		$encodingSelectText = Xml::tags( 'select',
				array( 'name' => 'encoding' ),
				"\n" . $utf8OptionText . $utf16OptionText. "\t" ) . "\n\t";
		$formText .= "\t" . Xml::tags( 'p', null, $this->msg( 'dt_import_encodingtype', 'CSV' )->text() . " " . $encodingSelectText ) . "\n";
		$formText .= "\t" . '<hr style="margin: 10px 0 10px 0" />' . "\n";
		$formText .= DTUtils::printExistingPagesHandling();
		$formText .= DTUtils::printImportSummaryInput( $this->getFiletype() );
		$formText .= DTUtils::printSubmitButton();
		$text = "\t" . Xml::tags( 'form',
				array(
					'enctype' => 'multipart/form-data',
					'action' => '',
					'method' => 'post'
				), $formText ) . "\n";
		return $text;
	}

	protected function importFromFile( $csv_file, $encoding, &$pages ) {
		if ( is_null( $csv_file ) ) {
			return wfMessage( 'emptyfile' )->text();
		}

		$table = array();
		if ( $encoding == 'utf16' ) {
			// Change encoding to UTF-8.
			// Starting with PHP 5.3 we could use str_getcsv(),
			// which would save the tempfile hassle.
			$tempfile = tmpfile();
			$csv_string = '';
			while ( !feof( $csv_file ) ) {
				$csv_string .= fgets( $csv_file, 65535 );
 			}
			fwrite( $tempfile, iconv( 'UTF-16', 'UTF-8', $csv_string ) );
			fseek( $tempfile, 0 );
			while ( $line = fgetcsv( $tempfile ) ) {
				array_push( $table, $line );
			}
			fclose( $tempfile );
		} else {
			while ( $line = fgetcsv( $csv_file ) ) {
				// Convert from UTF-8 to ASCII - htmlentities()
				// fails for UTF-8 if there are non-ASCII
				// characters.
				$convertedLine = array();
				foreach ( $line as $value ) {
					$convertedLine[] = mb_convert_encoding( $value, 'UTF-8', 'ASCII' );
				}
				array_push( $table, $convertedLine );
			}
		}
		fclose( $csv_file );

		// Get rid of the "byte order mark", if it's there - this is
		// a three-character string sometimes put at the beginning
		// of files to indicate its encoding.
		// Code copied from:
		// http://www.dotvoid.com/2010/04/detecting-utf-bom-byte-order-mark/
		$byteOrderMark = pack( "CCC", 0xef, 0xbb, 0xbf );
		if ( 0 == strncmp( $table[0][0], $byteOrderMark, 3 ) ) {
			$table[0][0] = substr( $table[0][0], 3 );
			// If there were quotation marks around this value,
			// they didn't get removed, so remove them now.
			$table[0][0] = trim( $table[0][0], '"' );
		}

		return $this->importFromArray( $table, $pages );

	}

	protected function importFromArray( $table, &$pages ) {
		// Check header line to make sure every term is in the
		// correct format.
		$titleLabels = array( wfMessage( 'dt_xml_title' )->inContentLanguage()->text() );
		$freeTextLabels = array( wfMessage( 'dt_xml_freetext' )->inContentLanguage()->text() );
		// Add the English-language values as well, if this isn't an
		// English-language wiki.
		if ( $this->getLanguage()->getCode() !== 'en' ) {
			$titleLabels[] = wfMessage( 'dt_xml_title' )->inLanguage( 'en' )->text();
			$freeTextLabels[] = wfMessage( 'dt_xml_freetext' )->inLanguage( 'en' )->text();
		}
		foreach ( $table[0] as $i => $headerVal ) {
			if ( !in_array( $headerVal, $titleLabels )
			&& !in_array( $headerVal, $freeTextLabels )
			&& $headerVal !== ''
			&& !preg_match( '/^[^\[\]]+\[[^\[\]]+]$/', $headerVal ) ) {
				$errorMsg = wfMessage( 'dt_importcsv_badheader', $i, $headerVal, $titleLabels[0], $freeTextLabels[0] )->text();
				return $errorMsg;
			}
		}
		foreach ( $table as $i => $line ) {
			if ( $i == 0 ) continue;
			$page = new DTPage();
			foreach ( $line as $j => $val ) {
				if ( $table[0][$j] === '' ) {
					continue;
				}
				if ( in_array( $table[0][$j], $titleLabels ) ) {
					$page->setName( $val );
				} elseif ( in_array( $table[0][$j], $freeTextLabels ) ) {
					$page->setFreeText( $val );
				} else {
					list( $templateName, $fieldName ) = explode( '[', str_replace( ']', '', $table[0][$j] ) );
					$page->addTemplateField( $templateName, $fieldName, $val );
				}
			}
			$pages[] = $page;
		}

		return null;
	}

	protected function modifyPages( $pages, $editSummary, $forPagesThatExist ) {
		$text = "";
		$jobs = array();
		$jobParams = array();
		$jobParams['user_id'] = $this->getUser()->getId();
		$jobParams['edit_summary'] = $editSummary;
		$jobParams['for_pages_that_exist'] = $forPagesThatExist;
		foreach ( $pages as $page ) {
			$title = Title::newFromText( $page->getName() );
			if ( is_null( $title ) ) {
				$text .= '<p>' . $this->msg( 'img-auth-badtitle', $page->getName() )->text() . "</p>\n";
				continue;
			}
			$jobParams['text'] = $page->createText();
			$jobs[] = new DTImportJob( $title, $jobParams );
		}
		JobQueueGroup::singleton()->push( $jobs );

		$text .= $this->msg( 'dt_import_success' )->numParams( count( $jobs ) )->params( $this->getFiletype() )->parseAsBlock();

		return $text;
	}

	protected function getFiletype() {
		return wfMessage( 'dt_filetype_csv' )->text();
	}
}
